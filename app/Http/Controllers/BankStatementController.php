<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessBankStatementImportJob;
use App\Models\BankStatementImport;
use App\Models\Transaction;
use App\Services\DemoDataService;
use App\Services\Ingestion\StatementIngestionService;
use App\Services\Ingestion\TransactionNormalizationService;
use App\Services\OnboardingService;
use App\Services\PlanUsageService;
use App\Services\Statements\StatementParser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class BankStatementController extends Controller
{
    public function __construct(
        private readonly PlanUsageService $planUsage,
        private readonly DemoDataService $demoData,
        private readonly OnboardingService $onboarding,
        private readonly StatementIngestionService $ingestion,
        private readonly TransactionNormalizationService $normalizer,
    )
    {
    }

    public function upload(Request $request)
    {
        if ($request->user()->onboarding_mode) {
            $validated = $request->validate([
                'file' => ['required', 'file', 'max:25600'],
            ], [
                'file.required' => 'Please upload a statement file.',
            ]);

            $this->assertStatementUpload($validated['file'], 'file');

            $import = $this->createDemoImport($request);
            $this->onboarding->rememberImportId($request, $import->id);
            $this->onboarding->setStep($request->user(), 2, $request);

            return response()->json([
                'import' => $this->serializeImport($import),
            ], 201);
        }

        $limit = $this->planUsage->limitState($request->user(), 'statement_uploads');
        if (! $limit['allowed']) {
            return response()->json(
                $this->planUsage->limitResponse($request->user(), 'statement_uploads', 'bank statement uploads'),
                429
            );
        }

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:25600'],
        ], [
            'file.required' => 'Please upload a statement file.',
        ]);

        $this->assertStatementUpload($validated['file'], 'file');

        $import = $this->queueImport($request, [$validated['file']]);
        $status = $import->processing_status === 'completed' ? 201 : 202;

        return response()->json([
            'import' => $this->serializeImport($import),
        ], $status);
    }

    public function scanImages(Request $request)
    {
        if ($request->user()->onboarding_mode) {
            $request->validate([
                'files' => ['nullable', 'array', 'max:12'],
                'files.*' => ['required', 'file', 'max:25600'],
                'images' => ['nullable', 'array', 'max:12'],
                'images.*' => ['required', 'file', 'max:25600'],
            ]);

            $files = $request->file('files', []);
            $images = $request->file('images', []);
            $documents = array_merge($files, $images);
            foreach ($files as $index => $file) {
                $this->assertStatementUpload($file, "files.$index");
            }
            foreach ($images as $index => $file) {
                $this->assertStatementUpload($file, "images.$index");
            }

            $import = $this->createDemoImport($request);
            $this->onboarding->rememberImportId($request, $import->id);
            $this->onboarding->setStep($request->user(), 2, $request);

            return response()->json([
                'import' => $this->serializeImport($import),
            ], 201);
        }

        $limit = $this->planUsage->limitState($request->user(), 'statement_uploads');
        if (! $limit['allowed']) {
            return response()->json(
                $this->planUsage->limitResponse($request->user(), 'statement_uploads', 'bank statement uploads'),
                429
            );
        }

        $request->validate([
            'files' => ['nullable', 'array', 'max:12'],
            'files.*' => ['required', 'file', 'max:25600'],
            'images' => ['nullable', 'array', 'max:12'],
            'images.*' => ['required', 'file', 'max:25600'],
        ], [
            'files.required' => 'Please upload at least one statement file.',
        ]);

        $files = $request->file('files', []);
        $images = $request->file('images', []);
        $documents = array_merge($files, $images);

        if (empty($documents)) {
            throw ValidationException::withMessages([
                'files' => ['Please upload at least one statement file.'],
            ]);
        }

        foreach ($files as $index => $file) {
            $this->assertStatementUpload($file, "files.$index");
        }
        foreach ($images as $index => $file) {
            $this->assertStatementUpload($file, "images.$index");
        }

        $import = $this->queueImport($request, $documents);
        $status = $import->processing_status === 'completed' ? 201 : 202;

        return response()->json([
            'import' => $this->serializeImport($import),
        ], $status);
    }

    public function show(Request $request, BankStatementImport $import)
    {
        $this->authorizeImport($request, $import);
        $import = $this->processInlineIfQueueWorkerUnavailable($import);

        return response()->json([
            'import' => $this->serializeImport($import),
        ]);
    }

    public function confirm(Request $request, BankStatementImport $import)
    {
        $this->authorizeImport($request, $import);

        if (in_array((string) $import->processing_status, ['queued', 'processing'], true)) {
            return response()->json([
                'message' => 'This statement is still processing. Please wait before confirming.',
            ], 409);
        }

        if ((string) $import->processing_status === 'failed') {
            return response()->json([
                'message' => 'Statement parsing could not be automated. Please review manually or upload a clearer file.',
            ], 422);
        }

        if ($request->user()->onboarding_mode) {
            $validated = $request->validate([
                'transactions' => ['required', 'array'],
                'transactions.*.date' => ['required', 'date'],
                'transactions.*.description' => ['required', 'string', 'max:255'],
                'transactions.*.amount' => ['required', 'numeric', 'min:0.01'],
                'transactions.*.type' => ['required', 'in:income,spending'],
                'transactions.*.category' => ['nullable', 'string', 'max:50'],
                'transactions.*.include' => ['required', 'boolean'],
            ]);

            $included = array_values(array_filter($validated['transactions'], fn ($item) => ! empty($item['include'])));
            $this->onboarding->storeConfirmedTransactions($request, $included);
            $this->onboarding->forgetImportId($request);
            $import->delete();
            $status = $this->onboarding->setStep($request->user(), 3, $request);

            return response()->json([
                'status' => 'sandbox_confirmed',
                'count' => count($included),
                'onboarding' => $status,
            ]);
        }

        $limit = $this->planUsage->limitState($request->user(), 'statement_uploads');
        if (! $limit['allowed']) {
            return response()->json(
                $this->planUsage->limitResponse($request->user(), 'statement_uploads', 'bank statement uploads'),
                429
            );
        }

        $validated = $request->validate([
            'transactions' => ['required', 'array'],
            'transactions.*.date' => ['required', 'date'],
            'transactions.*.description' => ['required', 'string', 'max:255'],
            'transactions.*.amount' => ['required', 'numeric', 'min:0.01'],
            'transactions.*.type' => ['required', 'in:income,spending'],
            'transactions.*.category' => ['nullable', 'string', 'max:50'],
            'transactions.*.include' => ['required', 'boolean'],
            'transactions.*.confidence_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'transactions.*.flagged' => ['nullable', 'boolean'],
        ]);

        return DB::transaction(function () use ($request, $import, $validated) {
            $lockedImport = BankStatementImport::query()
                ->where('id', $import->id)
                ->where('user_id', $request->user()->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedImport) {
                return response()->json([
                    'status' => 'already_imported',
                ], 409);
            }

            $toCreate = [];
            $dates = array_map(fn ($item) => $item['date'] ?? null, $validated['transactions']);
            $dates = array_values(array_filter($dates));
            $existingCounts = [];
            if (! empty($dates)) {
                $minDate = min($dates);
                $maxDate = max($dates);
                $existing = Transaction::query()
                    ->where('user_id', $request->user()->id)
                    ->whereIn('source', ['statement', 'bank_upload'])
                    ->whereBetween('transaction_date', [$minDate, $maxDate])
                    ->get(['transaction_date', 'amount', 'note', 'type']);

                foreach ($existing as $row) {
                    $amountKey = number_format((float) $row->amount, 2, '.', '');
                    $noteKey = StatementParser::sanitizeDescription($row->note ?? '');
                    $key = strtolower($row->transaction_date.'|'.$amountKey.'|'.$row->type.'|'.$noteKey);
                    $existingCounts[$key] = ($existingCounts[$key] ?? 0) + 1;
                }
            }

            foreach ($validated['transactions'] as $item) {
                if (! $item['include']) {
                    continue;
                }

                $type = $item['type'] === 'income' ? 'income' : 'spending';
                $category = $type === 'income' ? 'Income' : $this->sanitizeStatementCategory($item['category'] ?? null);
                $date = $item['date'];
                $amount = number_format((float) $item['amount'], 2, '.', '');
                $description = StatementParser::sanitizeDescription($item['description']);
                $dedupeKey = strtolower($date.'|'.$amount.'|'.$type.'|'.$description);
                if (($existingCounts[$dedupeKey] ?? 0) > 0) {
                    $existingCounts[$dedupeKey] -= 1;
                    continue;
                }

                $normalized = [
                    'source' => 'bank_upload',
                    'date' => $date,
                    'description' => $description,
                    'amount' => (float) $item['amount'],
                    'category' => $category,
                    'confidence_score' => $item['confidence_score'] ?? $lockedImport->confidence_score,
                    'flagged' => (bool) ($item['flagged'] ?? false),
                    'type' => $type,
                ];

                $toCreate[] = $this->normalizer->toTransactionInsert($normalized, $request->user()->id);
            }

            if (! empty($toCreate)) {
                Transaction::query()->insert($toCreate);
                analytics_track('statement_uploaded', ['mode' => 'confirm']);
            }

            $lockedImport->delete();

            return response()->json([
                'status' => 'imported',
                'count' => count($toCreate),
            ]);
        });
    }

    public function destroy(Request $request, BankStatementImport $import)
    {
        $this->authorizeImport($request, $import);

        $this->cleanupPendingFiles($import);
        $import->delete();

        if ($request->user()->onboarding_mode) {
            $this->onboarding->forgetImportId($request);
            $this->onboarding->setStep($request->user(), 1, $request);
        }

        return response()->json(['status' => 'discarded']);
    }

    private function authorizeImport(Request $request, BankStatementImport $import): void
    {
        if ($import->user_id !== $request->user()->id) {
            abort(403);
        }
    }

    private function sanitizeStatementCategory(?string $category): string
    {
        $allowed = [
            'Groceries',
            'Dining',
            'Transportation',
            'Housing',
            'School',
            'Shopping',
            'Subscriptions',
            'Misc',
            'Income',
        ];

        if ($category && in_array($category, $allowed, true)) {
            return $category;
        }

        return 'Misc';
    }

    private function assertStatementUpload($file, string $field): void
    {
        $name = strtolower((string) $file->getClientOriginalName());
        $clientMime = strtolower((string) $file->getClientMimeType());
        $detectedMime = strtolower((string) $file->getMimeType());

        $isSupported = $this->ingestion->detectFormat($name, $clientMime) !== null
            || $this->ingestion->detectFormat($name, $detectedMime) !== null;

        if ($isSupported) {
            return;
        }

        throw ValidationException::withMessages([
            $field => ['Supported formats are PDF, CSV, OFX, and QFX.'],
        ]);
    }

    /**
     * @param array<int, mixed> $files
     */
    private function queueImport(Request $request, array $files): BankStatementImport
    {
        $pendingFiles = [];
        foreach ($files as $file) {
            $storedPath = $file->store('tmp/statement-ingest');
            $pendingFiles[] = [
                'name' => (string) $file->getClientOriginalName(),
                'mime' => (string) $file->getClientMimeType(),
                'storage_path' => $storedPath,
            ];
        }

        $firstName = (string) ($pendingFiles[0]['name'] ?? 'statement');
        $detectedFormats = [];
        foreach ($pendingFiles as $entry) {
            $detected = $this->ingestion->detectFormat($entry['name'], $entry['mime'] ?? null);
            if ($detected !== null) {
                $detectedFormats[] = $detected;
            }
        }
        $uniqueFormats = array_values(array_unique($detectedFormats));
        $fileFormat = count($uniqueFormats) === 1 ? $uniqueFormats[0] : 'mixed';

        $import = BankStatementImport::query()->create([
            'user_id' => $request->user()->id,
            'transactions' => [],
            'meta' => [
                'queued_files' => array_map(fn ($item) => $item['storage_path'], $pendingFiles),
                'queued_file_entries' => $pendingFiles,
                'queued_at' => now()->toIso8601String(),
                'review' => [
                    'recommended' => false,
                    'message' => null,
                ],
            ],
            'masked_account' => null,
            'source' => 'pending',
            'file_name' => $firstName,
            'file_format' => $fileFormat,
            'processing_status' => 'queued',
            'processing_started_at' => now(),
            'extraction_confidence' => null,
            'balance_mismatch' => false,
            'extraction_method' => null,
            'confidence_score' => null,
            'flagged_rows' => 0,
            'total_rows' => 0,
        ]);

        if ($this->shouldProcessInline()) {
            ProcessBankStatementImportJob::dispatchSync($import->id, $pendingFiles);
        } else {
            ProcessBankStatementImportJob::dispatch($import->id, $pendingFiles);
        }

        return $import->refresh();
    }

    private function shouldProcessInline(): bool
    {
        $queueConnection = (string) config('queue.default', 'sync');

        return in_array($queueConnection, ['sync', 'database'], true);
    }

    private function processInlineIfQueueWorkerUnavailable(BankStatementImport $import): BankStatementImport
    {
        if (! $this->shouldProcessInline()) {
            return $import;
        }

        $status = (string) $import->processing_status;
        if ($status !== 'queued') {
            if (
                $status !== 'processing'
                || ! $import->processing_started_at
                || $import->processing_started_at->gt(now()->subMinutes(3))
            ) {
                return $import;
            }
        }

        $meta = is_array($import->meta) ? $import->meta : [];
        $queuedEntries = $meta['queued_file_entries'] ?? null;
        $pendingFiles = [];

        if (is_array($queuedEntries) && ! empty($queuedEntries)) {
            foreach ($queuedEntries as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $storagePath = (string) ($entry['storage_path'] ?? '');
                if ($storagePath === '') {
                    continue;
                }

                $pendingFiles[] = [
                    'name' => (string) ($entry['name'] ?? basename($storagePath)),
                    'mime' => $entry['mime'] ?? null,
                    'storage_path' => $storagePath,
                ];
            }
        }

        if (! empty($pendingFiles)) {
            ProcessBankStatementImportJob::dispatchSync($import->id, $pendingFiles);
            return $import->fresh() ?? $import;
        }

        $queuedFiles = $meta['queued_files'] ?? null;
        if (! is_array($queuedFiles) || empty($queuedFiles)) {
            return $import;
        }

        $fallbackFiles = [];
        foreach ($queuedFiles as $storagePath) {
            if (! is_string($storagePath) || $storagePath === '') {
                continue;
            }

            $fallbackFiles[] = [
                'name' => basename($storagePath),
                'mime' => null,
                'storage_path' => $storagePath,
            ];
        }

        if (empty($fallbackFiles)) {
            return $import;
        }

        ProcessBankStatementImportJob::dispatchSync($import->id, $fallbackFiles);

        return $import->fresh() ?? $import;
    }

    private function cleanupPendingFiles(BankStatementImport $import): void
    {
        $queued = $import->meta['queued_files'] ?? null;
        if (! is_array($queued)) {
            return;
        }

        foreach ($queued as $path) {
            if (is_string($path) && $path !== '') {
                Storage::disk('local')->delete($path);
            }
        }
    }

    private function serializeImport(BankStatementImport $import): array
    {
        return [
            'id' => $import->id,
            'transactions' => $import->transactions,
            'meta' => $import->meta,
            'source' => $import->source,
            'file_name' => $import->file_name,
            'file_format' => $import->file_format,
            'processing_status' => $import->processing_status,
            'processing_error' => $import->processing_error,
            'confidence_score' => $import->confidence_score,
            'flagged_rows' => (int) $import->flagged_rows,
            'total_rows' => (int) $import->total_rows,
            'extraction_confidence' => $import->extraction_confidence,
            'balance_mismatch' => (bool) $import->balance_mismatch,
            'extraction_method' => $import->extraction_method,
        ];
    }

    private function createDemoImport(Request $request): BankStatementImport
    {
        $payload = $this->demoData->statementImportPayload();

        return BankStatementImport::create([
            'user_id' => $request->user()->id,
            'transactions' => $payload['transactions'],
            'meta' => $payload['meta'],
            'masked_account' => 'Demo account',
            'source' => 'onboarding_demo',
            'file_name' => 'demo-statement.pdf',
            'file_format' => 'pdf',
            'processing_status' => 'completed',
            'processing_started_at' => now(),
            'processing_completed_at' => now(),
            'extraction_confidence' => $payload['extraction_confidence'] ?? 'high',
            'balance_mismatch' => (bool) ($payload['balance_mismatch'] ?? false),
            'extraction_method' => $payload['extraction_method'] ?? 'ai_pdf',
            'confidence_score' => 99,
            'flagged_rows' => 0,
            'total_rows' => count($payload['transactions'] ?? []),
        ]);
    }
}
