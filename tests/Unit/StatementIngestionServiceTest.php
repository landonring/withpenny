<?php

namespace Tests\Unit;

use App\Services\Ingestion\AiStructuredExtractionService;
use App\Services\Ingestion\CategorySuggestionService;
use App\Services\Ingestion\OfxStatementParser;
use App\Services\Ingestion\StatementIngestionService;
use App\Services\Ingestion\TransactionNormalizationService;
use App\Services\Statements\CsvStatementParser;
use App\Services\Statements\PdfStatementParser;
use Tests\TestCase;

class StatementIngestionServiceTest extends TestCase
{
    public function test_process_files_retries_with_focused_ai_text_when_first_ai_pass_returns_empty(): void
    {
        $payload = implode("\n", [
            'BT',
            '/F1 12 Tf',
            '72 720 Td',
            '(01/13/2026 PAYROLL DEPOSIT +1200.00) Tj',
            'T*',
            '(01/14/2026 COFFEE SHOP -4.50) Tj',
            'ET',
        ]);
        $path = $this->writeTempPdf($payload);

        $ai = new class extends AiStructuredExtractionService {
            public int $calls = 0;

            public function isEnabled(): bool
            {
                return true;
            }

            public function extractStatementTransactions(string $rawText): array
            {
                $this->calls++;

                if ($this->calls === 1) {
                    return [
                        'transactions' => [],
                        'attempts' => 1,
                    ];
                }

                return [
                    'transactions' => [
                        [
                            'date' => '2026-01-13',
                            'description' => 'PAYROLL DEPOSIT',
                            'amount' => 1200.00,
                            'type' => 'credit',
                        ],
                    ],
                    'attempts' => 1,
                ];
            }
        };

        $service = $this->makeService($ai);
        $result = $service->processFiles([
            [
                'name' => 'sample.pdf',
                'path' => $path,
                'mime' => 'application/pdf',
            ],
        ]);

        @unlink($path);

        $this->assertSame(2, $ai->calls);
        $this->assertSame(1, (int) ($result['total_rows'] ?? 0));
        $this->assertNull($result['processing_error'] ?? null);
    }

    public function test_process_files_keeps_partial_ai_results_and_normalizes_string_amounts(): void
    {
        $payload = implode("\n", [
            'BT',
            '/F1 12 Tf',
            '72 720 Td',
            '(01/13/2026 PAYROLL DEPOSIT +1200.00) Tj',
            'T*',
            '(01/14/2026 COFFEE SHOP -4.50) Tj',
            'ET',
        ]);
        $path = $this->writeTempPdf($payload);

        $ai = new class extends AiStructuredExtractionService {
            public int $calls = 0;

            public function isEnabled(): bool
            {
                return true;
            }

            public function extractStatementTransactions(string $rawText): array
            {
                $this->calls++;

                return [
                    'transactions' => [
                        [
                            'date' => '01/13/2026',
                            'description' => 'PAYROLL DEPOSIT',
                            'amount' => '$1,200.00',
                            'type' => 'credit',
                        ],
                        [
                            'date' => 'bad-date',
                            'description' => 'BROKEN ROW',
                            'amount' => '$14.20',
                            'type' => 'debit',
                        ],
                    ],
                    'attempts' => 1,
                ];
            }
        };

        $service = $this->makeService($ai);
        $result = $service->processFiles([
            [
                'name' => 'sample.pdf',
                'path' => $path,
                'mime' => 'application/pdf',
            ],
        ]);

        @unlink($path);

        $this->assertSame(1, $ai->calls);
        $this->assertSame(1, (int) ($result['total_rows'] ?? 0));
        $this->assertNull($result['processing_error'] ?? null);
        $this->assertSame(1200.00, (float) ($result['transactions'][0]['amount'] ?? 0));
        $this->assertSame('income', $result['transactions'][0]['type'] ?? null);
    }

    public function test_process_files_uses_stream_fallback_when_pdf_text_tools_are_unavailable(): void
    {
        $payload = implode("\n", [
            'BT',
            '/F1 12 Tf',
            '72 720 Td',
            '(01/13/2026 PAYROLL DEPOSIT +1200.00) Tj',
            'T*',
            '(01/14/2026 COFFEE SHOP -4.50) Tj',
            'ET',
        ]);

        $path = $this->writeTempPdf($payload);

        $service = $this->makeService();
        $result = $service->processFiles([
            [
                'name' => 'sample.pdf',
                'path' => $path,
                'mime' => 'application/pdf',
            ],
        ]);

        @unlink($path);

        $this->assertGreaterThanOrEqual(1, (int) ($result['total_rows'] ?? 0));
        $this->assertNotEmpty($result['transactions'] ?? []);
        $this->assertNull($result['processing_error'] ?? null);
    }

    public function test_process_files_recovers_fragmented_posted_date_rows_without_ai_or_pdf_tooling(): void
    {
        $payload = implode("\n", [
            'BT',
            '/F1 12 Tf',
            '72 720 Td',
            '(Posted 01/13/2026) Tj',
            'T*',
            '(PAYROLL DEPOSIT) Tj',
            'T*',
            '(Credit) Tj',
            'T*',
            '(+$1,200.00) Tj',
            'T*',
            '(Posted 01/14/2026) Tj',
            'T*',
            '(COFFEE SHOP) Tj',
            'T*',
            '(Debit) Tj',
            'T*',
            '(-$4.50) Tj',
            'ET',
        ]);

        $path = $this->writeTempPdf($payload);

        $service = $this->makeService();
        $result = $service->processFiles([
            [
                'name' => 'fragmented.pdf',
                'path' => $path,
                'mime' => 'application/pdf',
            ],
        ]);

        @unlink($path);

        $transactions = $result['transactions'] ?? [];
        $this->assertCount(2, $transactions);
        $this->assertSame('income', $transactions[0]['type']);
        $this->assertSame('spending', $transactions[1]['type']);
        $this->assertNull($result['processing_error'] ?? null);
    }

    private function makeService(?AiStructuredExtractionService $ai = null): StatementIngestionService
    {
        $ai ??= new AiStructuredExtractionService();
        $category = new CategorySuggestionService($ai);
        $normalizer = new TransactionNormalizationService($category);

        return new StatementIngestionService(
            new CsvStatementParser(),
            new OfxStatementParser(),
            $ai,
            $normalizer,
            new PdfStatementParser(),
        );
    }

    private function writeTempPdf(string $payload): string
    {
        $compressed = gzcompress($payload);
        $pdf = "%PDF-1.4\n"
            ."1 0 obj\n"
            ."<< /Length ".strlen($compressed)." /Filter /FlateDecode >>\n"
            ."stream\n"
            .$compressed."\n"
            ."endstream\n"
            ."endobj\n"
            ."%%EOF\n";

        $path = tempnam(sys_get_temp_dir(), 'stmt-pdf-');
        file_put_contents($path, $pdf);

        return $path;
    }
}
