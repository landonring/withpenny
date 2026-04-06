<?php

namespace Tests\Feature;

use App\Models\AnalyticsEvent;
use App\Models\BankStatementImport;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class BankStatementUsageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_discarding_pending_statement_does_not_consume_usage(): void
    {
        $user = User::factory()->create();
        $import = $this->createImport($user);

        $this->actingAs($user)
            ->deleteJson("/api/statements/{$import->id}")
            ->assertOk()
            ->assertJsonPath('status', 'discarded');

        $this->actingAs($user)
            ->getJson('/api/usage')
            ->assertOk()
            ->assertJsonPath('features.statement_uploads.used', 0)
            ->assertJsonPath('features.statement_uploads.remaining', 2);
    }

    public function test_confirming_statement_consumes_one_upload_usage(): void
    {
        $user = User::factory()->create();
        $import = $this->createImport($user);

        $payload = [
            'transactions' => [
                [
                    'date' => '2026-02-01',
                    'description' => 'PAYROLL DEPOSIT',
                    'amount' => 1200.00,
                    'type' => 'income',
                    'category' => 'Income',
                    'include' => true,
                ],
                [
                    'date' => '2026-02-02',
                    'description' => 'GROCERY',
                    'amount' => 100.50,
                    'type' => 'spending',
                    'category' => 'Groceries',
                    'include' => true,
                ],
            ],
        ];

        $this->actingAs($user)
            ->postJson("/api/statements/{$import->id}/confirm", $payload)
            ->assertOk()
            ->assertJsonPath('status', 'imported')
            ->assertJsonPath('count', 2);

        $this->assertDatabaseHas('bank_statement_uploads', [
            'id' => $import->id,
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'upload_id' => $import->id,
            'note' => 'PAYROLL DEPOSIT',
        ]);
        $this->assertDatabaseCount('analytics_events', 1);
        $this->assertDatabaseHas('analytics_events', [
            'user_id' => $user->id,
            'event_name' => 'statement_uploaded',
        ]);

        $this->actingAs($user)
            ->getJson('/api/usage')
            ->assertOk()
            ->assertJsonPath('features.statement_uploads.used', 1)
            ->assertJsonPath('features.statement_uploads.remaining', 1);
    }

    public function test_confirm_is_blocked_when_monthly_statement_limit_is_reached(): void
    {
        $user = User::factory()->create();
        $import = $this->createImport($user);

        AnalyticsEvent::query()->create([
            'user_id' => $user->id,
            'event_name' => 'statement_uploaded',
            'event_data' => ['mode' => 'confirm'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        AnalyticsEvent::query()->create([
            'user_id' => $user->id,
            'event_name' => 'statement_uploaded',
            'event_data' => ['mode' => 'confirm'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = [
            'transactions' => [
                [
                    'date' => '2026-02-01',
                    'description' => 'PAYROLL DEPOSIT',
                    'amount' => 1200.00,
                    'type' => 'income',
                    'category' => 'Income',
                    'include' => true,
                ],
            ],
        ];

        $this->actingAs($user)
            ->postJson("/api/statements/{$import->id}/confirm", $payload)
            ->assertStatus(429)
            ->assertJsonPath('message', "You've reached your monthly limit.");

        $this->assertDatabaseHas('bank_statement_uploads', ['id' => $import->id]);
    }

    public function test_upload_requires_pdf_format(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/statements/upload', [
                'file' => UploadedFile::fake()->image('statement.png'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_scan_endpoint_requires_pdf_files(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/statements/scan-images', [
                'images' => [UploadedFile::fake()->image('statement.png')],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['images.0']);
    }

    public function test_upload_accepts_csv_and_parses_structured_transactions(): void
    {
        $user = User::factory()->create();

        $csv = implode("\n", [
            'Date,Description,Amount',
            '2026-02-01,Payroll Deposit,1200.00',
            '2026-02-02,Grocery Store,-48.12',
        ]);

        $file = UploadedFile::fake()->createWithContent('statement.csv', $csv);

        $response = $this->actingAs($user)
            ->postJson('/api/statements/upload', ['file' => $file])
            ->assertStatus(201);

        $response
            ->assertJsonPath('import.file_format', 'csv')
            ->assertJsonPath('import.processing_status', 'completed')
            ->assertJsonCount(2, 'import.transactions');
    }

    public function test_confirm_is_blocked_while_import_is_processing(): void
    {
        $user = User::factory()->create();
        $import = BankStatementImport::query()->create([
            'user_id' => $user->id,
            'transactions' => [],
            'meta' => null,
            'masked_account' => null,
            'source' => 'pending',
            'processing_status' => 'processing',
        ]);

        $this->actingAs($user)
            ->postJson("/api/statements/{$import->id}/confirm", [
                'transactions' => [
                    [
                        'date' => '2026-02-01',
                        'description' => 'Sample Row',
                        'amount' => 10.00,
                        'type' => 'spending',
                        'category' => 'Misc',
                        'include' => true,
                    ],
                ],
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'This statement is still processing. Please wait before confirming.');
    }

    public function test_statement_uploads_are_available_to_every_user_with_plan_limits(): void
    {
        $user = User::factory()->create([
            'email' => 'anyone@example.com',
        ]);

        $this->actingAs($user)
            ->getJson('/api/usage')
            ->assertOk()
            ->assertJsonPath('features.statement_uploads.unlimited', false)
            ->assertJsonPath('features.statement_uploads.limit', 2)
            ->assertJsonPath('features.statement_uploads.beta_enabled', true)
            ->assertJsonPath('features.statement_uploads.max_days_per_upload', 30);
    }

    private function createImport(User $user): BankStatementImport
    {
        return BankStatementImport::query()->create([
            'user_id' => $user->id,
            'transactions' => [
                [
                    'id' => 'row-1',
                    'date' => '2026-02-01',
                    'description' => 'PAYROLL DEPOSIT',
                    'amount' => 1200.00,
                    'type' => 'income',
                    'category' => 'Income',
                    'include' => true,
                    'duplicate' => false,
                ],
                [
                    'id' => 'row-2',
                    'date' => '2026-02-02',
                    'description' => 'GROCERY',
                    'amount' => 100.50,
                    'type' => 'spending',
                    'category' => 'Groceries',
                    'include' => true,
                    'duplicate' => false,
                ],
            ],
            'meta' => null,
            'masked_account' => null,
            'source' => 'photo',
        ]);
    }
}
