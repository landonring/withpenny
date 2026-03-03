<?php

namespace App\Services\Ingestion;

use App\Services\Statements\StatementParser;
use Illuminate\Support\Str;

class ReceiptIngestionService
{
    public function __construct(
        private readonly AiStructuredExtractionService $aiExtractor,
        private readonly TransactionNormalizationService $normalizer,
        private readonly CategorySuggestionService $categorySuggestion,
    )
    {
    }

    /**
     * @param array<int, string> $imagePaths Absolute local paths.
     * @return array<string,mixed>
     */
    public function processImages(array $imagePaths): array
    {
        $rawTextParts = [];

        foreach ($imagePaths as $path) {
            if (! is_file($path)) {
                continue;
            }

            $processed = $this->preprocessImage($path);
            $processedText = $this->runOcrOnPath($processed);
            $originalText = $this->runOcrOnPath($path);

            $chosen = strlen($processedText) >= strlen($originalText) ? $processedText : $originalText;
            if (trim($chosen) !== '') {
                $rawTextParts[] = trim($chosen);
            }

            if ($processed !== $path && is_file($processed)) {
                @unlink($processed);
            }
        }

        $rawText = trim(implode("\n\n", $rawTextParts));

        $aiError = null;
        $payload = [
            'merchant' => null,
            'date' => null,
            'total' => null,
            'tax' => null,
            'line_items' => [],
        ];

        if ($rawText !== '') {
            try {
                $payload = array_merge($payload, $this->aiExtractor->interpretReceipt($rawText));
            } catch (\Throwable $error) {
                $aiError = $error->getMessage();
            }
        } else {
            $aiError = 'OCR produced no text.';
        }

        $heuristics = $this->runReceiptHeuristics($payload, $rawText);
        $confidence = $this->scoreConfidence($payload, $heuristics['warnings'], $aiError !== null);

        $normalized = $this->normalizer->normalizeReceiptPayload(
            $payload,
            $confidence,
            $heuristics['flagged']
        );

        $categoryInput = trim((string) ($normalized['merchant'] ?? ''));
        if ($categoryInput === '' && ! empty($normalized['line_items'][0]['name'])) {
            $categoryInput = (string) $normalized['line_items'][0]['name'];
        }

        $category = $this->categorySuggestion->suggest($categoryInput);

        return [
            'raw_text' => $rawText,
            'suggestions' => [
                'merchant' => $normalized['merchant'],
                'amount' => $normalized['total'],
                'date' => $normalized['date'],
                'tax' => $normalized['tax'],
                'category' => $category['category'],
            ],
            'line_items' => array_map(fn ($item) => [
                'description' => $item['name'],
                'amount' => $item['amount'],
            ], $normalized['line_items']),
            'extracted_data' => $normalized,
            'confidence_score' => $normalized['confidence_score'],
            'flagged' => (bool) $normalized['flagged'],
            'warnings' => $heuristics['warnings'],
            'processing_error' => $aiError,
            'category_suggestion' => $category['category'],
            'category_confidence' => round(($category['confidence'] ?? 0) * 100, 2),
        ];
    }

    private function preprocessImage(string $path): string
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            return $path;
        }

        $image = @imagecreatefromstring($contents);
        if (! $image) {
            return $path;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        if ($width <= 0 || $height <= 0) {
            imagedestroy($image);
            return $path;
        }

        $maxWidth = 2200;
        if ($width > $maxWidth) {
            $ratio = $maxWidth / $width;
            $newWidth = $maxWidth;
            $newHeight = (int) round($height * $ratio);
            $canvas = imagecreatetruecolor($newWidth, $newHeight);
            imagecopyresampled($canvas, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($image);
            $image = $canvas;
            $width = $newWidth;
            $height = $newHeight;
        }

        imagefilter($image, IMG_FILTER_GRAYSCALE);
        imagefilter($image, IMG_FILTER_CONTRAST, -20);
        imagefilter($image, IMG_FILTER_BRIGHTNESS, 10);

        [$cropX, $cropY, $cropWidth, $cropHeight] = $this->detectContentBounds($image, $width, $height);
        if ($cropWidth > 0 && $cropHeight > 0 && ($cropWidth < $width || $cropHeight < $height)) {
            $cropped = imagecrop($image, [
                'x' => $cropX,
                'y' => $cropY,
                'width' => $cropWidth,
                'height' => $cropHeight,
            ]);

            if ($cropped !== false) {
                imagedestroy($image);
                $image = $cropped;
            }
        }

        $output = storage_path('app/ocr/preprocessed-'.Str::uuid().'.jpg');
        if (! is_dir(dirname($output))) {
            mkdir(dirname($output), 0775, true);
        }

        imagejpeg($image, $output, 92);
        imagedestroy($image);

        return $output;
    }

    /**
     * @return array{0:int,1:int,2:int,3:int}
     */
    private function detectContentBounds($image, int $width, int $height): array
    {
        $threshold = 245;
        $minX = $width;
        $minY = $height;
        $maxX = 0;
        $maxY = 0;

        $stepX = max(1, (int) floor($width / 400));
        $stepY = max(1, (int) floor($height / 400));

        for ($y = 0; $y < $height; $y += $stepY) {
            for ($x = 0; $x < $width; $x += $stepX) {
                $rgb = imagecolorat($image, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                if ($r < $threshold || $g < $threshold || $b < $threshold) {
                    $minX = min($minX, $x);
                    $minY = min($minY, $y);
                    $maxX = max($maxX, $x);
                    $maxY = max($maxY, $y);
                }
            }
        }

        if ($maxX <= $minX || $maxY <= $minY) {
            return [0, 0, $width, $height];
        }

        $padding = 18;
        $x = max(0, $minX - $padding);
        $y = max(0, $minY - $padding);
        $w = min($width - $x, ($maxX - $minX) + (2 * $padding));
        $h = min($height - $y, ($maxY - $minY) + (2 * $padding));

        return [$x, $y, $w, $h];
    }

    private function runOcrOnPath(string $fullPath): string
    {
        $tesseract = trim((string) shell_exec('command -v tesseract'));
        if ($tesseract === '' || ! is_file($fullPath)) {
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

        return trim($rawText);
    }

    private function runTesseract(string $tesseract, string $inputPath, string $outputBase, int $psm): string
    {
        $command = escapeshellcmd($tesseract)
            .' '.escapeshellarg($inputPath)
            .' '.escapeshellarg($outputBase)
            .' --dpi 300 -l eng --oem 1 --psm '.$psm
            .' -c preserve_interword_spaces=1';

        shell_exec($command);

        $textPath = $outputBase.'.txt';
        if (! file_exists($textPath)) {
            return '';
        }

        $rawText = (string) file_get_contents($textPath);
        @unlink($textPath);

        return $rawText;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{warnings:array<int,string>, flagged:bool}
     */
    private function runReceiptHeuristics(array $payload, string $rawText): array
    {
        $warnings = [];

        $merchant = trim((string) ($payload['merchant'] ?? ''));
        $date = StatementParser::parseDate((string) ($payload['date'] ?? ''));
        $total = is_numeric($payload['total'] ?? null) ? abs((float) $payload['total']) : null;

        if ($merchant === '') {
            $warnings[] = 'Merchant missing.';
        }

        if ($date === null) {
            $warnings[] = 'Date missing.';
        }

        if ($total === null || $total <= 0) {
            $warnings[] = 'Total missing.';
        }

        $bottomNumbers = $this->extractBottomAmounts($rawText);
        if ($total !== null && ! empty($bottomNumbers)) {
            $maxBottom = max($bottomNumbers);
            if ($total + 0.01 < $maxBottom) {
                $warnings[] = 'Total may not match largest bottom amount.';
            }
        }

        return [
            'warnings' => $warnings,
            'flagged' => count($warnings) > 0,
        ];
    }

    /**
     * @return array<int,float>
     */
    private function extractBottomAmounts(string $text): array
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $text))));
        if (empty($lines)) {
            return [];
        }

        $tail = array_slice($lines, -12);
        $amounts = [];

        foreach ($tail as $line) {
            if (preg_match_all('/\b([0-9]{1,6}(?:[.,][0-9]{2}))\b/', $line, $matches)) {
                foreach ($matches[1] as $candidate) {
                    $value = (float) str_replace(',', '', $candidate);
                    if ($value > 0) {
                        $amounts[] = $value;
                    }
                }
            }
        }

        return $amounts;
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<int,string> $warnings
     */
    private function scoreConfidence(array $payload, array $warnings, bool $hasAiError): float
    {
        $score = 100.0;

        if (! is_numeric($payload['total'] ?? null) || (float) $payload['total'] <= 0) {
            $score -= 35.0;
        }

        if (StatementParser::parseDate((string) ($payload['date'] ?? '')) === null) {
            $score -= 25.0;
        }

        if (trim((string) ($payload['merchant'] ?? '')) === '') {
            $score -= 20.0;
        }

        $lineItems = is_array($payload['line_items'] ?? null) ? $payload['line_items'] : [];
        if (count($lineItems) === 0) {
            $score -= 10.0;
        }

        $score -= min(20.0, count($warnings) * 5.0);

        if ($hasAiError) {
            $score -= 30.0;
        }

        return round(max(0.0, min(100.0, $score)), 2);
    }
}
