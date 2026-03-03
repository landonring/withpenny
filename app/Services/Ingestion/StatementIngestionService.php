<?php

namespace App\Services\Ingestion;

use App\Services\Statements\CsvStatementParser;
use App\Services\Statements\StatementParser;

class StatementIngestionService
{
    public function __construct(
        private readonly CsvStatementParser $csvParser,
        private readonly OfxStatementParser $ofxParser,
        private readonly AiStructuredExtractionService $aiExtractor,
        private readonly TransactionNormalizationService $normalizer,
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
        ];
    }

    /**
     * @return array{rows:array<int,array<string,mixed>>, raw_text:string, invalid_rows:int, statement_range:array{min:?string,max:?string}, error:?string}
     */
    private function processPdf(string $path, string $name): array
    {
        $rawText = $this->extractPdfText($path);
        if (trim($rawText) === '') {
            return [
                'rows' => [],
                'raw_text' => '',
                'invalid_rows' => 1,
                'statement_range' => ['min' => null, 'max' => null],
                'error' => "No readable text found in {$name}.",
            ];
        }

        $statementRange = $this->detectStatementRange($rawText);

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

            return [
                'rows' => $rows,
                'raw_text' => $rawText,
                'invalid_rows' => 0,
                'statement_range' => $statementRange,
                'error' => null,
            ];
        } catch (\Throwable $error) {
            return [
                'rows' => [],
                'raw_text' => $rawText,
                'invalid_rows' => 1,
                'statement_range' => $statementRange,
                'error' => "AI extraction failed for {$name}. Review required.",
            ];
        }
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

        $strings = trim((string) shell_exec('command -v strings'));
        if ($strings !== '') {
            $command = escapeshellcmd($strings).' -n 6 '.escapeshellarg($path).' 2>/dev/null';
            return trim((string) shell_exec($command));
        }

        return '';
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
}
