<?php

namespace App\Http\Controllers;

use App\Models\Receipt;
use App\Models\ReceiptText;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ReceiptController extends Controller
{
    public function scan(Request $request)
    {
        $validated = $request->validate([
            'image' => ['required', 'image', 'max:8192'],
        ]);

        $path = $this->storeReceiptImage($validated['image'], $request->user()->id);

        $receipt = Receipt::create([
            'user_id' => $request->user()->id,
            'image_path' => $path,
            'scanned_at' => now(),
        ]);

        $rawText = $this->runOcr($path);

        ReceiptText::create([
            'receipt_id' => $receipt->id,
            'raw_text' => $rawText,
        ]);

        return response()->json([
            'receipt' => $receipt,
            'image_url' => Storage::disk('public')->url($receipt->image_path),
            'raw_text' => $rawText,
            'suggestions' => $this->suggestFromText($rawText),
        ], 201);
    }

    public function show(Request $request, Receipt $receipt)
    {
        $this->authorizeReceipt($request, $receipt);

        $rawText = $receipt->receiptText?->raw_text ?? '';

        return response()->json([
            'receipt' => $receipt,
            'image_url' => Storage::disk('public')->url($receipt->image_path),
            'raw_text' => $rawText,
            'suggestions' => $this->suggestFromText($rawText),
        ]);
    }

    public function confirm(Request $request, Receipt $receipt)
    {
        $this->authorizeReceipt($request, $receipt);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'category' => ['required', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:255'],
            'transaction_date' => ['nullable', 'date'],
        ]);

        $validated['transaction_date'] = $validated['transaction_date'] ?? now()->toDateString();

        $transaction = Transaction::create([
            'user_id' => $request->user()->id,
            'receipt_id' => $receipt->id,
            'amount' => $validated['amount'],
            'category' => $validated['category'],
            'note' => $validated['note'],
            'transaction_date' => $validated['transaction_date'],
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

    private function storeReceiptImage($file, int $userId): string
    {
        $contents = file_get_contents($file->getRealPath());
        $image = @imagecreatefromstring($contents);

        if (! $image) {
            $extension = $file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg';
            $filename = Str::uuid().'.'.$extension;
            $path = "receipts/{$userId}/{$filename}";
            Storage::disk('public')->putFileAs("receipts/{$userId}", $file, $filename);
            return $path;
        }

        $filename = Str::uuid().'.jpg';
        $path = "receipts/{$userId}/{$filename}";

        $width = imagesx($image);
        $height = imagesy($image);
        $maxWidth = 1600;

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
        imagefilter($canvas, IMG_FILTER_CONTRAST, -8);
        imagejpeg($canvas, null, 90);
        $jpegData = ob_get_clean();

        imagedestroy($image);
        imagedestroy($canvas);

        Storage::disk('public')->put($path, $jpegData);

        return $path;
    }

    private function runOcr(string $path): string
    {
        $fullPath = Storage::disk('public')->path($path);

        if (! file_exists($fullPath)) {
            return '';
        }

        $tesseract = trim((string) shell_exec('command -v tesseract'));
        if ($tesseract === '') {
            return '';
        }

        $outputBase = storage_path('app/ocr/'.Str::uuid());
        if (! is_dir(dirname($outputBase))) {
            mkdir(dirname($outputBase), 0775, true);
        }

        $command = escapeshellcmd($tesseract).' '.escapeshellarg($fullPath).' '.escapeshellarg($outputBase).' --dpi 300 -l eng --oem 1 --psm 6 -c preserve_interword_spaces=1';
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
        $patterns = [
            '/total\s*[:\-]?\s*\$?([0-9]+(?:[\.,][0-9]{2})?)/i',
            '/amount\s*due\s*[:\-]?\s*\$?([0-9]+(?:[\.,][0-9]{2})?)/i',
            '/balance\s*due\s*[:\-]?\s*\$?([0-9]+(?:[\.,][0-9]{2})?)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $match)) {
                return $this->normalizeAmount($match[1]);
            }
        }

        if (preg_match_all('/\$?([0-9]+(?:[\.,][0-9]{2})?)/', $text, $matches)) {
            $amounts = array_map([$this, 'normalizeAmount'], $matches[1]);
            $amounts = array_filter($amounts, fn ($value) => $value !== null);
            if (! empty($amounts)) {
                return (string) max($amounts);
            }
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
}
