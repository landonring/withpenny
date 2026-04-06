<?php

namespace App\Services\Statements;

use App\Models\BankStatementImport;
use App\Services\Ingestion\AiStructuredExtractionService;
use App\Services\Ingestion\StatementIngestionService;
use App\Services\Ingestion\TransactionNormalizationService;
use Illuminate\Support\Facades\Storage;

class StatementUploadPipelineService
{
    public function __construct(
        private readonly PdfTextExtractor $extractor,
        private readonly StatementTextNormalizer $normalizer,
        private readonly GenericStatementTransactionParser $parser,
        private readonly TransactionNormalizationService $transactionNormalizer,
        private readonly AiStructuredExtractionService $ai,
        private readonly StatementIngestionService $legacyIngestion,
    )
    {
    }

    public function begin(BankStatementImport $upload): void
    {
        $this->mergeMeta($upload, [
            'progress' => [],
            'analysis' => [
                'raw_text_length' => 0,
                'normalized_line_count' => 0,
                'statement_period' => ['start' => null, 'end' => null],
                'queued_file_entries' => $this->queuedFiles($upload),
                'ai_fallback_used' => false,
            ],
        ]);

        $upload->forceFill([
            'status' => 'processing',
            'processing_status' => 'processing',
            'processing_error' => null,
            'processing_started_at' => $upload->processing_started_at ?? now(),
            'processing_completed_at' => null,
        ])->save();

        $this->appendProgress($upload, 'process', 'We are analyzing your statement.');
    }

    public function processLegacyStructuredFiles(BankStatementImport $upload): void
    {
        $files = [];
        foreach ($this->queuedFiles($upload) as $entry) {
            $storagePath = (string) ($entry['storage_path'] ?? '');
            if ($storagePath === '') {
                continue;
            }

            $files[] = [
                'name' => (string) ($entry['name'] ?? basename($storagePath)),
                'mime' => $entry['mime'] ?? null,
                'path' => Storage::disk('local')->path($storagePath),
            ];
        }

        $result = $this->legacyIngestion->processFiles($files);

        $this->complete(
            $upload,
            $result['transactions'] ?? [],
            (float) ($result['confidence_score'] ?? 0.0),
            [
                'review' => $result['summary']['review'] ?? null,
                'validation' => $result['summary']['validation'] ?? null,
                'legacy' => true,
            ],
            (string) ($result['extraction_method'] ?? 'structured'),
            (bool) (($result['extraction_method'] ?? '') === 'ai_pdf')
        );
    }

    public function extractText(BankStatementImport $upload): string
    {
        $chunks = [];
        $method = 'unavailable';
        $ocrUsed = false;

        foreach ($this->queuedFiles($upload) as $entry) {
            $storagePath = (string) ($entry['storage_path'] ?? '');
            if ($storagePath === '') {
                continue;
            }

            $path = Storage::disk('local')->path($storagePath);
            if (! is_file($path)) {
                continue;
            }

            $result = $this->extractor->extract($path);
            $text = trim((string) ($result['text'] ?? ''));
            if ($text !== '') {
                $chunks[] = $text;
            }

            $method = (string) ($result['method'] ?? $method);
            $ocrUsed = $ocrUsed || (bool) ($result['ocr_used'] ?? false);
        }

        $rawText = trim(implode("\n\n", $chunks));
        $analysis = $this->analysisMeta($upload);
        $analysis['raw_text_length'] = mb_strlen($rawText);
        $analysis['extract_method'] = $method;
        $analysis['ocr_used'] = $ocrUsed;
        $this->mergeMeta($upload, ['analysis' => $analysis]);

        $upload->forceFill([
            'raw_extraction_cache' => $rawText,
            'extraction_method' => $method,
            'processing_error' => null,
        ])->save();

        $this->appendProgress($upload, 'extract_text', 'Extracted text from the uploaded PDF.');

        return $rawText;
    }

    /**
     * @return array{lines:array<int,string>,statement_period:array{start:?string,end:?string}}
     */
    public function normalizeText(BankStatementImport $upload): array
    {
        $normalized = $this->normalizer->normalize((string) ($upload->raw_extraction_cache ?? ''));
        $lines = array_values($normalized['lines'] ?? []);
        $analysis = $this->analysisMeta($upload);
        $analysis['normalized_lines'] = $lines;
        $analysis['normalized_line_count'] = count($lines);
        $analysis['statement_period'] = $normalized['statement_period'] ?? ['start' => null, 'end' => null];
        $this->mergeMeta($upload, ['analysis' => $analysis]);

        $this->appendProgress($upload, 'normalize_text', 'Cleaned the extracted text into transaction-ready lines.');

        return [
            'lines' => $lines,
            'statement_period' => $analysis['statement_period'],
        ];
    }

    /**
     * @return array{transactions:array<int,array<string,mixed>>,confidence_score:float,needs_ai:bool}
     */
    public function parseTransactions(BankStatementImport $upload): array
    {
        $analysis = $this->analysisMeta($upload);
        $lines = array_values($analysis['normalized_lines'] ?? []);
        $statementPeriod = $analysis['statement_period'] ?? ['start' => null, 'end' => null];
        $parsed = $this->parser->parse($lines, $statementPeriod);
        $normalized = $this->transactionNormalizer->normalizeBankRows($parsed, $this->score(count($parsed), count($lines)));
        $confidence = $this->score(count($normalized), count($lines));
        $needsAi = $confidence < 0.6 || count($normalized) < 5;

        $analysis['parsed_transactions'] = count($normalized);
        $analysis['confidence_score'] = $confidence;
        $analysis['needs_ai'] = $needsAi;
        $analysis['heuristic_transactions'] = $normalized;
        $this->mergeMeta($upload, ['analysis' => $analysis]);

        $upload->forceFill([
            'transactions' => $normalized,
            'confidence_score' => $confidence,
            'detected_transactions' => count($normalized),
            'flagged_rows' => max(0, count($lines) - count($normalized)),
            'total_rows' => count($lines),
        ])->save();

        $this->appendProgress($upload, 'parse_transactions', 'Parsed candidate transactions from the cleaned text.');

        return [
            'transactions' => $normalized,
            'confidence_score' => $confidence,
            'needs_ai' => $needsAi,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function runAiFallback(BankStatementImport $upload): array
    {
        $analysis = $this->analysisMeta($upload);
        $lines = array_values($analysis['normalized_lines'] ?? []);
        $statementPeriod = $analysis['statement_period'] ?? ['start' => null, 'end' => null];
        $transactions = $this->ai->extractUniversalStatementTransactions($lines, $statementPeriod);

        if ($transactions === []) {
            $analysis['ai_fallback_used'] = false;
            $this->mergeMeta($upload, ['analysis' => $analysis]);

            return array_values($upload->transactions ?? []);
        }

        $normalized = $this->transactionNormalizer->normalizeBankRows($transactions, 1.0);
        $analysis['ai_fallback_used'] = true;
        $analysis['parsed_transactions'] = count($normalized);
        $analysis['confidence_score'] = 1.0;
        $analysis['needs_ai'] = false;
        $this->mergeMeta($upload, ['analysis' => $analysis]);

        $upload->forceFill([
            'transactions' => $normalized,
            'confidence_score' => 1.0,
            'ai_fallback_used' => true,
            'detected_transactions' => count($normalized),
        ])->save();

        $this->appendProgress($upload, 'ai_parse_fallback', 'Applied an additional parsing pass to improve the transaction list.');

        return $normalized;
    }

    /**
     * @param array<int,array<string,mixed>> $transactions
     * @param array<string,mixed> $extraMeta
     */
    public function complete(
        BankStatementImport $upload,
        array $transactions,
        float $confidenceScore,
        array $extraMeta = [],
        string $extractionMethod = 'generic_pdf',
        bool $aiFallbackUsed = false,
    ): void {
        $analysis = $this->analysisMeta($upload);
        $statementPeriod = $analysis['statement_period'] ?? ['start' => null, 'end' => null];

        $reviewMessage = count($transactions) > 0
            ? null
            : 'We could not extract transactions from this file.';

        $meta = array_merge($upload->meta ?? [], $extraMeta, [
            'review' => [
                'recommended' => $confidenceScore < 0.6,
                'message' => $reviewMessage,
            ],
        ]);

        $status = count($transactions) > 0 ? 'completed' : 'failed';
        $upload->forceFill([
            'transactions' => $transactions,
            'meta' => $meta,
            'status' => $status,
            'processing_status' => $status,
            'processing_error' => $reviewMessage,
            'processing_completed_at' => now(),
            'confidence_score' => $confidenceScore,
            'ai_fallback_used' => $aiFallbackUsed,
            'detected_transactions' => count($transactions),
            'total_rows' => (int) ($analysis['normalized_line_count'] ?? $upload->total_rows ?? 0),
            'flagged_rows' => max(0, (int) ($analysis['normalized_line_count'] ?? 0) - count($transactions)),
            'extraction_method' => $extractionMethod,
            'extraction_confidence' => $this->confidenceLabel($confidenceScore),
        ])->save();

        $this->appendProgress($upload, 'complete', count($transactions) > 0
            ? 'Finished parsing the statement.'
            : 'Parsing finished but no transactions were detected.');
    }

    public function fail(BankStatementImport $upload, string $message): void
    {
        $upload->forceFill([
            'status' => 'failed',
            'processing_status' => 'failed',
            'processing_error' => $message,
            'processing_completed_at' => now(),
        ])->save();

        $this->appendProgress($upload, 'failed', $message);
    }

    public function queuedFiles(BankStatementImport $upload): array
    {
        $meta = $upload->meta ?? [];
        $entries = $meta['queued_file_entries'] ?? [];

        return is_array($entries) ? array_values(array_filter($entries, 'is_array')) : [];
    }

    public function isPdfOnly(BankStatementImport $upload): bool
    {
        foreach ($this->queuedFiles($upload) as $entry) {
            $name = (string) ($entry['name'] ?? '');
            if (! str_ends_with(strtolower($name), '.pdf')) {
                return false;
            }
        }

        return true;
    }

    private function appendProgress(BankStatementImport $upload, string $step, string $message): void
    {
        $meta = $upload->meta ?? [];
        $progress = is_array($meta['progress'] ?? null) ? $meta['progress'] : [];
        $progress[] = [
            'step' => $step,
            'message' => $message,
            'at' => now()->toIso8601String(),
        ];
        $meta['progress'] = $progress;
        $upload->forceFill(['meta' => $meta])->save();
    }

    /**
     * @param array<string,mixed> $values
     */
    private function mergeMeta(BankStatementImport $upload, array $values): void
    {
        $meta = $upload->meta ?? [];
        $upload->forceFill(['meta' => array_replace_recursive($meta, $values)])->save();
    }

    /**
     * @return array<string,mixed>
     */
    private function analysisMeta(BankStatementImport $upload): array
    {
        $upload->refresh();

        return is_array($upload->meta['analysis'] ?? null) ? $upload->meta['analysis'] : [];
    }

    private function score(int $transactions, int $lines): float
    {
        if ($lines <= 0) {
            return 0.0;
        }

        return round(min(1, $transactions / $lines), 2);
    }

    private function confidenceLabel(float $confidence): string
    {
        if ($confidence >= 0.85) {
            return 'high';
        }

        if ($confidence >= 0.6) {
            return 'medium';
        }

        return 'low';
    }
}
