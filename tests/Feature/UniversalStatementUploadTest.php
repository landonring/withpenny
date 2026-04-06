<?php

namespace Tests\Feature;

use App\Services\Ingestion\AiStructuredExtractionService;
use App\Services\Statements\PdfTextExtractor;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UniversalStatementUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_pdf_upload_parses_generic_transactions_and_persists_file(): void
    {
        $user = User::factory()->create();

        $this->app->instance(PdfTextExtractor::class, new class extends PdfTextExtractor {
            public function extract(string $path): array
            {
                return [
                    'text' => implode("\n", [
                        '03/10 PAYROLL DEPOSIT +1200.00',
                        '03/11 GROCERY STORE 42.55',
                        '03/12 STARBUCKS 6.45',
                        '03/13 AMAZON 24.99',
                        '03/14 GAS STATION 35.12',
                        '03/15 ELECTRIC BILL 88.20',
                    ]),
                    'method' => 'fake_pdf_text',
                    'ocr_used' => false,
                ];
            }
        });

        $response = $this->actingAs($user)
            ->postJson('/api/statements/upload', [
                'file' => UploadedFile::fake()->create('statement.pdf', 20, 'application/pdf'),
            ])
            ->assertCreated();

        $uploadId = (int) $response->json('import.id');
        $filePath = (string) $response->json('import.file_path');

        $this->assertNotSame('', $filePath);
        $this->assertTrue(Storage::disk('local')->exists($filePath));
        $this->assertDatabaseHas('bank_statement_uploads', [
            'id' => $uploadId,
            'user_id' => $user->id,
            'status' => 'completed',
            'file_format' => 'pdf',
            'extraction_method' => 'generic_pdf',
            'detected_transactions' => 6,
        ]);
        $response
            ->assertJsonPath('import.processing_status', 'completed')
            ->assertJsonCount(6, 'import.transactions');
    }

    public function test_low_confidence_pdf_upload_uses_ai_fallback_silently(): void
    {
        $user = User::factory()->create();

        $this->app->instance(PdfTextExtractor::class, new class extends PdfTextExtractor {
            public function extract(string $path): array
            {
                return [
                    'text' => implode("\n", [
                        'Monthly statement',
                        'Account activity',
                        '03/12 STARBUCKS',
                        '6.45',
                        'Random note',
                        '03/13 AMAZON',
                        '24.99',
                    ]),
                    'method' => 'fake_pdf_text',
                    'ocr_used' => false,
                ];
            }
        });

        $this->app->instance(AiStructuredExtractionService::class, new class extends AiStructuredExtractionService {
            public function isEnabled(): bool
            {
                return true;
            }

            public function extractUniversalStatementTransactions(array $cleanedLines, ?array $statementPeriod = null): array
            {
                return [
                    [
                        'date' => '2026-03-12',
                        'description' => 'STARBUCKS',
                        'amount' => -6.45,
                    ],
                    [
                        'date' => '2026-03-13',
                        'description' => 'AMAZON',
                        'amount' => -24.99,
                    ],
                ];
            }
        });

        $this->actingAs($user)
            ->postJson('/api/statements/upload', [
                'file' => UploadedFile::fake()->create('statement.pdf', 20, 'application/pdf'),
            ])
            ->assertCreated()
            ->assertJsonPath('import.processing_status', 'completed')
            ->assertJsonPath('import.ai_fallback_used', true)
            ->assertJsonCount(2, 'import.transactions');

        $this->assertDatabaseHas('bank_statement_uploads', [
            'user_id' => $user->id,
            'status' => 'completed',
            'ai_fallback_used' => true,
            'extraction_method' => 'generic_pdf_ai_fallback',
            'detected_transactions' => 2,
        ]);
    }
}
