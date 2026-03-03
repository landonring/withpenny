<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessReceiptScanJob;
use App\Models\Receipt;
use App\Models\Transaction;
use App\Services\Ingestion\TransactionNormalizationService;
use App\Services\PlanUsageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReceiptController extends Controller
{
    public function __construct(
        private readonly PlanUsageService $planUsage,
        private readonly TransactionNormalizationService $normalizer,
    )
    {
    }

    public function scan(Request $request)
    {
        $limit = $this->planUsage->limitState($request->user(), 'receipt_scans');
        if (! $limit['allowed']) {
            return response()->json(
                $this->planUsage->limitResponse($request->user(), 'receipt_scans', 'receipt scanning'),
                429
            );
        }

        $validated = $request->validate([
            'image' => ['required', 'file', 'max:16384'],
        ]);

        $file = $validated['image'];
        $this->ensureReceiptFile($file, 'image');

        [$receipt] = $this->queueReceiptProcessing($request, [$file]);

        analytics_track('receipt_uploaded', ['mode' => 'single']);

        $status = $receipt->processing_status === 'completed' ? 201 : 202;

        return response()->json(
            $this->serializeReceiptPayload($receipt, $this->planUsage->isStarter($request->user())),
            $status
        );
    }

    public function scanImages(Request $request)
    {
        $limit = $this->planUsage->limitState($request->user(), 'receipt_scans');
        if (! $limit['allowed']) {
            return response()->json(
                $this->planUsage->limitResponse($request->user(), 'receipt_scans', 'receipt scanning'),
                429
            );
        }

        $validated = $request->validate([
            'images' => ['required', 'array', 'min:1', 'max:7'],
            'images.*' => ['required', 'file', 'max:16384'],
        ]);

        foreach ($validated['images'] as $image) {
            $this->ensureReceiptFile($image, 'image');
        }

        [$receipt] = $this->queueReceiptProcessing($request, $validated['images']);

        analytics_track('receipt_uploaded', ['mode' => 'multi', 'images' => count($validated['images'])]);

        $status = $receipt->processing_status === 'completed' ? 201 : 202;

        return response()->json(
            $this->serializeReceiptPayload($receipt, $this->planUsage->isStarter($request->user())),
            $status
        );
    }

    public function show(Request $request, Receipt $receipt)
    {
        $this->authorizeReceipt($request, $receipt);

        return response()->json(
            $this->serializeReceiptPayload($receipt, $this->planUsage->isStarter($request->user()))
        );
    }

    public function confirm(Request $request, Receipt $receipt)
    {
        $this->authorizeReceipt($request, $receipt);

        if (in_array((string) $receipt->processing_status, ['queued', 'processing'], true)) {
            return response()->json([
                'message' => 'This receipt is still processing. Please wait before saving.',
            ], 409);
        }

        $validated = $request->validate([
            'amount' => ['required_without:items', 'numeric', 'min:0.01'],
            'items' => ['required_without:amount', 'array', 'min:1'],
            'items.*.amount' => ['required', 'numeric', 'min:0.01'],
            'items.*.note' => ['nullable', 'string', 'max:255'],
            'items.*.description' => ['nullable', 'string', 'max:255'],
            'items.*.category' => ['nullable', 'string', 'max:100'],
            'category' => ['required_without:items', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:255'],
            'transaction_date' => ['nullable', 'date'],
        ]);

        $transactionDate = $validated['transaction_date'] ?? now()->toDateString();
        $confidence = $receipt->confidence_score;
        $flagged = (bool) $receipt->flagged;

        if (! empty($validated['items'])) {
            $transactions = [];
            foreach ($validated['items'] as $item) {
                $note = $item['note'] ?? $item['description'] ?? null;
                $category = $item['category'] ?? $validated['category'] ?? 'Misc';

                $normalized = [
                    'source' => 'receipt_scan',
                    'date' => $transactionDate,
                    'description' => (string) ($note ?? 'Receipt item'),
                    'amount' => abs((float) $item['amount']),
                    'category' => $category,
                    'confidence_score' => $confidence,
                    'flagged' => $flagged,
                    'type' => 'spending',
                ];

                $transactions[] = Transaction::query()->create(
                    $this->normalizer->toTransactionInsert($normalized, $request->user()->id, $receipt->id)
                );
            }

            $receipt->update(['reviewed_at' => now()]);

            return response()->json([
                'transactions' => $transactions,
            ], 201);
        }

        $normalized = [
            'source' => 'receipt_scan',
            'date' => $transactionDate,
            'description' => (string) ($validated['note'] ?? ($receipt->extracted_data['suggestions']['merchant'] ?? 'Receipt purchase')),
            'amount' => abs((float) $validated['amount']),
            'category' => $validated['category'] ?? 'Misc',
            'confidence_score' => $confidence,
            'flagged' => $flagged,
            'type' => 'spending',
        ];

        $transaction = Transaction::query()->create(
            $this->normalizer->toTransactionInsert($normalized, $request->user()->id, $receipt->id)
        );

        $receipt->update(['reviewed_at' => now()]);

        return response()->json([
            'transaction' => $transaction,
        ], 201);
    }

    public function destroy(Request $request, Receipt $receipt)
    {
        $this->authorizeReceipt($request, $receipt);

        Storage::disk('public')->delete($receipt->image_path);
        $receipt->delete();

        return response()->json(['status' => 'deleted']);
    }

    private function authorizeReceipt(Request $request, Receipt $receipt): void
    {
        if ($receipt->user_id !== $request->user()->id) {
            abort(403);
        }
    }

    private function ensureReceiptFile($file, string $field = 'image'): void
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: '');
        $mime = strtolower((string) $file->getMimeType());

        $allowedExtensions = ['jpg', 'jpeg', 'png', 'heic', 'heif'];
        $allowedMimes = [
            'image/jpeg',
            'image/png',
            'image/heic',
            'image/heif',
            'image/heic-sequence',
            'image/heif-sequence',
        ];

        if (! in_array($extension, $allowedExtensions, true) && ! in_array($mime, $allowedMimes, true)) {
            throw ValidationException::withMessages([
                $field => ['Please choose JPG, PNG, or HEIC files.'],
            ]);
        }
    }

    /**
     * @param array<int,mixed> $files
     * @return array{0:Receipt,1:array<int,string>}
     */
    private function queueReceiptProcessing(Request $request, array $files): array
    {
        $storedPaths = [];

        foreach ($files as $file) {
            $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
            $filename = Str::uuid().'.'.$extension;
            $stored = Storage::disk('public')->putFileAs("receipts/{$request->user()->id}", $file, $filename);
            if (! is_string($stored) || $stored === '') {
                throw ValidationException::withMessages([
                    'images' => ['Receipt upload failed. Please try again.'],
                ]);
            }
            $storedPaths[] = $stored;
        }

        $receipt = Receipt::query()->create([
            'user_id' => $request->user()->id,
            'image_path' => $storedPaths[0],
            'scanned_at' => now(),
            'processing_status' => 'queued',
            'processing_started_at' => now(),
            'extracted_data' => [
                'pending_images' => $storedPaths,
                'warnings' => [],
                'suggestions' => [],
                'line_items' => [],
            ],
            'flagged' => false,
        ]);

        ProcessReceiptScanJob::dispatch($receipt->id, $storedPaths);

        return [$receipt->refresh(), $storedPaths];
    }

    /**
     * @return array<string,mixed>
     */
    private function serializeReceiptPayload(Receipt $receipt, bool $isStarter): array
    {
        $data = is_array($receipt->extracted_data) ? $receipt->extracted_data : [];
        $suggestions = is_array($data['suggestions'] ?? null) ? $data['suggestions'] : [];
        $lineItems = is_array($data['line_items'] ?? null) ? $data['line_items'] : [];
        $warnings = is_array($data['warnings'] ?? null) ? $data['warnings'] : [];

        $rawText = $receipt->receiptText?->raw_text ?? '';

        if ($isStarter) {
            $lineItems = [];
        }

        return [
            'receipt' => [
                'id' => $receipt->id,
                'user_id' => $receipt->user_id,
                'image_path' => $receipt->image_path,
                'scanned_at' => $receipt->scanned_at,
                'processing_status' => $receipt->processing_status,
                'processing_error' => $receipt->processing_error,
                'confidence_score' => $receipt->confidence_score,
                'flagged' => (bool) $receipt->flagged,
                'category_suggestion' => $receipt->category_suggestion,
                'category_confidence' => $receipt->category_confidence,
                'reviewed_at' => $receipt->reviewed_at,
            ],
            'image_url' => Storage::disk('public')->url($receipt->image_path),
            'raw_text' => $rawText,
            'suggestions' => $suggestions,
            'line_items' => $lineItems,
            'mode' => $isStarter ? 'basic' : 'full',
            'processing_status' => $receipt->processing_status,
            'processing_error' => $receipt->processing_error,
            'confidence_score' => $receipt->confidence_score,
            'flagged' => (bool) $receipt->flagged,
            'warnings' => $warnings,
        ];
    }
}
