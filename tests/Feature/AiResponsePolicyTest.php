<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiResponsePolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_chat_returns_no_data_message_when_user_has_no_transactions(): void
    {
        $user = User::factory()->create([
            'onboarding_mode' => false,
        ]);

        $this->actingAs($user)
            ->postJson('/api/ai/chat', ['message' => 'How is my spending trend?'])
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Penny AI does not see enough data yet. Start by tracking every purchase for 7 days to establish a baseline.'
            );
    }

    public function test_monthly_reflection_returns_no_data_message_when_month_has_no_transactions(): void
    {
        $user = User::factory()->create([
            'onboarding_mode' => false,
        ]);

        $this->actingAs($user)
            ->postJson('/api/ai/monthly-reflection', ['month' => now()->format('Y-m')])
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Penny AI does not see enough data yet. Start by tracking every purchase for 7 days to establish a baseline.'
            );
    }

    public function test_chat_spreadsheet_request_returns_structured_spreadsheet_message(): void
    {
        $user = User::factory()->create([
            'onboarding_mode' => false,
        ]);

        Transaction::query()->create([
            'user_id' => $user->id,
            'amount' => 42.50,
            'category' => 'Groceries',
            'note' => 'Sample purchase',
            'transaction_date' => now()->toDateString(),
            'type' => 'spending',
            'source' => 'manual',
        ]);

        $this->actingAs($user)
            ->postJson('/api/ai/chat', ['message' => 'Can you generate my budget spreadsheet for this month?'])
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Penny AI generated a monthly budget spreadsheet organized by Needs, Wants, and Future categories. Totals and summaries are automated.'
            )
            ->assertJsonPath('action.type', 'download_spreadsheet')
            ->assertJsonPath('action.label', 'Download spreadsheet');
    }
}
