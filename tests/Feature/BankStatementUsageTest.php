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

        $this->assertDatabaseMissing('bank_statement_imports', ['id' => $import->id]);
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

        $this->assertDatabaseHas('bank_statement_imports', ['id' => $import->id]);
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
