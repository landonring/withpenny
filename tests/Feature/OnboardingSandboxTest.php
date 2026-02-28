<?php

namespace Tests\Feature;

use App\Models\AnalyticsEvent;
use App\Models\BankStatementImport;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingSandboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_login_starts_onboarding_for_new_users(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('password123'),
            'onboarding_completed' => false,
            'onboarding_mode' => false,
            'onboarding_step' => 0,
        ]);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertOk();

        $user->refresh();
        $this->assertTrue((bool) $user->onboarding_mode);
        $this->assertSame(0, (int) $user->onboarding_step);
        $this->assertNotNull($user->onboarding_started_at);
    }

    public function test_statement_confirm_in_onboarding_does_not_write_transactions_or_usage(): void
    {
        $user = User::factory()->create([
            'onboarding_mode' => true,
            'onboarding_step' => 2,
            'onboarding_completed' => false,
        ]);

        $import = BankStatementImport::query()->create([
            'user_id' => $user->id,
            'transactions' => [
                [
                    'id' => 'demo-row',
                    'date' => now()->toDateString(),
                    'description' => 'Demo statement row',
                    'amount' => 20.00,
                    'type' => 'spending',
                    'category' => 'Misc',
                    'include' => true,
                    'duplicate' => false,
                ],
            ],
            'meta' => ['opening_balance' => 100.00, 'closing_balance' => 80.00],
            'masked_account' => 'Demo account',
            'source' => 'onboarding_demo',
        ]);

        $this->actingAs($user)
            ->postJson("/api/statements/{$import->id}/confirm", [
                'transactions' => [
                    [
                        'date' => now()->toDateString(),
                        'description' => 'Demo statement row',
                        'amount' => 20.00,
                        'type' => 'spending',
                        'category' => 'Misc',
                        'include' => true,
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('status', 'sandbox_confirmed')
            ->assertJsonPath('onboarding.step', 3);

        $this->assertDatabaseMissing('bank_statement_imports', ['id' => $import->id]);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('analytics_events', 0);
    }

    public function test_onboarding_scan_images_can_create_demo_import_without_uploaded_file(): void
    {
        $user = User::factory()->create([
            'onboarding_mode' => true,
            'onboarding_step' => 1,
            'onboarding_completed' => false,
        ]);

        $response = $this->actingAs($user)
            ->postJson('/api/statements/scan-images', [])
            ->assertCreated()
            ->assertJsonStructure([
                'import' => ['id', 'transactions', 'meta'],
            ]);

        $importId = (int) $response->json('import.id');

        $this->assertDatabaseHas('bank_statement_imports', [
            'id' => $importId,
            'user_id' => $user->id,
            'source' => 'onboarding_demo',
        ]);

        $user->refresh();
        $this->assertSame(2, (int) $user->onboarding_step);
    }

    public function test_onboarding_usage_is_unlimited_even_with_existing_events(): void
    {
        $user = User::factory()->create([
            'onboarding_mode' => true,
            'onboarding_step' => 1,
            'onboarding_completed' => false,
        ]);

        AnalyticsEvent::query()->create([
            'user_id' => $user->id,
            'event_name' => 'statement_uploaded',
            'event_data' => ['mode' => 'confirm'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->getJson('/api/usage')
            ->assertOk()
            ->assertJsonPath('onboarding_mode', true)
            ->assertJsonPath('features.statement_uploads.exhausted', false)
            ->assertJsonPath('features.statement_uploads.limit', null);
    }

    public function test_step_locked_endpoint_returns_redirect_instruction(): void
    {
        $user = User::factory()->create([
            'onboarding_mode' => true,
            'onboarding_step' => 3,
            'onboarding_completed' => false,
        ]);

        $this->actingAs($user)
            ->postJson('/api/ai/chat', ['message' => 'hello'])
            ->assertStatus(409)
            ->assertJsonPath('onboarding_step', 3)
            ->assertJsonPath('redirect_to', '/insights');
    }
}
