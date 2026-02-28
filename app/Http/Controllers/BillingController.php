<?php

namespace App\Http\Controllers;

use App\Services\SubscriptionAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Laravel\Cashier\Cashier;

class BillingController extends Controller
{
    public function __construct(private readonly SubscriptionAccessService $subscriptionAccess)
    {
    }

    public function plans(): JsonResponse
    {
        return response()->json([
            'currency' => config('subscriptions.currency', 'usd'),
            'plans' => config('subscriptions.plans', []),
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $subscriptionName = config('subscriptions.subscription_name', 'default');
        $subscription = $user->subscription($subscriptionName);

        if (! $subscription || $subscription->ended() || ($subscription->canceled() && ! $subscription->onGracePeriod())) {
            $this->syncFromStripeIfNeeded($user);
            $subscription = $user->subscription($subscriptionName);
        }

        $resolved = $this->subscriptionAccess->resolve($user);
        $pendingChange = $subscription ? $this->resolvePendingChange($subscription, $resolved['base_plan']) : null;

        return response()->json([
            'plan' => $resolved['effective_plan'],
            'effective_plan' => $resolved['effective_plan'],
            'base_plan' => $resolved['base_plan'],
            'status' => $resolved['status'],
            'interval' => $this->intervalFromPrice($resolved['price_id']),
            'active' => $resolved['active'],
            'on_trial' => $resolved['on_trial'],
            'ends_at' => $resolved['ends_at'],
            'pending_change' => $pendingChange,
        ]);
    }

    public function checkout(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'plan' => ['required', 'in:pro,premium'],
            'interval' => ['required', 'in:monthly,yearly'],
        ]);

        $subscriptionName = config('subscriptions.subscription_name', 'default');
        $planConfig = config("subscriptions.plans.{$payload['plan']}.{$payload['interval']}");
        $priceId = $planConfig['stripe_price'] ?? null;

        if (! $priceId) {
            return response()->json([
                'message' => 'Stripe price is not configured for this plan.',
            ], 422);
        }

        $user = $request->user();
        $subscription = $user->subscription($subscriptionName);
        $current = $this->subscriptionAccess->resolve($user);
        $currentPlan = $current['base_plan'];
        $currentRank = $this->planRank($currentPlan);
        $targetRank = $this->planRank($payload['plan']);
        $currentInterval = $subscription ? $this->intervalFromPrice($subscription->stripe_price) : 'monthly';

        if ($currentPlan === 'premium' && $payload['plan'] === 'pro') {
            return response()->json([
                'message' => 'unable to dongrade to pro, please downgrade to free and than upgrade to pro',
            ], 422);
        }

        if ($subscription && ! $subscription->ended()) {
            // If a cancellation was requested and user keeps the same plan, restore it immediately.
            if ($subscription->onGracePeriod()
                && $currentPlan === $payload['plan']
                && $currentInterval === $payload['interval']) {
                $subscription->resume();
                analytics_track('plan_upgraded', ['from' => 'starter', 'to' => $currentPlan]);

                return response()->json([
                    'status' => 'resumed',
                    'message' => 'Cancellation removed. Your plan stays active.',
                ]);
            }

            // Downgrades should take effect at period end, not immediately.
            if ($targetRank < $currentRank) {
                try {
                    $effectiveAt = $this->scheduleDowngradeAtPeriodEnd($subscription, $priceId);
                } catch (\Throwable $e) {
                    return response()->json([
                        'message' => 'Unable to schedule plan change right now.',
                    ], 422);
                }

                analytics_track('plan_downgraded', ['from' => $currentPlan, 'to' => $payload['plan']]);

                return response()->json([
                    'status' => 'scheduled_downgrade',
                    'effective_at' => $effectiveAt?->toDateTimeString(),
                    'message' => 'Your plan will adjust on '.$this->formatPlanAdjustDate($effectiveAt).'.',
                ]);
            }

            // Upgrades should clear pending cancellations or scheduled downgrades.
            $this->clearPendingPlanAdjustments($subscription);

            if ($subscription->stripe_price === $priceId) {
                return response()->json([
                    'status' => 'already_subscribed',
                ]);
            }
        }

        $baseUrl = $this->requestBaseUrl($request);
        $checkoutOptions = [
            'success_url' => $baseUrl.'/app?billing=success&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $baseUrl.'/app?billing=cancel',
        ];

        if ($subscription && ! $subscription->ended()) {
            $checkoutOptions['metadata'] = [
                'replace_subscription_id' => $subscription->stripe_id,
            ];
        }

        $subscriptionBuilder = $user->newSubscription($subscriptionName, $priceId);
        $feeLineItem = $this->buildProcessingFeeLineItem($planConfig);
        if ($feeLineItem) {
            $subscriptionBuilder->price($feeLineItem, 1);
        }

        $checkout = $subscriptionBuilder->checkout($checkoutOptions);

        return response()->json([
            'status' => 'checkout',
            'url' => $checkout->url,
        ]);
    }

    public function complete(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'session_id' => ['required', 'string'],
        ]);

        $user = $request->user();
        $subscriptionName = config('subscriptions.subscription_name', 'default');
        $existingSubscription = $user->subscription($subscriptionName);
        $previousPlan = $existingSubscription ? $this->planFromPrice($existingSubscription->stripe_price) : 'starter';

        $stripe = Cashier::stripe();
        $session = $stripe->checkout->sessions->retrieve($payload['session_id'], [
            'expand' => ['subscription', 'customer'],
        ]);

        $subscriptionId = is_object($session->subscription) ? $session->subscription->id : $session->subscription;
        if (! $subscriptionId) {
            return response()->json(['message' => 'Subscription not found for this checkout.'], 422);
        }

        if ($session->customer && $user->stripe_id !== $session->customer) {
            $user->stripe_id = is_object($session->customer) ? $session->customer->id : $session->customer;
            $user->save();
        }

        $stripeSubscription = $stripe->subscriptions->retrieve($subscriptionId, [
            'expand' => ['items.data.price'],
        ]);

        $replaceSubscriptionId = $session->metadata?->replace_subscription_id ?? null;
        if ($replaceSubscriptionId && $replaceSubscriptionId !== $stripeSubscription->id) {
            try {
                $stripe->subscriptions->cancel($replaceSubscriptionId, [
                    'proration_behavior' => 'none',
                ]);
                $user->subscriptions()
                    ->where('stripe_id', $replaceSubscriptionId)
                    ->update([
                        'stripe_status' => 'canceled',
                        'ends_at' => now(),
                    ]);
            } catch (\Throwable $e) {
                // ignore cancel failure
            }
        }

        $this->cancelOtherStripeSubscriptions($user, $stripeSubscription->id);
        $this->syncStripeSubscription($user, $stripeSubscription);

        $item = $stripeSubscription->items->data[0] ?? null;
        $priceId = $item?->price?->id;
        $newPlan = $this->planFromPrice($priceId);
        if ($newPlan !== $previousPlan) {
            $previousRank = $this->planRank($previousPlan);
            $newRank = $this->planRank($newPlan);
            if ($newRank > $previousRank) {
                analytics_track('plan_upgraded', ['from' => $previousPlan, 'to' => $newPlan]);
            } elseif ($newRank < $previousRank) {
                analytics_track('plan_downgraded', ['from' => $previousPlan, 'to' => $newPlan]);
            }
        }

        return response()->json([
            'status' => 'synced',
        ]);
    }

    public function portal(Request $request): JsonResponse
    {
        $user = $request->user();
        $url = $user->billingPortalUrl($this->requestBaseUrl($request).'/app');

        return response()->json(['url' => $url]);
    }

    public function cancel(Request $request): JsonResponse
    {
        $user = $request->user();
        $subscriptionName = config('subscriptions.subscription_name', 'default');
        $subscription = $user->subscription($subscriptionName);

        if (! $subscription || $subscription->ended()) {
            return response()->json([
                'status' => 'cancelled',
                'ends_at' => null,
            ]);
        }

        $plan = $this->planFromPrice($subscription->stripe_price);
        $this->clearPendingPlanAdjustments($subscription);

        if (! $subscription->onGracePeriod()) {
            $subscription->cancel();
        }

        $subscription = $user->fresh()->subscription($subscriptionName);
        $endsAt = optional($subscription?->ends_at)->toDateTimeString();

        analytics_track('plan_cancelled', ['plan' => $plan]);

        return response()->json([
            'status' => 'cancelled',
            'ends_at' => $endsAt,
            'message' => $endsAt
                ? "You'll retain access until {$this->formatPlanAdjustDate(Carbon::parse($endsAt))}. After that, Penny will gently move you back to the Free plan."
                : 'Your plan will switch to Starter at the end of the current billing period.',
        ]);
    }

    public function resume(Request $request): JsonResponse
    {
        $user = $request->user();
        $subscriptionName = config('subscriptions.subscription_name', 'default');
        $subscription = $user->subscription($subscriptionName);

        if (! $subscription) {
            return response()->json(['status' => 'resumed']);
        }

        $this->clearPendingPlanAdjustments($subscription);

        if ($subscription->onGracePeriod()) {
            $subscription->resume();
        }

        return response()->json(['status' => 'resumed']);
    }

    private function syncFromStripeIfNeeded($user): bool
    {
        try {
            $stripe = Cashier::stripe();
            $customerId = $user->stripe_id;
            if (! $customerId && $user->email) {
                try {
                    $customers = $stripe->customers->search([
                        'query' => "email:'{$user->email}'",
                        'limit' => 1,
                    ]);
                    $customerId = $customers->data[0]->id ?? null;
                    if ($customerId) {
                        $user->stripe_id = $customerId;
                        $user->save();
                    }
                } catch (\Throwable $e) {
                    $customerId = null;
                }
            }

            if (! $customerId) {
                return false;
            }

            $subscriptions = $stripe->subscriptions->all([
                'customer' => $customerId,
                'status' => 'all',
                'limit' => 5,
            ]);

            $candidates = collect($subscriptions->data ?? [])->filter(function ($subscription) {
                return in_array($subscription->status, ['active', 'trialing', 'past_due', 'incomplete'], true);
            });
            $candidate = $candidates->sortByDesc(function ($subscription) {
                return $subscription->current_period_start ?? $subscription->created ?? 0;
            })->first();

            if (! $candidate) {
                return false;
            }

            $stripeSubscription = $stripe->subscriptions->retrieve($candidate->id, [
                'expand' => ['items.data.price'],
            ]);

            $this->syncStripeSubscription($user, $stripeSubscription);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function buildProcessingFeeLineItem(?array $planConfig): ?array
    {
        if (! $planConfig) {
            return null;
        }

        $amount = (float) ($planConfig['amount'] ?? 0);
        if ($amount <= 0) {
            return null;
        }

        $interval = $planConfig['interval'] ?? 'month';
        $currency = config('subscriptions.currency', 'usd');
        $netCents = (int) round($amount * 100);
        $feeCents = $this->processingFeeCents($netCents);
        if ($feeCents <= 0) {
            return null;
        }

        return [
            'price_data' => [
                'currency' => $currency,
                'product_data' => [
                    'name' => 'Processing fee',
                ],
                'unit_amount' => $feeCents,
                'recurring' => [
                    'interval' => $interval,
                ],
            ],
            'quantity' => 1,
        ];
    }

    private function processingFeeCents(int $netCents): int
    {
        $percent = 0.029;
        $fixed = 30;
        $gross = (int) ceil(($netCents + $fixed) / (1 - $percent));
        $fee = $gross - $netCents;

        return max(0, $fee);
    }

    private function syncStripeSubscription($user, $stripeSubscription): void
    {
        if ($stripeSubscription->customer && $user->stripe_id !== $stripeSubscription->customer) {
            $user->stripe_id = is_object($stripeSubscription->customer) ? $stripeSubscription->customer->id : $stripeSubscription->customer;
            $user->save();
        }

        $item = $stripeSubscription->items->data[0] ?? null;
        $priceId = $item?->price?->id;
        $quantity = $item?->quantity ?? 1;
        $subscriptionName = config('subscriptions.subscription_name', 'default');

        $subscription = $user->subscriptions()->updateOrCreate(
            ['stripe_id' => $stripeSubscription->id],
            [
                'type' => $subscriptionName,
                'stripe_status' => $stripeSubscription->status,
                'stripe_price' => $priceId,
                'quantity' => $quantity,
                'trial_ends_at' => $stripeSubscription->trial_end ? Carbon::createFromTimestamp($stripeSubscription->trial_end) : null,
                'ends_at' => $stripeSubscription->ended_at ? Carbon::createFromTimestamp($stripeSubscription->ended_at) : null,
            ]
        );

        if ($item) {
            \Laravel\Cashier\SubscriptionItem::updateOrCreate(
                ['stripe_id' => $item->id],
                [
                    'subscription_id' => $subscription->id,
                    'stripe_product' => $item->price->product ?? null,
                    'stripe_price' => $item->price->id ?? null,
                    'meter_id' => $item->price->recurring->meter ?? null,
                    'meter_event_name' => $item->price->recurring->usage_type ?? null,
                    'quantity' => $quantity,
                ]
            );
        }

        $user->subscriptions()
            ->where('stripe_id', '!=', $stripeSubscription->id)
            ->whereIn('stripe_status', ['active', 'trialing', 'past_due', 'incomplete'])
            ->update([
                'stripe_status' => 'canceled',
                'ends_at' => now(),
            ]);
    }

    private function cancelOtherStripeSubscriptions($user, string $keepId): void
    {
        if (! $user->stripe_id) {
            return;
        }

        try {
            $stripe = Cashier::stripe();
            $subscriptions = $stripe->subscriptions->all([
                'customer' => $user->stripe_id,
                'status' => 'all',
                'limit' => 10,
            ]);

            foreach ($subscriptions->data ?? [] as $subscription) {
                if ($subscription->id === $keepId) {
                    continue;
                }
                if (! in_array($subscription->status, ['active', 'trialing', 'past_due', 'incomplete'], true)) {
                    continue;
                }
                try {
                    $stripe->subscriptions->cancel($subscription->id, [
                        'proration_behavior' => 'none',
                    ]);
                } catch (\Throwable $e) {
                    // ignore cancel failure
                }
            }
        } catch (\Throwable $e) {
            // ignore list failure
        }
    }

    private function resolvePendingChange($subscription, string $currentPlan): ?array
    {
        if ($subscription->canceled() && $subscription->onGracePeriod()) {
            $effectiveAt = $subscription->ends_at ? Carbon::parse($subscription->ends_at) : null;
            return [
                'type' => 'cancel',
                'plan' => 'starter',
                'interval' => 'monthly',
                'effective_at' => $effectiveAt?->toDateTimeString(),
                'message' => $effectiveAt
                    ? "You'll retain access until {$this->formatPlanAdjustDate($effectiveAt)}. After that, Penny will gently move you back to the Free plan."
                    : null,
            ];
        }

        try {
            $stripeSubscription = $subscription->asStripeSubscription();
            if (empty($stripeSubscription->schedule)) {
                return null;
            }

            $scheduleId = is_object($stripeSubscription->schedule)
                ? $stripeSubscription->schedule->id
                : $stripeSubscription->schedule;
            if (! $scheduleId) {
                return null;
            }

            $schedule = Cashier::stripe()->subscriptionSchedules->retrieve($scheduleId, [
                'expand' => ['phases.items.price'],
            ]);

            $now = now()->timestamp;
            $nextPhase = null;
            foreach (($schedule->phases ?? []) as $phase) {
                if (($phase->start_date ?? 0) > $now) {
                    $nextPhase = $phase;
                    break;
                }
            }
            if (! $nextPhase) {
                return null;
            }

            $firstItem = $nextPhase->items[0] ?? null;
            $price = $firstItem?->price ?? null;
            $priceId = is_object($price) ? ($price->id ?? null) : $price;
            if (! $priceId) {
                return null;
            }

            $targetPlan = $this->planFromPrice($priceId);
            $targetInterval = $this->intervalFromPrice($priceId);
            $effectiveAt = Carbon::createFromTimestamp((int) $nextPhase->start_date);

            return [
                'type' => $this->planRank($targetPlan) < $this->planRank($currentPlan) ? 'downgrade' : 'change',
                'plan' => $targetPlan,
                'interval' => $targetInterval,
                'effective_at' => $effectiveAt->toDateTimeString(),
                'message' => 'Your plan will adjust on '.$this->formatPlanAdjustDate($effectiveAt).'.',
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function scheduleDowngradeAtPeriodEnd($subscription, string $targetPriceId): ?Carbon
    {
        $stripe = Cashier::stripe();
        $stripeSubscription = $subscription->asStripeSubscription();
        $this->clearPendingPlanAdjustments($subscription, $stripeSubscription);

        $currentItem = $stripeSubscription->items->data[0] ?? null;
        $currentPrice = $currentItem?->price ?? null;
        $currentPriceId = is_object($currentPrice) ? ($currentPrice->id ?? null) : $currentPrice;
        $currentQuantity = (int) ($currentItem?->quantity ?? 1);
        $periodStart = $stripeSubscription->current_period_start ?? null;
        $periodEnd = $stripeSubscription->current_period_end ?? null;

        if (! $currentPriceId || ! $periodEnd || ! $periodStart) {
            throw new \RuntimeException('Unable to determine current subscription period.');
        }

        $schedule = $stripe->subscriptionSchedules->create([
            'from_subscription' => $stripeSubscription->id,
        ]);

        $stripe->subscriptionSchedules->update($schedule->id, [
            'end_behavior' => 'release',
            'phases' => [
                [
                    'start_date' => (int) $periodStart,
                    'end_date' => (int) $periodEnd,
                    'items' => [[
                        'price' => $currentPriceId,
                        'quantity' => $currentQuantity,
                    ]],
                    'proration_behavior' => 'none',
                ],
                [
                    'start_date' => (int) $periodEnd,
                    'items' => [[
                        'price' => $targetPriceId,
                        'quantity' => 1,
                    ]],
                    'proration_behavior' => 'none',
                ],
            ],
        ]);

        return Carbon::createFromTimestamp((int) $periodEnd);
    }

    private function clearPendingPlanAdjustments($subscription, $stripeSubscription = null): void
    {
        try {
            $stripe = Cashier::stripe();
            $stripeSubscription = $stripeSubscription ?: $subscription->asStripeSubscription();

            if (! empty($stripeSubscription->schedule)) {
                $this->clearStripeSchedule($stripeSubscription->schedule);
            }

            if (! empty($stripeSubscription->cancel_at_period_end)) {
                $stripe->subscriptions->update($stripeSubscription->id, [
                    'cancel_at_period_end' => false,
                    'proration_behavior' => 'none',
                ]);
                $subscription->update([
                    'ends_at' => null,
                ]);
            }
        } catch (\Throwable $e) {
            // ignore clear failures
        }
    }

    private function clearStripeSchedule($scheduleRef): void
    {
        $scheduleId = is_object($scheduleRef) ? ($scheduleRef->id ?? null) : $scheduleRef;
        if (! $scheduleId) {
            return;
        }

        $stripe = Cashier::stripe();
        try {
            $stripe->subscriptionSchedules->release($scheduleId, []);
        } catch (\Throwable $e) {
            try {
                $stripe->subscriptionSchedules->cancel($scheduleId, []);
            } catch (\Throwable $ignored) {
                // ignore cancel failure
            }
        }
    }

    private function formatPlanAdjustDate(?Carbon $date): string
    {
        if (! $date) {
            return 'the end of your current billing period';
        }

        return $date->copy()->format('F j, Y');
    }

    private function planFromPrice(?string $priceId): string
    {
        return $this->subscriptionAccess->planFromPrice($priceId);
    }

    private function intervalFromPrice(?string $priceId): string
    {
        return $this->subscriptionAccess->intervalFromPrice($priceId);
    }

    private function planRank(string $plan): int
    {
        return $this->subscriptionAccess->rank($plan);
    }

    private function requestBaseUrl(Request $request): string
    {
        return rtrim($request->getSchemeAndHttpHost(), '/');
    }
}
