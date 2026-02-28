<?php

namespace App\Services;

use App\Models\User;

class SubscriptionAccessService
{
    public function resolve(User $user): array
    {
        $subscriptionName = config('subscriptions.subscription_name', 'default');
        $subscription = $user->subscription($subscriptionName);

        if (! $subscription || $subscription->ended() || ($subscription->canceled() && ! $subscription->onGracePeriod())) {
            return [
                'base_plan' => 'starter',
                'effective_plan' => 'starter',
                'status' => 'none',
                'active' => false,
                'on_trial' => false,
                'ends_at' => null,
                'price_id' => null,
            ];
        }

        $status = (string) ($subscription->stripe_status ?? 'none');
        $basePlan = $this->planFromPrice($subscription->stripe_price);
        $effectivePlan = $basePlan;

        // Failed payments should not hard-lock immediately; temporarily limit Premium-only features.
        if (in_array($status, ['past_due', 'incomplete'], true) && $basePlan === 'premium') {
            $effectivePlan = 'pro';
        }

        // Expired unpaid subscriptions are no longer active paid access.
        if (in_array($status, ['unpaid', 'incomplete_expired'], true)) {
            $effectivePlan = 'starter';
        }

        return [
            'base_plan' => $basePlan,
            'effective_plan' => $effectivePlan,
            'status' => $status,
            'active' => $subscription->active(),
            'on_trial' => $subscription->onTrial(),
            'ends_at' => optional($subscription->ends_at)->toDateTimeString(),
            'price_id' => $subscription->stripe_price,
        ];
    }

    public function allows(User $user, string $requiredPlan): bool
    {
        $resolved = $this->resolve($user);
        return $this->rank($resolved['effective_plan']) >= $this->rank($requiredPlan);
    }

    public function rank(string $plan): int
    {
        return match ($plan) {
            'premium' => 2,
            'pro' => 1,
            default => 0,
        };
    }

    public function label(string $plan): string
    {
        return match ($plan) {
            'premium' => 'Premium',
            'pro' => 'Pro',
            default => 'Starter',
        };
    }

    public function planFromPrice(?string $priceId): string
    {
        foreach (config('subscriptions.plans', []) as $planKey => $plan) {
            foreach (['monthly', 'yearly'] as $interval) {
                if (($plan[$interval]['stripe_price'] ?? null) === $priceId) {
                    return $planKey;
                }
            }
        }

        return 'starter';
    }

    public function intervalFromPrice(?string $priceId): string
    {
        foreach (config('subscriptions.plans', []) as $plan) {
            foreach (['monthly', 'yearly'] as $interval) {
                if (($plan[$interval]['stripe_price'] ?? null) === $priceId) {
                    return $interval;
                }
            }
        }

        return 'monthly';
    }
}
