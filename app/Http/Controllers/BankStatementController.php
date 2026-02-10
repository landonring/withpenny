<?php

namespace App\Http\Controllers;

use App\Models\BankStatementImport;
use App\Models\Transaction;
use App\Services\Statements\PdfStatementParser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BankStatementController extends Controller
{
    public function upload(Request $request)
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:15360'],
        ], [
            'file.mimes' => 'That file type isn’t supported yet. A statement screenshot (PNG/JPG) works best.',
        ]);

        $file = $validated['file'];
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $imageExtensions = ['jpg', 'jpeg', 'png', 'webp'];

        $path = $file->store('tmp');

        $pdfExtractionFailed = false;
        $summary = null;

        try {
            $parser = new PdfStatementParser();
            $imagePath = $this->storeStatementImage($file);
            try {
                $text = $this->runOcrOnFile($imagePath);
            } finally {
                @unlink($imagePath);
            }

            if ($text === '') {
                $pdfExtractionFailed = true;
                $transactions = [];
            } else {
                $transactions = $parser->parseText($text);
                $summary = $parser->extractSummary($text);
            }
        } catch (\Throwable $error) {
            Log::warning('statement_parse_failed', ['source' => $extension, 'error' => $error->getMessage()]);
            $transactions = [];
        } finally {
            Storage::disk('local')->delete($path);
        }

        if (empty($transactions)) {
            return response()->json([
                'message' => $pdfExtractionFailed
                    ? "We couldn't read that screenshot. You can try again or keep things manual."
                    : "We couldn't find any transactions in this file.",
            ], 422);
        }

        $transactions = $this->flagDuplicates($request->user()->id, $transactions);

        $import = BankStatementImport::create([
            'user_id' => $request->user()->id,
            'transactions' => $transactions,
            'meta' => $summary,
            'masked_account' => null,
            'source' => 'photo',
        ]);

        return response()->json([
            'import' => [
                'id' => $import->id,
                'transactions' => $import->transactions,
                'meta' => $import->meta,
            ],
        ], 201);
    }

    public function scanImages(Request $request)
    {
        $validated = $request->validate([
            'images' => ['required', 'array', 'max:6'],
            'images.*' => ['required', 'image', 'max:8192'],
        ]);

        $rawText = '';
        foreach ($validated['images'] as $image) {
            $path = $this->storeStatementImage($image);
            try {
                $rawText .= "\n".$this->runOcrOnFile($path);
            } finally {
                @unlink($path);
            }
        }

        $parser = new PdfStatementParser();
        $transactions = $parser->parseText($rawText);
        $summary = $parser->extractSummary($rawText);

        if (empty($transactions)) {
            return response()->json([
                'message' => "We couldn't read that photo. You can try again or keep things manual.",
            ], 422);
        }

        $transactions = $this->flagDuplicates($request->user()->id, $transactions);

        $import = BankStatementImport::create([
            'user_id' => $request->user()->id,
            'transactions' => $transactions,
            'meta' => $summary,
            'masked_account' => null,
            'source' => 'photo',
        ]);

        return response()->json([
            'import' => [
                'id' => $import->id,
                'transactions' => $import->transactions,
                'meta' => $import->meta,
            ],
        ], 201);
    }

    public function show(Request $request, BankStatementImport $import)
    {
        $this->authorizeImport($request, $import);

        return response()->json([
            'import' => [
                'id' => $import->id,
                'transactions' => $import->transactions,
                'meta' => $import->meta,
            ],
        ]);
    }

    public function confirm(Request $request, BankStatementImport $import)
    {
        $this->authorizeImport($request, $import);

        $validated = $request->validate([
            'transactions' => ['required', 'array'],
            'transactions.*.date' => ['required', 'date'],
            'transactions.*.description' => ['required', 'string', 'max:255'],
            'transactions.*.amount' => ['required', 'numeric', 'min:0.01'],
            'transactions.*.type' => ['required', 'in:income,spending'],
            'transactions.*.include' => ['required', 'boolean'],
        ]);

        $toCreate = [];
        foreach ($validated['transactions'] as $item) {
            if (! $item['include']) {
                continue;
            }
            $type = $item['type'] === 'income' ? 'income' : 'spending';

            $toCreate[] = [
                'user_id' => $request->user()->id,
                'amount' => $item['amount'],
                'category' => $type === 'income' ? 'Income' : 'Misc',
                'note' => $item['description'],
                'transaction_date' => $item['date'],
                'source' => 'statement',
                'type' => $type,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (! empty($toCreate)) {
            Transaction::query()->insert($toCreate);
        }

        $import->delete();

        return response()->json([
            'status' => 'imported',
            'count' => count($toCreate),
        ]);
    }

    public function destroy(Request $request, BankStatementImport $import)
    {
        $this->authorizeImport($request, $import);
        $import->delete();

        return response()->json(['status' => 'discarded']);
    }

    private function authorizeImport(Request $request, BankStatementImport $import): void
    {
        if ($import->user_id !== $request->user()->id) {
            abort(403);
        }
    }

    private function storeStatementImage($file): string
    {
        $contents = file_get_contents($file->getRealPath());
        $image = @imagecreatefromstring($contents);

        $tmpDir = storage_path('app/tmp');
        if (! is_dir($tmpDir)) {
            mkdir($tmpDir, 0775, true);
        }

        if (! $image) {
            $extension = $file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg';
            $filename = Str::uuid().'.'.$extension;
            $path = $tmpDir.'/'.$filename;
            $file->move($tmpDir, $filename);
            return $path;
        }

        $filename = Str::uuid().'.jpg';
        $path = $tmpDir.'/'.$filename;

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

        imagefilter($canvas, IMG_FILTER_GRAYSCALE);
        imagefilter($canvas, IMG_FILTER_CONTRAST, -8);
        imagejpeg($canvas, $path, 90);

        imagedestroy($image);
        imagedestroy($canvas);

        return $path;
    }

    private function runOcrOnFile(string $path): string
    {
        if (! file_exists($path)) {
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

        $command = escapeshellcmd($tesseract).' '.escapeshellarg($path).' '.escapeshellarg($outputBase).' --dpi 300 -l eng --oem 1 --psm 6 -c preserve_interword_spaces=1';
        shell_exec($command);

        $textPath = $outputBase.'.txt';
        if (! file_exists($textPath)) {
            return '';
        }

        $rawText = trim((string) file_get_contents($textPath));
        @unlink($textPath);

        return $rawText;
    }

    private function flagDuplicates(int $userId, array $transactions): array
    {
        $dates = array_column($transactions, 'date');
        if (empty($dates)) {
            return $transactions;
        }

        $min = min($dates);
        $max = max($dates);

        $existing = Transaction::query()
            ->where('user_id', $userId)
            ->whereBetween('transaction_date', [$min, $max])
            ->get(['transaction_date', 'amount', 'note', 'type']);

        $existingMap = [];
        foreach ($existing as $row) {
            $key = $row->transaction_date.'|'.$row->amount.'|'.($row->type ?? 'spending');
            $existingMap[$key][] = $this->normalizeDescription($row->note ?? '');
        }

        return array_map(function ($item) use ($existingMap) {
            $key = $item['date'].'|'.$item['amount'].'|'.($item['type'] ?? 'spending');
            if (isset($existingMap[$key])) {
                $candidate = $this->normalizeDescription($item['description']);
                foreach ($existingMap[$key] as $existingDescription) {
                    if ($this->isSimilarDescription($candidate, $existingDescription)) {
                        $item['duplicate'] = true;
                        break;
                    }
                }
            }
            return $item;
        }, $transactions);
    }

    private function normalizeDescription(string $value): string
    {
        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $value)));
        return preg_replace('/[^a-z0-9 ]/', '', $normalized);
    }

    private function isSimilarDescription(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }

        if ($a === $b) {
            return true;
        }

        similar_text($a, $b, $percent);
        return $percent >= 85.0;
    }
}
