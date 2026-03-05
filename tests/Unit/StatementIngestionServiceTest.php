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

    private function makeService(): StatementIngestionService
    {
        $ai = new AiStructuredExtractionService();
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
