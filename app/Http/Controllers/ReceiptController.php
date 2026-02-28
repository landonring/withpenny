<?php

namespace App\Http\Controllers;

use App\Models\Receipt;
use App\Models\ReceiptText;
use App\Models\Transaction;
use App\Services\PlanUsageService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReceiptController extends Controller
{
    public function __construct(private readonly PlanUsageService $planUsage)
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

        $path = $this->storeReceiptImage($file, $request->user()->id);

        $receipt = Receipt::create([
            'user_id' => $request->user()->id,
            'image_path' => $path,
            'scanned_at' => now(),
        ]);

        analytics_track('receipt_uploaded', ['mode' => 'single']);

        [$rawText, $lineItems] = $this->selectBestOcr($path, $validated['image']);

        ReceiptText::create([
            'receipt_id' => $receipt->id,
            'raw_text' => $rawText,
        ]);

        $suggestions = $this->suggestFromText($rawText);
        $suggestions = $this->mergeLineItemTotal($suggestions, $lineItems);
        $isStarter = $this->planUsage->isStarter($request->user());
        if ($isStarter) {
            $lineItems = [];
        }

        return response()->json([
            'receipt' => $receipt,
            'image_url' => Storage::disk('public')->url($receipt->image_path),
            'raw_text' => $rawText,
            'suggestions' => $suggestions,
            'line_items' => $lineItems,
            'mode' => $isStarter ? 'basic' : 'full',
        ], 201);
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

        $images = $validated['images'];
        $primary = array_shift($images);

        $path = $this->storeReceiptImage($primary, $request->user()->id);

        $receipt = Receipt::create([
            'user_id' => $request->user()->id,
            'image_path' => $path,
            'scanned_at' => now(),
        ]);

        analytics_track('receipt_uploaded', ['mode' => 'multi', 'images' => count($validated['images'])]);

        $rawTexts = [];
        $lineItems = [];
        [$primaryText, $primaryItems] = $this->selectBestOcr($path, $primary);
        $rawTexts[] = $primaryText;
        $lineItems = array_merge($lineItems, $primaryItems);

        foreach ($images as $image) {
            $tempPath = $this->storeReceiptImage($image, $request->user()->id);
            [$imageText, $imageItems] = $this->selectBestOcr($tempPath, $image);
            $rawTexts[] = $imageText;
            $lineItems = array_merge($lineItems, $imageItems);
            Storage::disk('public')->delete($tempPath);
        }

        $rawText = trim(implode("\n\n", array_filter($rawTexts)));

        ReceiptText::create([
            'receipt_id' => $receipt->id,
            'raw_text' => $rawText,
        ]);

        $suggestions = $this->suggestFromText($rawText);
        $suggestions = $this->mergeLineItemTotal($suggestions, $lineItems);
        $isStarter = $this->planUsage->isStarter($request->user());
        if ($isStarter) {
            $lineItems = [];
        }

        return response()->json([
            'receipt' => $receipt,
            'image_url' => Storage::disk('public')->url($receipt->image_path),
            'raw_text' => $rawText,
            'suggestions' => $suggestions,
            'line_items' => $lineItems,
            'mode' => $isStarter ? 'basic' : 'full',
        ], 201);
    }

    public function show(Request $request, Receipt $receipt)
    {
        $this->authorizeReceipt($request, $receipt);

        $rawText = $receipt->receiptText?->raw_text ?? '';

        $lineItems = $this->extractLineItems($rawText);
        $suggestions = $this->suggestFromText($rawText);
        $suggestions = $this->mergeLineItemTotal($suggestions, $lineItems);

        return response()->json([
            'receipt' => $receipt,
            'image_url' => Storage::disk('public')->url($receipt->image_path),
            'raw_text' => $rawText,
            'suggestions' => $suggestions,
            'line_items' => $lineItems,
        ]);
    }

    public function confirm(Request $request, Receipt $receipt)
    {
        $this->authorizeReceipt($request, $receipt);

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

        if (! empty($validated['items'])) {
            $transactions = [];
            foreach ($validated['items'] as $item) {
                $note = $item['note'] ?? $item['description'] ?? null;
                $category = $item['category'] ?? $validated['category'] ?? 'Misc';
                $transactions[] = Transaction::create([
                    'user_id' => $request->user()->id,
                    'receipt_id' => $receipt->id,
                    'amount' => $item['amount'],
                    'category' => $category,
                    'note' => $note,
                    'transaction_date' => $transactionDate,
                    'type' => 'spending',
                ]);
            }

            return response()->json([
                'transactions' => $transactions,
            ], 201);
        }

        $transaction = Transaction::create([
            'user_id' => $request->user()->id,
            'receipt_id' => $receipt->id,
            'amount' => $validated['amount'],
            'category' => $validated['category'],
            'note' => $validated['note'],
            'transaction_date' => $transactionDate,
            'type' => 'spending',
        ]);

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

    private function storeReceiptImage($file, int $userId): string
    {
        $contents = file_get_contents($file->getRealPath());
        $image = @imagecreatefromstring($contents);

        if (! $image) {
            return $this->storeOriginalReceiptFile($file, $userId);
        }

        $filename = Str::uuid().'.jpg';
        $path = "receipts/{$userId}/{$filename}";

        $width = imagesx($image);
        $height = imagesy($image);
        $maxWidth = 2200;

        if ($width > $maxWidth) {
            $ratio = $maxWidth / $width;
            $newWidth = $maxWidth;
            $newHeight = (int) round($height * $ratio);
        } else {
            $newWidth = $width;
            $newHeight = $height;
        }

        $canvas = imagecreatetruecolor($newWidth, $newHeight);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        ob_start();
        imagefilter($canvas, IMG_FILTER_GRAYSCALE);
        imagefilter($canvas, IMG_FILTER_CONTRAST, -10);
        imagejpeg($canvas, null, 90);
        $jpegData = ob_get_clean();

        imagedestroy($image);
        imagedestroy($canvas);

        Storage::disk('public')->put($path, $jpegData);

        return $path;
    }

    private function storeOriginalReceiptFile($file, int $userId): string
    {
        $extension = $file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg';
        $filename = Str::uuid().'.'.$extension;
        $path = "receipts/{$userId}/{$filename}";
        Storage::disk('public')->putFileAs("receipts/{$userId}", $file, $filename);
        return $path;
    }

    private function runOcr(string $path): string
    {
        $fullPath = Storage::disk('public')->path($path);

        if (! file_exists($fullPath)) {
            return '';
        }

        return $this->runOcrOnPath($fullPath);
    }

    private function runOcrOnPath(string $fullPath): string
    {
        $tesseract = trim((string) shell_exec('command -v tesseract'));
        if ($tesseract === '') {
            return '';
        }

        $outputBase = storage_path('app/ocr/'.Str::uuid());
        if (! is_dir(dirname($outputBase))) {
            mkdir(dirname($outputBase), 0775, true);
        }

        $rawText = $this->runTesseract($tesseract, $fullPath, $outputBase, 6);

        if ($rawText === '') {
            $rawText = $this->runTesseract($tesseract, $fullPath, $outputBase, 4);
        }

        return $rawText;
    }

    private function selectBestOcr(string $processedPath, $file): array
    {
        $processedText = $this->runOcr($processedPath);
        $processedItems = $this->extractLineItems($processedText);

        $originalText = '';
        $originalItems = [];

        if ($file && method_exists($file, 'getRealPath')) {
            $originalPath = $file->getRealPath();
            if ($originalPath && file_exists($originalPath)) {
                $originalText = $this->runOcrOnPath($originalPath);
                $originalItems = $this->extractLineItems($originalText);
            }
        }

        $processedScore = $this->countPricedItems($processedItems);
        $originalScore = $this->countPricedItems($originalItems);

        if ($originalScore > $processedScore) {
            return [$originalText, $originalItems];
        }

        return [$processedText, $processedItems];
    }

    private function countPricedItems(array $items): int
    {
        $count = 0;
        foreach ($items as $item) {
            if (isset($item['amount']) && $item['amount'] !== null && $item['amount'] !== '') {
                $count += 1;
            }
        }
        return $count;
    }

    private function runTesseract(string $tesseract, string $inputPath, string $outputBase, int $psm): string
    {
        $command = escapeshellcmd($tesseract).' '.escapeshellarg($inputPath).' '.escapeshellarg($outputBase).' --dpi 300 -l eng --oem 1 --psm '.$psm.' -c preserve_interword_spaces=1';
        shell_exec($command);

        $textPath = $outputBase.'.txt';
        if (! file_exists($textPath)) {
            return '';
        }

        $rawText = trim((string) file_get_contents($textPath));
        @unlink($textPath);

        return $rawText;
    }

    private function suggestFromText(string $text): array
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $text ?? ''))));

        $merchant = '';
        foreach ($lines as $line) {
            if (strlen($line) < 3) {
                continue;
            }
            if (preg_match('/total|subtotal|tax|change|amount|balance/i', $line)) {
                continue;
            }
            $merchant = $line;
            break;
        }
        if (! $merchant && isset($lines[0])) {
            $merchant = $lines[0];
        }

        $amount = $this->extractTotal($text);
        $date = $this->extractDate($text);

        return [
            'merchant' => $merchant,
            'amount' => $amount,
            'date' => $date,
        ];
    }

    private function extractTotal(string $text): ?string
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $text ?? ''))));

        foreach ($lines as $line) {
            if (! preg_match('/total|amount due|balance due/i', $line)) {
                continue;
            }
            $amounts = $this->extractDecimalAmounts($line);
            if (! empty($amounts)) {
                return (string) max($amounts);
            }
        }

        $amounts = $this->extractDecimalAmounts($text);
        if (! empty($amounts)) {
            return (string) max($amounts);
        }

        return null;
    }

    private function normalizeAmount(string $amount): ?float
    {
        $clean = str_replace(',', '', $amount);
        $value = (float) $clean;
        return $value > 0 ? $value : null;
    }

    private function extractDate(string $text): ?string
    {
        $patterns = [
            '/\b(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})\b/',
            '/\b(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})\b/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $match)) {
                try {
                    if (strlen($match[1]) === 4) {
                        $date = Carbon::createFromFormat('Y-m-d', sprintf('%04d-%02d-%02d', $match[1], $match[2], $match[3]));
                    } else {
                        $year = strlen($match[3]) === 2 ? '20'.$match[3] : $match[3];
                        $date = Carbon::createFromFormat('m-d-Y', sprintf('%02d-%02d-%04d', $match[1], $match[2], $year));
                    }
                    return $date->toDateString();
                } catch (\Throwable $error) {
                    continue;
                }
            }
        }

        return null;
    }

    private function extractLineItems(string $text): array
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $text ?? ''))));
        if (empty($lines)) {
            return [];
        }

        $skipPatterns = [
            'total',
            'subtotal',
            'tax',
            'change',
            'balance',
            'amount due',
            'tender',
            'visa',
            'mastercard',
            'amex',
            'discover',
            'debit',
            'credit',
            'cash',
            'tip',
            'gratuity',
            'service',
            'discount',
            'savings',
            'coupon',
            'auth',
            'entry method',
            'card#',
            'card #',
            'expdate',
            'register',
            'cashier',
            'store',
            'phone',
            'tel',
            'str:',
            'reg:',
            'trn:',
            'cshr',
        ];

        $items = [];
        $pendingDescription = '';
        $pendingUsed = false;
        $lastItemKey = null;
        $orphanDescriptions = [];
        $seenPricedItem = false;
        $stopCollecting = false;

        foreach ($lines as $line) {
            $line = trim(preg_replace('/[^\P{C}]+/u', '', $line));

            if (strlen($line) < 3) {
                continue;
            }

            $normalized = strtolower($line);
            $skip = false;
            foreach ($skipPatterns as $pattern) {
                if (str_contains($normalized, $pattern)) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                if (str_contains($normalized, 'subtotal') || str_contains($normalized, 'total') || str_contains($normalized, 'tax')) {
                    if ($pendingDescription !== '' && ! $pendingUsed && $seenPricedItem) {
                        $orphanDescriptions[] = $pendingDescription;
                        $pendingDescription = '';
                        $pendingUsed = true;
                    }
                    $stopCollecting = true;
                }
                continue;
            }

            if (preg_match('/\b\d{1,2}[:.]\d{2}\b/', $line) && preg_match('/\b(am|pm)\b/i', $line)) {
                continue;
            }
            if (preg_match('/\b\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}\b/', $line)) {
                continue;
            }
            if (preg_match('/\b\d{4}-\d{1,2}-\d{1,2}\b/', $line)) {
                continue;
            }

            $allowCentsNoMarker = $pendingDescription !== '' && ! preg_match('/[a-z]/i', $line);
            $amount = $this->extractAmountFromLine($line, $allowCentsNoMarker);

            if ($amount !== null) {
                $lineHasAt = str_contains($line, '@')
                    || str_contains($line, '®')
                    || preg_match('/\(\s*\d+\s*[@®x]/i', $line);
                $description = '';
                if ($lineHasAt && $pendingDescription !== '') {
                    $description = $pendingDescription;
                } elseif (preg_match('/[a-z]/i', $line)) {
                    $description = $this->stripAmountFromLine($line);
                } else {
                    $description = $pendingDescription;
                }

                $description = $this->cleanDescription($description);
                if ($description === '' || $this->isBadDescription($description)) {
                    $pendingDescription = '';
                    continue;
                }

                $key = strtolower($description).'|'.$amount;
                if ($lastItemKey === $key) {
                    $pendingDescription = '';
                    continue;
                }
                $lastItemKey = $key;

                $items[] = [
                    'description' => $description,
                    'amount' => $amount,
                ];

                $pendingUsed = true;
                $pendingDescription = '';
                $seenPricedItem = true;
                continue;
            }

            if ($this->isLikelyDescription($line)) {
                if ($stopCollecting) {
                    continue;
                }
                if ($pendingDescription !== '' && ! $pendingUsed && $seenPricedItem) {
                    $orphanDescriptions[] = $pendingDescription;
                }
                $pendingDescription = $line;
                $pendingUsed = false;
            }
        }

        if ($pendingDescription !== '' && ! $pendingUsed && $seenPricedItem && ! $stopCollecting) {
            $orphanDescriptions[] = $pendingDescription;
        }

        if (! empty($orphanDescriptions)) {
            $existing = [];
            foreach ($items as $item) {
                $existing[strtolower($item['description'])] = true;
            }

            foreach ($orphanDescriptions as $description) {
                $clean = $this->cleanDescription($description);
                if ($clean === '' || $this->isBadDescription($clean)) {
                    continue;
                }
                $wordCount = preg_match_all('/[a-z]{3,}/i', $clean);
                if ($wordCount < 2) {
                    continue;
                }
                $key = strtolower($clean);
                if (isset($existing[$key])) {
                    continue;
                }
                $existing[$key] = true;
                $items[] = [
                    'description' => $clean,
                    'amount' => null,
                ];
            }
        }

        $items = $this->fillMissingItemAmounts($items, $text);

        return array_slice($items, 0, 40);
    }

    private function fillMissingItemAmounts(array $items, string $text): array
    {
        $missingIndexes = [];
        $sum = 0.0;

        foreach ($items as $index => $item) {
            if (isset($item['amount']) && $item['amount'] !== null && $item['amount'] !== '') {
                $sum += (float) $item['amount'];
            } else {
                $missingIndexes[] = $index;
            }
        }

        if (empty($missingIndexes)) {
            return $items;
        }

        $total = $this->extractTotal($text);
        if ($total !== null) {
            $totalValue = (float) $total;
            if ($totalValue < $sum - 0.01 || $totalValue > max($sum * 3, $sum + 500)) {
                $total = null;
            }
        }

        if ($total !== null) {
            $totalValue = (float) $total;
            $remaining = round($totalValue - $sum, 2);

            if ($remaining <= 0.5) {
                $items = array_values(array_filter($items, fn ($item) => $item['amount'] !== null && $item['amount'] !== ''));
                return $items;
            }

            $count = count($missingIndexes);
            if ($count === 0) {
                return $items;
            }

            $perItem = round($remaining / $count, 2);
            if ($perItem <= 0) {
                return $items;
            }

            $allocated = 0.0;
            foreach ($missingIndexes as $i => $index) {
                $value = $perItem;
                if ($i === $count - 1) {
                    $value = round($remaining - $allocated, 2);
                } else {
                    $allocated += $value;
                }
                $items[$index]['amount'] = $value;
                $items[$index]['estimated'] = true;
            }

            return $items;
        }

        $known = [];
        foreach ($items as $item) {
            if (isset($item['amount']) && $item['amount'] !== null && $item['amount'] !== '') {
                $known[] = round((float) $item['amount'], 2);
            }
        }

        if (empty($known)) {
            return $items;
        }

        $counts = [];
        foreach ($known as $amount) {
            $counts[$amount] = ($counts[$amount] ?? 0) + 1;
        }

        arsort($counts);
        $mode = (float) array_key_first($counts);
        $values = array_values($counts);
        $useMode = count($values) === 1 || ($values[0] >= 2 && ($values[1] ?? 0) < $values[0]);

        sort($known);
        $mid = (int) floor(count($known) / 2);
        $median = count($known) % 2 === 0
            ? round(($known[$mid - 1] + $known[$mid]) / 2, 2)
            : $known[$mid];

        $fill = $useMode ? $mode : $median;

        foreach ($missingIndexes as $index) {
            $items[$index]['amount'] = $fill;
            $items[$index]['estimated'] = true;
        }

        return $items;
    }

    private function mergeLineItemTotal(array $suggestions, array $lineItems): array
    {
        if (empty($lineItems)) {
            return $suggestions;
        }

        $sum = array_sum(array_map(fn ($item) => (float) ($item['amount'] ?? 0), $lineItems));
        if ($sum <= 0) {
            return $suggestions;
        }

        $suggestions['amount'] = round($sum, 2);

        return $suggestions;
    }

    private function extractDecimalAmounts(string $text): array
    {
        if (! preg_match_all('/\$?\b([0-9]{1,4}(?:[.,][0-9]{2}))\b/', $text, $matches)) {
            return [];
        }

        $amounts = [];
        foreach ($matches[1] as $match) {
            $normalized = str_replace(',', '', $match);
            if (! preg_match('/^\d+(\.\d{2})$/', $normalized)) {
                continue;
            }
            $value = (float) $normalized;
            if ($value <= 0 || $value >= 10000) {
                continue;
            }
            $amounts[] = $value;
        }

        return $amounts;
    }

    private function extractAmountFromLine(string $line, bool $allowCentsNoMarker = false): ?float
    {
        if (preg_match_all('/\$?\b([0-9]{1,4}(?:[.,][0-9]{2})?)\b/', $line, $matches)) {
            $candidates = $matches[1];
            $ordered = $candidates;

            $values = [];
            foreach ($ordered as $candidate) {
                $normalized = str_replace(',', '', $candidate);
                if (! preg_match('/^\d+(\.\d{2})?$/', $normalized)) {
                    continue;
                }

                $digitsOnly = preg_replace('/\D/', '', $normalized);
                if (strlen($digitsOnly) >= 5) {
                    continue;
                }

                if (str_contains($normalized, '.')) {
                    $amount = (float) $normalized;
                } elseif (strlen($normalized) === 4) {
                    if (! $allowCentsNoMarker && ! str_contains($line, '@') && ! str_contains($line, '$')) {
                        continue;
                    }
                    $amount = ((float) $normalized) / 100;
                } else {
                    continue;
                }

                if ($amount > 0 && $amount < 10000) {
                    $values[] = $amount;
                }
            }

            if (! empty($values)) {
                return max($values);
            }
        }

        return null;
    }

    private function stripAmountFromLine(string $line): string
    {
        $stripped = preg_replace('/\$?\b[0-9]{1,4}(?:[.,][0-9]{2})?\b(?!.*\b[0-9]{1,4}(?:[.,][0-9]{2})?\b)/', '', $line);
        return trim((string) $stripped);
    }

    private function cleanDescription(string $description): string
    {
        $description = preg_replace('/\s{2,}/', ' ', $description ?? '');
        $description = trim((string) $description, "-:;,. ");
        return trim($description ?? '');
    }

    private function isBadDescription(string $description): bool
    {
        if ($description === '') {
            return true;
        }

        if (preg_match('/^[\d\W]+$/', $description)) {
            return true;
        }

        if (preg_match('/\b\d{7,}\b/', $description)) {
            return true;
        }

        $normalized = strtolower($description);
        $addressTokens = ['street', 'st', 'road', 'rd', 'avenue', 'ave', 'blvd', 'pkwy', 'parkway', 'suite', 'unit'];
        foreach ($addressTokens as $token) {
            if (str_contains($normalized, ' '.$token) || str_contains($normalized, $token.' ')) {
                return true;
            }
        }

        return false;
    }

    private function isLikelyDescription(string $line): bool
    {
        if (! preg_match('/[a-z]/i', $line)) {
            return false;
        }

        if (preg_match('/\b\d{7,}\b/', $line)) {
            return false;
        }

        if (strlen($line) < 4) {
            return false;
        }

        $letters = preg_match_all('/[a-z]/i', $line);
        $digits = preg_match_all('/\d/', $line);
        if ($digits > $letters) {
            return false;
        }

        if (preg_match('/\b\d{5}(-\d{4})?\b/', $line)) {
            return false;
        }

        if (preg_match('/\b[A-Z]{2}\s*\d{5}\b/', strtoupper($line))) {
            return false;
        }

        if (preg_match('/\b\d{4,}\b/', $line)) {
            $wordCount = preg_match_all('/[a-z]{2,}/i', $line);
            if ($wordCount < 2) {
                return false;
            }
        }

        return true;
    }
}
