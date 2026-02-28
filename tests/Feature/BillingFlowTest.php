<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class BillingFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);

        config([
            'subscriptions.subscription_name' => 'default',
            'subscriptions.plans.pro.monthly.stripe_price' => 'price_pro_monthly_test',
            'subscriptions.plans.pro.yearly.stripe_price' => 'price_pro_yearly_test',
            'subscriptions.plans.premium.monthly.stripe_price' => 'price_premium_monthly_test',
            'subscriptions.plans.premium.yearly.stripe_price' => 'price_premium_yearly_test',
        ]);
    }

    public function test_billing_checkout_requires_authentication(): void
    {
        $response = $this->postJson('/api/billing/checkout', [
            'plan' => 'pro',
            'interval' => 'monthly',
        ]);

        $response->assertUnauthorized();
    }

    public function test_checkout_blocks_direct_premium_to_pro_downgrade(): void
    {
        $user = User::factory()->create();
        $this->createSubscription($user, 'price_premium_monthly_test');

        $response = $this->actingAs($user)->postJson('/api/billing/checkout', [
            'plan' => 'pro',
            'interval' => 'monthly',
        ]);

        $response
            ->assertStatus(422)
            ->assertJson([
                'message' => 'unable to dongrade to pro, please downgrade to free and than upgrade to pro',
            ]);
    }

    public function test_checkout_fails_when_target_price_is_not_configured(): void
    {
        config(['subscriptions.plans.pro.monthly.stripe_price' => null]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/billing/checkout', [
            'plan' => 'pro',
            'interval' => 'monthly',
        ]);

        $response
            ->assertStatus(422)
            ->assertJson([
                'message' => 'Stripe price is not configured for this plan.',
            ]);
    }

    public function test_status_returns_active_plan_and_interval_for_active_subscription(): void
    {
        $user = User::factory()->create();
        $this->createSubscription($user, 'price_pro_monthly_test');

        $response = $this->actingAs($user)->getJson('/api/billing/status');

        $response
            ->assertOk()
            ->assertJsonPath('plan', 'pro')
            ->assertJsonPath('effective_plan', 'pro')
            ->assertJsonPath('base_plan', 'pro')
            ->assertJsonPath('interval', 'monthly')
            ->assertJsonPath('active', true)
            ->assertJsonPath('pending_change', null);
    }

    public function test_status_returns_pending_cancel_change_for_subscription_in_grace_period(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-24 12:00:00'));
        $user = User::factory()->create();
        $endsAt = now()->addDays(28);
        $this->createSubscription($user, 'price_pro_monthly_test', $endsAt);

        $response = $this->actingAs($user)->getJson('/api/billing/status');

        $response
            ->assertOk()
            ->assertJsonPath('plan', 'pro')
            ->assertJsonPath('pending_change.type', 'cancel')
            ->assertJsonPath('pending_change.plan', 'starter')
            ->assertJsonPath('pending_change.interval', 'monthly')
            ->assertJsonPath('pending_change.effective_at', $endsAt->toDateTimeString());

        $this->assertStringContainsString(
            "You'll retain access until",
            (string) $response->json('pending_change.message')
        );

        Carbon::setTestNow();
    }

    public function test_cancel_without_subscription_returns_cancelled_state(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/billing/cancel');

        $response
            ->assertOk()
            ->assertJsonPath('status', 'cancelled')
            ->assertJsonPath('ends_at', null);
    }

    public function test_resume_without_subscription_returns_resumed_state(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/billing/resume');

        $response
            ->assertOk()
            ->assertJsonPath('status', 'resumed');
    }

    private function createSubscription(User $user, string $priceId, ?Carbon $endsAt = null): void
    {
        $user->subscriptions()->create([
            'type' => config('subscriptions.subscription_name', 'default'),
            'stripe_id' => 'sub_test_'.Str::lower(Str::random(20)),
            'stripe_status' => 'active',
            'stripe_price' => $priceId,
            'quantity' => 1,
            'trial_ends_at' => null,
            'ends_at' => $endsAt,
        ]);
    }
}

