<?php

namespace App\Services\Ingestion;

use App\Services\Statements\CsvStatementParser;
use App\Services\Statements\PdfStatementParser;
use App\Services\Statements\StatementParser;
use Illuminate\Support\Facades\Log;

class StatementIngestionService
{
    public function __construct(
        private readonly CsvStatementParser $csvParser,
        private readonly OfxStatementParser $ofxParser,
        private readonly AiStructuredExtractionService $aiExtractor,
        private readonly TransactionNormalizationService $normalizer,
        private readonly PdfStatementParser $pdfParser,
    )
    {
    }

    public function detectFormat(string $originalName, ?string $mime = null): ?string
    {
        $lower = strtolower($originalName);
        $mimeLower = strtolower((string) $mime);

        if (str_ends_with($lower, '.csv') || str_contains($mimeLower, 'csv')) {
            return 'csv';
        }

        if (str_ends_with($lower, '.ofx') || str_ends_with($lower, '.qfx') || str_contains($mimeLower, 'ofx')) {
            return 'ofx';
        }

        if (str_ends_with($lower, '.pdf') || str_contains($mimeLower, 'pdf')) {
            return 'pdf';
        }

        return null;
    }

    /**
     * @param array<int, array{name:string, path:string, mime?:string|null}> $files
     * @return array<string,mixed>
     */
    public function processFiles(array $files): array
    {
        $rawRows = [];
        $formats = [];
        $rawLogs = [];
        $errors = [];
        $invalidRows = 0;
        $statementRange = ['min' => null, 'max' => null];

        foreach ($files as $file) {
            $name = (string) ($file['name'] ?? 'statement.pdf');
            $format = $this->detectFormat($name, (string) ($file['mime'] ?? ''));
            if ($format === null) {
                $errors[] = "Unsupported file format for {$name}.";
                continue;
            }

            $formats[] = $format;
            $path = (string) ($file['path'] ?? '');
            if ($path === '' || ! is_file($path)) {
                $errors[] = "Uploaded file is missing: {$name}.";
                continue;
            }

            if ($format === 'csv') {
                $rows = $this->csvParser->parse($path);
                $rawRows = array_merge($rawRows, $rows);
                $statementRange = $this->mergeRange($statementRange, $this->rangeFromRows($rows));
                continue;
            }

            if ($format === 'ofx') {
                $content = (string) @file_get_contents($path);
                $rows = $this->ofxParser->parse($content);
                $rawRows = array_merge($rawRows, $rows);
                $statementRange = $this->mergeRange($statementRange, $this->rangeFromRows($rows));
                continue;
            }

            $pdf = $this->processPdf($path, $name);
            $rawRows = array_merge($rawRows, $pdf['rows']);
            $rawLogs[] = $pdf['raw_text'];
            $invalidRows += (int) ($pdf['invalid_rows'] ?? 0);
            $statementRange = $this->mergeRange($statementRange, $pdf['statement_range'] ?? ['min' => null, 'max' => null]);
            if (! empty($pdf['error'])) {
                $errors[] = (string) $pdf['error'];
            }
        }

        $validated = $this->validateRows($rawRows, $statementRange);
        $invalidRows += (int) $validated['invalid_rows'];
        $processingError = null;
        if ((int) $validated['total_rows'] === 0) {
            $processingError = ! empty($errors)
                ? (string) $errors[0]
                : 'We could not extract transactions from this file.';
            Log::warning('statement_ingestion_empty_result', [
                'errors' => $errors,
                'formats' => $formats,
            ]);
        }

        $confidence = $this->scoreConfidence(
            (int) $validated['total_rows'],
            $invalidRows,
            (int) $validated['flagged_rows']
        );

        $normalized = $this->normalizer->normalizeBankRows($validated['rows'], $confidence);
        $meta = [
            'opening_balance' => null,
            'closing_balance' => null,
            'balance_change' => null,
            'validation' => [
                'total_rows' => (int) $validated['total_rows'],
                'invalid_rows' => $invalidRows,
                'flagged_rows' => (int) $validated['flagged_rows'],
                'errors' => $errors,
            ],
            'review' => [
                'recommended' => $confidence < 78.0 || (int) $validated['flagged_rows'] > 0,
                'message' => $confidence < 78.0
                    ? 'Review recommended before saving.'
                    : null,
            ],
        ];

        $uniqueFormats = array_values(array_unique($formats));
        $format = count($uniqueFormats) === 1 ? $uniqueFormats[0] : 'mixed';

        $hasPdf = in_array('pdf', $uniqueFormats, true);
        $hasStructured = count(array_intersect(['csv', 'ofx'], $uniqueFormats)) > 0;
        $method = $hasPdf && $hasStructured
            ? 'mixed'
            : ($hasPdf ? 'ai_pdf' : 'structured');

        return [
            'transactions' => $normalized,
            'summary' => $meta,
            'source' => $format,
            'extraction_method' => $method,
            'extraction_confidence' => $this->confidenceLabel($confidence),
            'confidence_score' => $confidence,
            'flagged_rows' => (int) $validated['flagged_rows'],
            'total_rows' => (int) $validated['total_rows'],
            'raw_extraction_cache' => $this->truncateRawLog(implode("\n\n---\n\n", array_filter($rawLogs))),
            'processing_error' => $processingError,
        ];
    }

    /**
     * @return array{rows:array<int,array<string,mixed>>, raw_text:string, invalid_rows:int, statement_range:array{min:?string,max:?string}, error:?string}
     */
    private function processPdf(string $path, string $name): array
    {
        $tooling = $this->toolingAvailability();
        $rawText = $this->extractPdfText($path);
        $rawTextTrimmed = trim($rawText);
        $rawTextLength = mb_strlen($rawTextTrimmed);
        Log::info('statement_pdf_text_extraction', [
            'file' => $name,
            'text_length' => $rawTextLength,
            'tooling' => $tooling,
            'ai_enabled' => $this->aiExtractor->isEnabled(),
        ]);

        if ($rawTextLength < 180) {
            $ocrText = $this->extractPdfTextViaOcr($path);
            $ocrTrimmed = trim($ocrText);
            $ocrLength = mb_strlen($ocrTrimmed);
            Log::info('statement_pdf_ocr_extraction', [
                'file' => $name,
                'ocr_length' => $ocrLength,
            ]);

            if ($ocrLength > 0) {
                $rawText = trim($rawTextTrimmed === '' ? $ocrTrimmed : ($rawTextTrimmed."\n\n".$ocrTrimmed));
                $rawTextTrimmed = $rawText;
                $rawTextLength = mb_strlen($rawTextTrimmed);
            }
        }

        if ($rawTextLength === 0) {
            return [
                'rows' => [],
                'raw_text' => '',
                'invalid_rows' => 1,
                'statement_range' => ['min' => null, 'max' => null],
                'error' => 'Unable to read this statement. Try another file.',
            ];
        }

        $statementRange = $this->detectStatementRange($rawText);
        $aiError = null;

        if ($rawTextTrimmed !== '') {
            try {
                $aiResult = $this->aiExtractor->extractStatementTransactions($rawText);
                $rows = [];

                foreach ($aiResult['transactions'] as $item) {
                    if (! is_array($item)) {
                        continue;
                    }

                    $rows[] = [
                        'date' => $item['date'] ?? null,
                        'description' => $item['description'] ?? null,
                        'amount' => $item['amount'] ?? null,
                        'type' => $this->normalizeStatementType((string) ($item['type'] ?? 'debit')),
                        'include' => true,
                        'duplicate' => false,
                    ];
                }

                if (! empty($rows)) {
                    $validatedAi = $this->validateRows($rows, $statementRange);
                    if (($validatedAi['total_rows'] ?? 0) > 0) {
                        return [
                            'rows' => $validatedAi['rows'],
                            'raw_text' => $rawText,
                            'invalid_rows' => (int) ($validatedAi['invalid_rows'] ?? 0),
                            'statement_range' => $statementRange,
                            'error' => null,
                        ];
                    }

                    $aiError = "AI extraction returned malformed rows for {$name}.";
                } else {
                    $aiError = "AI extraction returned no transaction rows for {$name}.";
                }
            } catch (\Throwable $error) {
                Log::warning('statement_pdf_ai_extraction_failed', [
                    'file' => $name,
                    'message' => $error->getMessage(),
                    'ai_enabled' => $this->aiExtractor->isEnabled(),
                ]);
                $aiError = "AI extraction failed for {$name}. Review required.";
            }
        } else {
            $aiError = "No readable text found in {$name}.";
        }

        $fallbackRows = $this->parseWithPdfFallbackParser($path);
        if (! empty($fallbackRows)) {
            $fallbackRange = $this->rangeFromRows($fallbackRows);
            $statementRange = $this->mergeRange($statementRange, $fallbackRange);
            return [
                'rows' => $fallbackRows,
                'raw_text' => $rawText,
                'invalid_rows' => 0,
                'statement_range' => $statementRange,
                'error' => null,
            ];
        }

        if ($rawTextTrimmed !== '') {
            $rawTextRows = $this->parseWithRawTextFallbackParser($rawTextTrimmed);
            if (! empty($rawTextRows)) {
                $fallbackRange = $this->rangeFromRows($rawTextRows);
                $statementRange = $this->mergeRange($statementRange, $fallbackRange);
                return [
                    'rows' => $rawTextRows,
                    'raw_text' => $rawText,
                    'invalid_rows' => 0,
                    'statement_range' => $statementRange,
                    'error' => null,
                ];
            }

            $permissiveRows = $this->parseWithPermissiveLineFallback($rawTextTrimmed);
            if (! empty($permissiveRows)) {
                $fallbackRange = $this->rangeFromRows($permissiveRows);
                $statementRange = $this->mergeRange($statementRange, $fallbackRange);
                return [
                    'rows' => $permissiveRows,
                    'raw_text' => $rawText,
                    'invalid_rows' => 0,
                    'statement_range' => $statementRange,
                    'error' => null,
                ];
            }
        }

        $ocrText = $this->extractPdfTextViaOcr($path);
        $ocrTrimmed = trim($ocrText);
        if ($ocrTrimmed !== '') {
            $combinedRaw = trim($rawTextTrimmed === '' ? $ocrTrimmed : ($rawTextTrimmed."\n\n".$ocrTrimmed));
            $combinedRange = $this->mergeRange($statementRange, $this->detectStatementRange($ocrTrimmed));

            try {
                $ocrAi = $this->aiExtractor->extractStatementTransactions($ocrTrimmed);
                $ocrRows = [];
                foreach (($ocrAi['transactions'] ?? []) as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $ocrRows[] = [
                        'date' => $item['date'] ?? null,
                        'description' => $item['description'] ?? null,
                        'amount' => $item['amount'] ?? null,
                        'type' => $this->normalizeStatementType((string) ($item['type'] ?? 'debit')),
                        'include' => true,
                        'duplicate' => false,
                    ];
                }

                if (! empty($ocrRows)) {
                    $validatedOcrAi = $this->validateRows($ocrRows, $combinedRange);
                    if (($validatedOcrAi['total_rows'] ?? 0) > 0) {
                        return [
                            'rows' => $validatedOcrAi['rows'],
                            'raw_text' => $combinedRaw,
                            'invalid_rows' => (int) ($validatedOcrAi['invalid_rows'] ?? 0),
                            'statement_range' => $combinedRange,
                            'error' => null,
                        ];
                    }
                }
            } catch (\Throwable) {
                // Continue through deterministic OCR fallbacks.
            }

            $ocrTextRows = $this->parseWithRawTextFallbackParser($ocrTrimmed);
            if (! empty($ocrTextRows)) {
                return [
                    'rows' => $ocrTextRows,
                    'raw_text' => $combinedRaw,
                    'invalid_rows' => 0,
                    'statement_range' => $combinedRange,
                    'error' => null,
                ];
            }

            $ocrPermissiveRows = $this->parseWithPermissiveLineFallback($ocrTrimmed);
            if (! empty($ocrPermissiveRows)) {
                return [
                    'rows' => $ocrPermissiveRows,
                    'raw_text' => $combinedRaw,
                    'invalid_rows' => 0,
                    'statement_range' => $combinedRange,
                    'error' => null,
                ];
            }
        }

        return [
            'rows' => [],
            'raw_text' => $rawText,
            'invalid_rows' => 1,
            'statement_range' => $statementRange,
            'error' => $this->buildPdfFailureMessage($name, $aiError, $tooling),
        ];
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    private function parseWithPdfFallbackParser(string $path): array
    {
        try {
            $parsed = $this->pdfParser->parseDocument($path);
        } catch (\Throwable) {
            return [];
        }

        $transactions = $parsed['transactions'] ?? null;
        if (! is_array($transactions)) {
            return [];
        }

        $rows = [];
        foreach ($transactions as $item) {
            if (! is_array($item)) {
                continue;
            }

            $rows[] = [
                'date' => $item['date'] ?? null,
                'description' => $item['description'] ?? null,
                'amount' => $item['amount'] ?? null,
                'type' => $this->normalizeStatementType((string) ($item['type'] ?? 'debit')),
                'include' => true,
                'duplicate' => false,
                'flagged' => false,
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    private function parseWithRawTextFallbackParser(string $rawText): array
    {
        try {
            $parsed = $this->pdfParser->parseText($rawText);
        } catch (\Throwable) {
            return [];
        }

        if (! is_array($parsed) || empty($parsed)) {
            return [];
        }

        $rows = [];
        foreach ($parsed as $item) {
            if (! is_array($item)) {
                continue;
            }

            $rows[] = [
                'date' => $item['date'] ?? null,
                'description' => $item['description'] ?? null,
                'amount' => $item['amount'] ?? null,
                'type' => $this->normalizeStatementType((string) ($item['type'] ?? 'debit')),
                'include' => true,
                'duplicate' => false,
                'flagged' => false,
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    private function parseWithPermissiveLineFallback(string $text): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        if (empty($lines)) {
            return [];
        }

        $rows = [];
        $seen = [];
        $year = StatementParser::extractStatementYear($text) ?: (int) date('Y');
        $monthPattern = StatementParser::monthPattern();

        foreach ($lines as $line) {
            $line = preg_replace('/\s+/u', ' ', trim((string) $line)) ?? '';
            if ($line === '') {
                continue;
            }

            $dateToken = StatementParser::extractDateToken($line, $monthPattern);
            if (! $dateToken) {
                continue;
            }

            $date = StatementParser::parseDate($dateToken, $year);
            if ($date === null) {
                continue;
            }

            $amounts = StatementParser::extractAmounts($line);
            if (empty($amounts)) {
                continue;
            }

            $amountRaw = (string) end($amounts);
            $signedAmount = StatementParser::parseAmount($amountRaw);
            $amount = abs($signedAmount);
            if ($amount <= 0) {
                continue;
            }

            $description = StatementParser::extractDescription($line, $dateToken, $amounts);
            $description = StatementParser::sanitizeDescription($description);
            if ($description === '' || $this->isSummaryRow($description)) {
                continue;
            }

            $type = $this->normalizeStatementType(
                StatementParser::determineType($signedAmount, $description, $amountRaw, $line)
            );

            $key = strtolower($date.'|'.number_format($amount, 2, '.', '').'|'.$type.'|'.$description);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $rows[] = [
                'date' => $date,
                'description' => $description,
                'amount' => $amount,
                'type' => $type,
                'include' => true,
                'duplicate' => false,
                'flagged' => false,
            ];
        }

        return $rows;
    }

    private function extractPdfText(string $path): string
    {
        $pdftotext = trim((string) shell_exec('command -v pdftotext'));
        if ($pdftotext !== '') {
            $layout = escapeshellcmd($pdftotext).' -layout '.escapeshellarg($path).' - 2>/dev/null';
            $layoutText = trim((string) shell_exec($layout));
            if ($layoutText !== '') {
                return $layoutText;
            }

            $plain = escapeshellcmd($pdftotext).' '.escapeshellarg($path).' - 2>/dev/null';
            $plainText = trim((string) shell_exec($plain));
            if ($plainText !== '') {
                return $plainText;
            }
        }

        $streamText = $this->extractPdfTextFromStreams($path);
        if ($streamText !== '') {
            return $streamText;
        }

        $strings = trim((string) shell_exec('command -v strings'));
        if ($strings !== '') {
            $command = escapeshellcmd($strings).' -n 6 '.escapeshellarg($path).' 2>/dev/null';
            return trim((string) shell_exec($command));
        }

        return '';
    }

    private function extractPdfTextViaOcr(string $path): string
    {
        $pdftoppm = trim((string) shell_exec('command -v pdftoppm'));
        $tesseract = trim((string) shell_exec('command -v tesseract'));
        if ($pdftoppm === '' || $tesseract === '') {
            return '';
        }

        $tmpDir = storage_path('app/ocr/pdf-'.uniqid('', true));
        if (! is_dir($tmpDir)) {
            @mkdir($tmpDir, 0775, true);
        }

        $prefix = $tmpDir.'/page';
        $command = escapeshellcmd($pdftoppm)
            .' -f 1 -l 4 -r 220 -gray -png '
            .escapeshellarg($path).' '
            .escapeshellarg($prefix)
            .' >/dev/null 2>&1';
        shell_exec($command);

        $images = glob($tmpDir.'/page-*.png') ?: [];
        sort($images);
        $parts = [];

        foreach ($images as $imagePath) {
            $ocr = $this->runTesseractInline($tesseract, $imagePath);
            if ($ocr !== '') {
                $parts[] = $ocr;
            }
            @unlink($imagePath);
        }

        @rmdir($tmpDir);

        return trim(implode("\n\n", $parts));
    }

    private function runTesseractInline(string $tesseractPath, string $imagePath): string
    {
        if (! is_file($imagePath)) {
            return '';
        }

        $command = escapeshellcmd($tesseractPath)
            .' '.escapeshellarg($imagePath)
            .' stdout --oem 1 --psm 6 -l eng 2>/dev/null';

        return trim((string) shell_exec($command));
    }

    /**
     * @param array<int, array<string,mixed>> $rows
     * @param array{min:?string,max:?string} $statementRange
     * @return array{rows:array<int,array<string,mixed>>, invalid_rows:int, flagged_rows:int, total_rows:int}
     */
    private function validateRows(array $rows, array $statementRange): array
    {
        $cleanRows = [];
        $invalidRows = 0;
        $flaggedRows = 0;
        $seen = [];

        foreach ($rows as $row) {
            $date = StatementParser::parseDate((string) ($row['date'] ?? ''));
            $description = StatementParser::sanitizeDescription((string) ($row['description'] ?? ''));
            $amount = $row['amount'] ?? null;
            $amount = is_numeric($amount) ? abs((float) $amount) : null;
            $type = $this->normalizeStatementType((string) ($row['type'] ?? 'spending'));

            if ($date === null || $description === '' || $amount === null || $amount <= 0) {
                $invalidRows += 1;
                continue;
            }

            if ($this->isSummaryRow($description)) {
                $invalidRows += 1;
                continue;
            }

            $key = strtolower($date.'|'.number_format($amount, 2, '.', '').'|'.$type.'|'.$description);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $flagged = (bool) ($row['flagged'] ?? false);
            if ($amount > 100000) {
                $flagged = true;
            }

            if ($statementRange['min'] && strcmp($date, $statementRange['min']) < 0) {
                $flagged = true;
            }
            if ($statementRange['max'] && strcmp($date, $statementRange['max']) > 0) {
                $flagged = true;
            }

            if ($flagged) {
                $flaggedRows += 1;
            }

            $cleanRows[] = [
                'date' => $date,
                'description' => $description,
                'amount' => $amount,
                'type' => $type,
                'flagged' => $flagged,
                'include' => true,
                'duplicate' => false,
            ];
        }

        usort($cleanRows, fn ($a, $b) => strcmp((string) $a['date'], (string) $b['date']));

        return [
            'rows' => $cleanRows,
            'invalid_rows' => $invalidRows,
            'flagged_rows' => $flaggedRows,
            'total_rows' => count($cleanRows),
        ];
    }

    /**
     * @param array<int, array<string,mixed>> $rows
     * @return array{min:?string,max:?string}
     */
    private function rangeFromRows(array $rows): array
    {
        $dates = [];
        foreach ($rows as $row) {
            $date = StatementParser::parseDate((string) ($row['date'] ?? ''));
            if ($date !== null) {
                $dates[] = $date;
            }
        }

        if (empty($dates)) {
            return ['min' => null, 'max' => null];
        }

        sort($dates);

        return ['min' => $dates[0], 'max' => $dates[count($dates) - 1]];
    }

    /**
     * @return array{min:?string,max:?string}
     */
    private function detectStatementRange(string $rawText): array
    {
        $dates = [];
        preg_match_all('/\b(\d{1,2}[\/\-]\d{1,2}(?:[\/\-]\d{2,4})?|\d{4}-\d{1,2}-\d{1,2})\b/', $rawText, $matches);

        foreach (($matches[1] ?? []) as $token) {
            $parsed = StatementParser::parseDate((string) $token);
            if ($parsed !== null) {
                $dates[] = $parsed;
            }
        }

        if (empty($dates)) {
            return ['min' => null, 'max' => null];
        }

        sort($dates);

        return ['min' => $dates[0], 'max' => $dates[count($dates) - 1]];
    }

    /**
     * @param array{min:?string,max:?string} $current
     * @param array{min:?string,max:?string} $incoming
     * @return array{min:?string,max:?string}
     */
    private function mergeRange(array $current, array $incoming): array
    {
        $min = $current['min'];
        $max = $current['max'];

        if ($incoming['min'] !== null && ($min === null || strcmp($incoming['min'], $min) < 0)) {
            $min = $incoming['min'];
        }

        if ($incoming['max'] !== null && ($max === null || strcmp($incoming['max'], $max) > 0)) {
            $max = $incoming['max'];
        }

        return ['min' => $min, 'max' => $max];
    }

    private function normalizeStatementType(string $type): string
    {
        $lower = strtolower(trim($type));

        if (in_array($lower, ['credit', 'income', 'cr'], true)) {
            return 'income';
        }

        return 'spending';
    }

    private function isSummaryRow(string $description): bool
    {
        $text = strtolower($description);

        return str_contains($text, 'beginning balance')
            || str_contains($text, 'ending balance')
            || str_contains($text, 'opening balance')
            || str_contains($text, 'closing balance')
            || preg_match('/\btotal\b/', $text) === 1;
    }

    private function scoreConfidence(int $totalRows, int $invalidRows, int $flaggedRows): float
    {
        if ($totalRows <= 0) {
            return 0.0;
        }

        $inputRows = max(1, $totalRows + $invalidRows);
        $score = 100.0;

        $malformedRate = $invalidRows / $inputRows;
        $score -= $malformedRate * 45.0;

        if ($totalRows <= 5) {
            $score -= 12.0;
        }

        $flagRate = $flaggedRows / max(1, $totalRows);
        $score -= min(25.0, $flagRate * 40.0);

        if ($invalidRows === 0 && $totalRows > 5) {
            $score += 5.0;
        }

        return round(max(0.0, min(100.0, $score)), 2);
    }

    private function confidenceLabel(float $score): string
    {
        if ($score >= 90.0) {
            return 'high';
        }

        if ($score >= 75.0) {
            return 'medium';
        }

        return 'low';
    }

    private function truncateRawLog(string $raw): ?string
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        return mb_substr($trimmed, 0, 250000);
    }

    /**
     * Extracts likely text from PDF content streams without external binaries.
     */
    private function extractPdfTextFromStreams(string $path): string
    {
        $content = @file_get_contents($path);
        if (! is_string($content) || $content === '') {
            return '';
        }

        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $content, $matches);
        $streams = $matches[1] ?? [];
        if (empty($streams)) {
            return '';
        }

        $chunks = [];
        foreach ($streams as $rawStream) {
            $decoded = $this->decodePdfStreamData((string) $rawStream);
            if ($decoded === '') {
                continue;
            }

            $text = $this->extractPdfTextOperators($decoded);
            if ($text !== '') {
                $chunks[] = $text;
            }
        }

        $combined = trim(implode("\n", $chunks));
        if ($combined === '') {
            return '';
        }

        $combined = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $combined) ?? $combined;
        $combined = preg_replace('/\n{3,}/', "\n\n", $combined) ?? $combined;

        return trim($combined);
    }

    private function decodePdfStreamData(string $stream): string
    {
        $stream = trim($stream, "\r\n");
        if ($stream === '') {
            return '';
        }

        $candidates = [$stream];
        $inflateCandidates = [$stream, substr($stream, 2)];

        foreach ($inflateCandidates as $candidate) {
            if (! is_string($candidate) || $candidate === '') {
                continue;
            }
            $uncompressed = @gzuncompress($candidate);
            if (is_string($uncompressed) && $uncompressed !== '') {
                $candidates[] = $uncompressed;
            }
            $inflated = @gzinflate($candidate);
            if (is_string($inflated) && $inflated !== '') {
                $candidates[] = $inflated;
            }
        }

        foreach ($candidates as $candidate) {
            if (
                str_contains($candidate, 'BT')
                && str_contains($candidate, 'ET')
                && (str_contains($candidate, 'Tj') || str_contains($candidate, 'TJ'))
            ) {
                return $candidate;
            }
        }

        return '';
    }

    private function extractPdfTextOperators(string $stream): string
    {
        preg_match_all('/BT(.*?)ET/s', $stream, $blocks);
        $textBlocks = $blocks[1] ?? [];
        if (empty($textBlocks)) {
            return '';
        }

        $lines = [];
        foreach ($textBlocks as $block) {
            $parts = [];

            if (preg_match_all('/\((?:\\\\.|[^\\\\)])*\)/s', $block, $literalMatches)) {
                foreach ($literalMatches[0] as $literal) {
                    $decoded = $this->decodePdfLiteralString($literal);
                    if ($decoded !== '') {
                        $parts[] = $decoded;
                    }
                }
            }

            if (preg_match_all('/<([0-9A-Fa-f\s]+)>/s', $block, $hexMatches)) {
                foreach ($hexMatches[1] as $hex) {
                    $decoded = $this->decodePdfHexString((string) $hex);
                    if ($decoded !== '') {
                        $parts[] = $decoded;
                    }
                }
            }

            $line = trim(implode(' ', $parts));
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return trim(implode("\n", $lines));
    }

    private function decodePdfLiteralString(string $token): string
    {
        $value = substr($token, 1, -1);
        if ($value === false || $value === '') {
            return '';
        }

        $value = preg_replace("/\\\\\r?\n/", '', $value) ?? $value;
        $value = preg_replace_callback('/\\\\([0-7]{1,3})/', function ($matches) {
            return chr(octdec((string) $matches[1]) & 0xFF);
        }, $value) ?? $value;

        $value = str_replace(
            ['\\n', '\\r', '\\t', '\\b', '\\f', '\\(', '\\)', '\\\\'],
            ["\n", "\r", "\t", "\x08", "\x0C", '(', ')', '\\'],
            $value
        );

        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? $value;
        if ($value === '' || ! preg_match('/[A-Za-z0-9]/', $value)) {
            return '';
        }

        return $value;
    }

    private function decodePdfHexString(string $hex): string
    {
        $hex = preg_replace('/\s+/', '', $hex) ?? $hex;
        if ($hex === '') {
            return '';
        }

        if (strlen($hex) % 2 === 1) {
            $hex = substr($hex, 0, -1);
        }

        $binary = @hex2bin($hex);
        if (! is_string($binary) || $binary === '') {
            return '';
        }

        if (str_starts_with($binary, "\xFE\xFF")) {
            $binary = mb_convert_encoding(substr($binary, 2), 'UTF-8', 'UTF-16BE');
        } elseif (str_contains($binary, "\x00")) {
            $binary = mb_convert_encoding($binary, 'UTF-8', 'UTF-16BE');
        }

        $binary = preg_replace('/\s+/u', ' ', trim($binary)) ?? $binary;
        if ($binary === '' || ! preg_match('/[A-Za-z0-9]/', $binary)) {
            return '';
        }

        return $binary;
    }

    /**
     * @return array{pdftotext:bool,pdftoppm:bool,tesseract:bool}
     */
    private function toolingAvailability(): array
    {
        return [
            'pdftotext' => trim((string) shell_exec('command -v pdftotext')) !== '',
            'pdftoppm' => trim((string) shell_exec('command -v pdftoppm')) !== '',
            'tesseract' => trim((string) shell_exec('command -v tesseract')) !== '',
        ];
    }

    /**
     * @param array{pdftotext:bool,pdftoppm:bool,tesseract:bool} $tooling
     */
    private function buildPdfFailureMessage(string $name, ?string $aiError, array $tooling): string
    {
        if ($aiError !== null && $aiError !== '') {
            return $aiError;
        }

        if (! $this->aiExtractor->isEnabled()) {
            return 'AI parsing is not configured for this environment and the statement could not be parsed automatically.';
        }

        if (! $tooling['pdftotext'] && (! $tooling['pdftoppm'] || ! $tooling['tesseract'])) {
            return "Unable to parse {$name}. PDF text tooling is unavailable on this server.";
        }

        return 'We could not extract transactions from this file.';
    }
}
