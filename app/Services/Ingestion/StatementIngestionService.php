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
                'duplicate_rows' => (int) ($validated['duplicate_rows'] ?? 0),
                'rejected_reason_counts' => $validated['rejected_reason_counts'] ?? [],
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
            $aiExtraction = $this->extractRowsViaAi($rawTextTrimmed, $statementRange, $name, 'pdf_text');
            if (! empty($aiExtraction['rows'])) {
                return [
                    'rows' => $aiExtraction['rows'],
                    'raw_text' => $rawText,
                    'invalid_rows' => (int) ($aiExtraction['invalid_rows'] ?? 0),
                    'statement_range' => $statementRange,
                    'error' => null,
                ];
            }

            $aiError = $aiExtraction['error'] ?? null;
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
            $permissiveRows = $this->parseWithPermissiveLineFallback($rawTextTrimmed);
            $bestTextRows = count($permissiveRows) > count($rawTextRows) ? $permissiveRows : $rawTextRows;

            if (! empty($bestTextRows)) {
                $fallbackRange = $this->rangeFromRows($bestTextRows);
                $statementRange = $this->mergeRange($statementRange, $fallbackRange);
                return [
                    'rows' => $bestTextRows,
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

            $ocrAiExtraction = $this->extractRowsViaAi($ocrTrimmed, $combinedRange, $name, 'ocr_text');
            if (! empty($ocrAiExtraction['rows'])) {
                return [
                    'rows' => $ocrAiExtraction['rows'],
                    'raw_text' => $combinedRaw,
                    'invalid_rows' => (int) ($ocrAiExtraction['invalid_rows'] ?? 0),
                    'statement_range' => $combinedRange,
                    'error' => null,
                ];
            }
            if ($aiError === null && ! empty($ocrAiExtraction['error'])) {
                $aiError = (string) $ocrAiExtraction['error'];
            }

            $ocrTextRows = $this->parseWithRawTextFallbackParser($ocrTrimmed);
            $ocrPermissiveRows = $this->parseWithPermissiveLineFallback($ocrTrimmed);
            $bestOcrRows = count($ocrPermissiveRows) > count($ocrTextRows) ? $ocrPermissiveRows : $ocrTextRows;

            if (! empty($bestOcrRows)) {
                return [
                    'rows' => $bestOcrRows,
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
     * @param array{min:?string,max:?string} $statementRange
     * @return array{rows:array<int,array<string,mixed>>, invalid_rows:int, error:?string}
     */
    private function extractRowsViaAi(string $text, array $statementRange, string $name, string $context): array
    {
        try {
            $firstPass = $this->runAiExtractionPass($text, $statementRange);
            $this->logAiPassResult($name, $context, 'primary', $firstPass);
            if ($firstPass['status'] === 'success') {
                return [
                    'rows' => $firstPass['rows'],
                    'invalid_rows' => $firstPass['invalid_rows'],
                    'error' => null,
                ];
            }

            $aiError = $firstPass['status'] === 'invalid'
                ? "AI extraction rows failed validation for {$name}."
                : "AI extraction returned no transaction rows for {$name}.";
        } catch (\Throwable $error) {
            Log::warning('statement_pdf_ai_extraction_failed', [
                'file' => $name,
                'context' => $context,
                'stage' => 'primary',
                'message' => $error->getMessage(),
                'ai_enabled' => $this->aiExtractor->isEnabled(),
            ]);

            return [
                'rows' => [],
                'invalid_rows' => 0,
                'error' => "AI extraction failed for {$name}. Review required.",
            ];
        }

        $focusedText = $this->buildFocusedAiText($text);
        if ($focusedText === '') {
            return [
                'rows' => [],
                'invalid_rows' => 0,
                'error' => $aiError,
            ];
        }

        Log::info('statement_pdf_ai_focus_retry', [
            'file' => $name,
            'context' => $context,
            'focused_length' => mb_strlen($focusedText),
        ]);

        try {
            $focusedPass = $this->runAiExtractionPass($focusedText, $statementRange);
            $this->logAiPassResult($name, $context, 'focused_retry', $focusedPass);
            if ($focusedPass['status'] === 'success') {
                Log::info('statement_pdf_ai_focus_retry_success', [
                    'file' => $name,
                    'context' => $context,
                    'transactions' => count($focusedPass['rows']),
                ]);

                return [
                    'rows' => $focusedPass['rows'],
                    'invalid_rows' => $focusedPass['invalid_rows'],
                    'error' => null,
                ];
            }

            return [
                'rows' => [],
                'invalid_rows' => 0,
                'error' => $focusedPass['status'] === 'invalid'
                    ? "AI extraction rows failed validation for {$name}."
                    : $aiError,
            ];
        } catch (\Throwable $error) {
            Log::warning('statement_pdf_ai_extraction_failed', [
                'file' => $name,
                'context' => $context,
                'stage' => 'focused_retry',
                'message' => $error->getMessage(),
                'ai_enabled' => $this->aiExtractor->isEnabled(),
            ]);

            return [
                'rows' => [],
                'invalid_rows' => 0,
                'error' => $aiError,
            ];
        }
    }

    /**
     * @param array{min:?string,max:?string} $statementRange
     * @return array{
     *   status:'success'|'empty'|'invalid',
     *   rows:array<int,array<string,mixed>>,
     *   invalid_rows:int,
     *   mapped_rows:int,
     *   raw_rows:int,
     *   duplicate_rows:int,
     *   rejected_reason_counts:array<string,int>,
     *   rejected_samples:array<int,array<string,mixed>>
     * }
     */
    private function runAiExtractionPass(string $text, array $statementRange): array
    {
        $aiResult = $this->aiExtractor->extractStatementTransactions($text);
        $transactions = $aiResult['transactions'] ?? [];
        $transactions = is_array($transactions) ? $transactions : [];
        $rows = $this->mapAiTransactionsToRows($transactions);
        if (empty($rows)) {
            return [
                'status' => 'empty',
                'rows' => [],
                'invalid_rows' => 0,
                'mapped_rows' => 0,
                'raw_rows' => count($transactions),
                'duplicate_rows' => 0,
                'rejected_reason_counts' => [],
                'rejected_samples' => [],
            ];
        }

        $validated = $this->validateRows($rows, $statementRange);
        if (($validated['total_rows'] ?? 0) <= 0) {
            return [
                'status' => 'invalid',
                'rows' => [],
                'invalid_rows' => (int) ($validated['invalid_rows'] ?? 0),
                'mapped_rows' => count($rows),
                'raw_rows' => count($transactions),
                'duplicate_rows' => (int) ($validated['duplicate_rows'] ?? 0),
                'rejected_reason_counts' => $validated['rejected_reason_counts'] ?? [],
                'rejected_samples' => $validated['rejected_samples'] ?? [],
            ];
        }

        return [
            'status' => 'success',
            'rows' => $validated['rows'],
            'invalid_rows' => (int) ($validated['invalid_rows'] ?? 0),
            'mapped_rows' => count($rows),
            'raw_rows' => count($transactions),
            'duplicate_rows' => (int) ($validated['duplicate_rows'] ?? 0),
            'rejected_reason_counts' => $validated['rejected_reason_counts'] ?? [],
            'rejected_samples' => $validated['rejected_samples'] ?? [],
        ];
    }

    /**
     * @param array<int,mixed> $transactions
     * @return array<int, array<string,mixed>>
     */
    private function mapAiTransactionsToRows(array $transactions): array
    {
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

    private function buildFocusedAiText(string $text): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        if (empty($lines)) {
            return '';
        }

        $monthPattern = StatementParser::monthPattern();
        $candidateIndexes = [];
        $dateIndexes = [];

        foreach ($lines as $index => $rawLine) {
            $line = preg_replace('/\s+/u', ' ', trim((string) $rawLine)) ?? '';
            if ($line === '') {
                continue;
            }

            $hasDate = $this->extractDateTokenPermissive($line, $monthPattern) !== null
                || preg_match('/\b\d{1,2}[\/\-]\d{1,2}(?:[\/\-]\d{2,4})?\b/', $line) === 1;
            $hasAmount = ! empty(StatementParser::extractAmounts($line));
            $hasTxnKeyword = preg_match('/\b(debit|credit|purchase|payment|deposit|withdrawal|pos|ach|atm|transfer|check|card|posted)\b/i', $line) === 1;

            if ($hasDate) {
                $dateIndexes[] = $index;
            }

            if (($hasDate && $hasAmount) || ($hasAmount && $hasTxnKeyword)) {
                $candidateIndexes[$index] = true;
                for ($offset = 1; $offset <= 2; $offset++) {
                    $candidateIndexes[$index - $offset] = true;
                    $candidateIndexes[$index + $offset] = true;
                }
            }
        }

        foreach ($dateIndexes as $position => $startIndex) {
            $next = $dateIndexes[$position + 1] ?? count($lines);
            $end = min($next - 1, $startIndex + 6);
            for ($idx = max(0, $startIndex - 1); $idx <= $end; $idx++) {
                $candidateIndexes[$idx] = true;
            }
        }

        if (empty($candidateIndexes)) {
            return '';
        }

        ksort($candidateIndexes);
        $focusedLines = [];
        foreach (array_keys($candidateIndexes) as $index) {
            if (! isset($lines[$index])) {
                continue;
            }

            $line = preg_replace('/\s+/u', ' ', trim((string) $lines[$index])) ?? '';
            if ($line !== '') {
                $focusedLines[] = $line;
            }
        }

        $focusedLines = array_values(array_unique($focusedLines));
        if (empty($focusedLines)) {
            return '';
        }

        return implode("\n", array_slice($focusedLines, 0, 260));
    }

    /**
     * @param array{
     *   status:string,
     *   mapped_rows?:int,
     *   raw_rows?:int,
     *   invalid_rows?:int,
     *   duplicate_rows?:int,
     *   rejected_reason_counts?:array<string,int>,
     *   rejected_samples?:array<int,array<string,mixed>>
     * } $pass
     */
    private function logAiPassResult(string $name, string $context, string $stage, array $pass): void
    {
        $payload = [
            'file' => $name,
            'context' => $context,
            'stage' => $stage,
            'status' => $pass['status'] ?? 'unknown',
            'raw_rows' => (int) ($pass['raw_rows'] ?? 0),
            'mapped_rows' => (int) ($pass['mapped_rows'] ?? 0),
            'valid_rows' => count($pass['rows'] ?? []),
            'invalid_rows' => (int) ($pass['invalid_rows'] ?? 0),
            'duplicate_rows' => (int) ($pass['duplicate_rows'] ?? 0),
        ];

        if (($pass['status'] ?? null) === 'empty') {
            Log::warning('statement_pdf_ai_empty_result', $payload);
            return;
        }

        if (($pass['status'] ?? null) === 'invalid') {
            $payload['rejected_reason_counts'] = $pass['rejected_reason_counts'] ?? [];
            $payload['rejected_samples'] = $pass['rejected_samples'] ?? [];
            Log::warning('statement_pdf_ai_validation_rejected', $payload);
            return;
        }

        if ((int) ($pass['invalid_rows'] ?? 0) > 0) {
            $payload['rejected_reason_counts'] = $pass['rejected_reason_counts'] ?? [];
        }

        Log::info('statement_pdf_ai_pass_success', $payload);
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

        $chunkedRows = $this->parseWithChunkedPermissiveFallback($lines, $year, $monthPattern);
        if (count($chunkedRows) > count($rows)) {
            return $chunkedRows;
        }

        if (empty($rows) && ! empty($chunkedRows)) {
            return $chunkedRows;
        }

        return $rows;
    }

    /**
     * Recover transactions from heavily fragmented PDF text where transaction fields are spread across lines.
     *
     * @param array<int, string> $rawLines
     * @return array<int, array<string,mixed>>
     */
    private function parseWithChunkedPermissiveFallback(array $rawLines, int $year, string $monthPattern): array
    {
        $lines = [];
        $splitPattern = '/(.)(?=(?:'.$monthPattern.'\s*\d{1,2}|\d{1,2}[\/-]\d{1,2}(?:[\/-]\d{2,4})?|(?:19|20)\d{2}[01]\d[0-3]\d)\b)/i';
        foreach ($rawLines as $line) {
            $line = preg_replace('/\s+/u', ' ', trim((string) $line)) ?? '';
            if ($line === '') {
                continue;
            }

            $expanded = preg_replace($splitPattern, "$1\n", $line) ?? $line;
            $parts = preg_split('/\n+/', $expanded) ?: [];
            foreach ($parts as $part) {
                $part = preg_replace('/\s+/u', ' ', trim((string) $part)) ?? '';
                if ($part !== '') {
                    $lines[] = $part;
                }
            }
        }

        if (empty($lines)) {
            return [];
        }

        $dateIndexes = [];
        foreach ($lines as $index => $line) {
            if ($this->extractDateTokenPermissive($line, $monthPattern) !== null) {
                $dateIndexes[] = $index;
            }
        }

        if (empty($dateIndexes)) {
            return [];
        }

        $rows = [];
        $seen = [];
        foreach ($dateIndexes as $pointer => $startIndex) {
            $nextDateIndex = $dateIndexes[$pointer + 1] ?? count($lines);
            $endIndex = min($nextDateIndex - 1, $startIndex + 10);
            if ($endIndex < $startIndex) {
                $endIndex = $startIndex;
            }

            $chunkParts = array_slice($lines, $startIndex, ($endIndex - $startIndex) + 1);
            if (empty($chunkParts)) {
                continue;
            }

            $chunk = trim(implode(' ', $chunkParts));
            if ($chunk === '') {
                continue;
            }

            $dateToken = $this->extractDateTokenPermissive($chunkParts[0], $monthPattern)
                ?? $this->extractDateTokenPermissive($chunk, $monthPattern);
            if (! $dateToken) {
                continue;
            }

            $date = StatementParser::parseDate($dateToken, $year);
            if ($date === null) {
                $date = $this->parseCompactDateToken($dateToken);
            }
            if ($date === null) {
                continue;
            }

            $amounts = StatementParser::extractAmounts($chunk);
            if (empty($amounts)) {
                continue;
            }

            $amountRaw = StatementParser::pickLikelyAmountFromLine($chunk, $amounts)
                ?? StatementParser::pickLikelyAmount($amounts);
            if (! $amountRaw) {
                continue;
            }

            $signedAmount = StatementParser::parseAmount($amountRaw);
            $amount = abs($signedAmount);
            if ($amount <= 0) {
                continue;
            }

            $description = StatementParser::extractDescription($chunk, $dateToken, $amounts);
            $description = StatementParser::sanitizeDescription($this->cleanChunkDescription($description));
            if ($description === '' || $this->isSummaryRow($description)) {
                continue;
            }

            $type = $this->normalizeStatementType(
                StatementParser::determineType($signedAmount, $description, $amountRaw, $chunk)
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
     * @return array{
     *   rows:array<int,array<string,mixed>>,
     *   invalid_rows:int,
     *   flagged_rows:int,
     *   total_rows:int,
     *   duplicate_rows:int,
     *   rejected_reason_counts:array<string,int>,
     *   rejected_samples:array<int,array<string,mixed>>
     * }
     */
    private function validateRows(array $rows, array $statementRange): array
    {
        $cleanRows = [];
        $invalidRows = 0;
        $flaggedRows = 0;
        $duplicateRows = 0;
        $seen = [];
        $rejectedReasonCounts = [];
        $rejectedSamples = [];

        foreach ($rows as $row) {
            $description = StatementParser::sanitizeDescription((string) ($row['description'] ?? ''));
            if ($description === '') {
                $invalidRows += 1;
                $this->trackRejectedRow($rejectedReasonCounts, $rejectedSamples, 'missing_description', $row);
                continue;
            }

            if ($this->isSummaryRow($description)) {
                $invalidRows += 1;
                $this->trackRejectedRow($rejectedReasonCounts, $rejectedSamples, 'summary_row', $row);
                continue;
            }

            $dateInput = trim((string) ($row['date'] ?? ''));
            $date = StatementParser::parseDate($dateInput);
            if ($date === null) {
                $invalidRows += 1;
                $this->trackRejectedRow($rejectedReasonCounts, $rejectedSamples, 'invalid_date', $row);
                continue;
            }

            $amountInfo = $this->normalizeAmountValue($row['amount'] ?? null);
            if ($amountInfo === null || $amountInfo['absolute'] <= 0) {
                $invalidRows += 1;
                $this->trackRejectedRow($rejectedReasonCounts, $rejectedSamples, 'invalid_amount', $row);
                continue;
            }

            $amount = $amountInfo['absolute'];
            $signedAmount = $amountInfo['signed'];
            $rawAmount = $amountInfo['raw'];
            $type = $this->resolveRowType(
                $row['type'] ?? null,
                $signedAmount,
                $description,
                $rawAmount
            );

            $key = strtolower($date.'|'.number_format($amount, 2, '.', '').'|'.$type.'|'.$description);
            if (isset($seen[$key])) {
                $duplicateRows += 1;
                $this->trackRejectedRow($rejectedReasonCounts, $rejectedSamples, 'duplicate', $row);
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
            'duplicate_rows' => $duplicateRows,
            'rejected_reason_counts' => $rejectedReasonCounts,
            'rejected_samples' => $rejectedSamples,
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

        if (in_array($lower, ['credit', 'income', 'cr', 'deposit', 'inflow'], true)) {
            return 'income';
        }

        return 'spending';
    }

    /**
     * @return array{absolute:float,signed:float,raw:?string}|null
     */
    private function normalizeAmountValue(mixed $value): ?array
    {
        if (is_int($value) || is_float($value)) {
            $signed = (float) $value;
            if (! is_finite($signed) || abs($signed) <= 0) {
                return null;
            }

            return [
                'absolute' => abs($signed),
                'signed' => $signed,
                'raw' => (string) $value,
            ];
        }

        if (! is_string($value)) {
            return null;
        }

        $raw = trim($value);
        if ($raw === '') {
            return null;
        }

        $signed = StatementParser::parseAmount($raw);
        if (! is_finite($signed) || abs($signed) <= 0) {
            return null;
        }

        return [
            'absolute' => abs($signed),
            'signed' => $signed,
            'raw' => $raw,
        ];
    }

    private function resolveRowType(mixed $typeValue, float $signedAmount, string $description, ?string $rawAmount): string
    {
        $type = strtolower(trim((string) $typeValue));
        if ($type !== '') {
            if (in_array($type, ['credit', 'income', 'cr', 'deposit'], true)) {
                return 'income';
            }
            if (in_array($type, ['debit', 'spending', 'expense', 'dr', 'withdrawal'], true)) {
                return 'spending';
            }
        }

        $derived = StatementParser::determineType($signedAmount, $description, $rawAmount, $description);

        return $this->normalizeStatementType($derived);
    }

    private function isSummaryRow(string $description): bool
    {
        $text = strtolower(preg_replace('/\s+/', ' ', $description) ?? $description);

        foreach ([
            'beginning balance',
            'ending balance',
            'opening balance',
            'closing balance',
            'available balance',
            'previous balance',
            'new balance',
            'statement balance',
            'balance forward',
            'daily balance',
        ] as $phrase) {
            if (str_contains($text, $phrase)) {
                return true;
            }
        }

        if (preg_match('/^grand total\b/', $text) === 1) {
            return true;
        }
        if (preg_match('/^(?:total|subtotal)\s+(?:debits?|credits?|withdrawals?|deposits?|fees?|charges?|payments?|transactions?|amount|balance)\b/', $text) === 1) {
            return true;
        }
        if (preg_match('/\b(?:credits?|debits?)\s+total\b/', $text) === 1) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string,int> $counts
     * @param array<int,array<string,mixed>> $samples
     * @param array<string,mixed> $row
     */
    private function trackRejectedRow(array &$counts, array &$samples, string $reason, array $row): void
    {
        $counts[$reason] = (int) ($counts[$reason] ?? 0) + 1;

        if (count($samples) >= 6) {
            return;
        }

        $samples[] = [
            'reason' => $reason,
            'date' => $row['date'] ?? null,
            'description' => mb_substr((string) ($row['description'] ?? ''), 0, 140),
            'amount' => is_scalar($row['amount'] ?? null) ? (string) $row['amount'] : null,
            'type' => is_scalar($row['type'] ?? null) ? (string) $row['type'] : null,
        ];
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
        if (! $this->aiExtractor->isEnabled()) {
            return 'AI parsing is not configured for this environment and the statement could not be parsed automatically.';
        }

        if ($aiError !== null && $aiError !== '') {
            return $aiError;
        }

        if (! $tooling['pdftotext'] && (! $tooling['pdftoppm'] || ! $tooling['tesseract'])) {
            return "Unable to parse {$name}. PDF text tooling is unavailable on this server.";
        }

        return 'We could not extract transactions from this file.';
    }

    private function extractDateTokenPermissive(string $text, string $monthPattern): ?string
    {
        $token = StatementParser::extractDateToken($text, $monthPattern);
        if ($token !== null) {
            return $token;
        }

        if (preg_match('/\b((?:19|20)\d{2}[01]\d[0-3]\d)\b/', $text, $matches)) {
            return (string) $matches[1];
        }

        return null;
    }

    private function parseCompactDateToken(string $token): ?string
    {
        $token = trim($token);
        if (! preg_match('/^\d{8}$/', $token)) {
            return null;
        }

        if (preg_match('/^(19|20)\d{6}$/', $token) === 1) {
            $date = \DateTime::createFromFormat('Ymd', $token);
            return $date?->format('Y-m-d');
        }

        return null;
    }

    private function cleanChunkDescription(string $description): string
    {
        $description = preg_replace('/\b(posted|posting\s+date|transaction\s+date)\b[:\s-]*/i', '', $description) ?? $description;
        $description = preg_replace('/\b(debit|credit|dr|cr)\b/i', '', $description) ?? $description;
        $description = preg_replace('/\s+/u', ' ', trim($description)) ?? trim($description);

        return $description;
    }
}
