<?php

namespace App\Services;

use App\Models\AnalyticsEvent;
use App\Models\User;
use Carbon\Carbon;

class PlanUsageService
{
    public function __construct(private readonly SubscriptionAccessService $subscriptionAccess)
    {
    }

    public function resolvePlan(User $user): string
    {
        $resolved = $this->subscriptionAccess->resolve($user);
        return (string) ($resolved['effective_plan'] ?? 'starter');
    }

    public function usageSummary(User $user): array
    {
        if ($user->onboarding_mode) {
            return $this->onboardingUsageSummary($user);
        }

        $plan = $this->resolvePlan($user);
        $limits = $this->limitsForPlan($plan);

        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();
        $yearStart = now()->startOfYear();
        $yearEnd = now()->endOfYear();

        $receiptUsed = $this->countEvents($user->id, 'receipt_uploaded', $monthStart, $monthEnd);
        $statementUsed = $this->countEvents($user->id, 'statement_uploaded', $monthStart, $monthEnd);
        $chatUsed = $this->countEvents($user->id, 'chat_message_sent', $monthStart, $monthEnd);
        $insightDailyUsed = $this->countEvents($user->id, 'reflection_generated', $monthStart, $monthEnd, 'daily');
        $insightWeeklyUsed = $this->countEvents($user->id, 'reflection_generated', $monthStart, $monthEnd, 'weekly');
        $insightMonthlyUsed = $this->countEvents($user->id, 'reflection_generated', $monthStart, $monthEnd, 'monthly');
        $insightYearlyUsed = $this->countEvents($user->id, 'reflection_generated', $yearStart, $yearEnd, 'yearly');

        return [
            'plan' => $plan,
            'features' => [
                'receipt_scans' => array_merge(
                    $this->buildState($limits['receipt_scans']['limit'], $receiptUsed, 'month', $monthEnd),
                    ['mode' => $limits['receipt_scans']['mode']]
                ),
                'statement_uploads' => array_merge(
                    $this->buildState($limits['statement_uploads']['limit'], $statementUsed, 'month', $monthEnd),
                    [
                        'mode' => $limits['statement_uploads']['mode'],
                        'max_days_per_upload' => $limits['statement_uploads']['max_days_per_upload'],
                    ]
                ),
            ],
            'insights' => [
                'daily' => $this->buildState($limits['insights']['daily']['limit'], $insightDailyUsed, 'month', $monthEnd),
                'weekly' => $this->buildState($limits['insights']['weekly']['limit'], $insightWeeklyUsed, 'month', $monthEnd),
                'monthly' => $this->buildState($limits['insights']['monthly']['limit'], $insightMonthlyUsed, 'month', $monthEnd),
                'yearly' => $this->buildState($limits['insights']['yearly']['limit'], $insightYearlyUsed, 'year', $yearEnd),
            ],
            'chat' => [
                'messages' => array_merge(
                    $this->buildState($limits['chat']['limit'], $chatUsed, 'month', $monthEnd),
                    [
                        'mode' => $limits['chat']['mode'],
                        'memory_days' => $limits['chat']['memory_days'],
                    ]
                ),
            ],
        ];
    }

    public function limitState(User $user, string $key): array
    {
        if ($user->onboarding_mode) {
            return [
                'allowed' => true,
                'required_plan' => null,
                'usage' => [
                    'limit' => null,
                    'used' => 0,
                    'remaining' => null,
                    'period' => 'month',
                    'unlimited' => true,
                    'exhausted' => false,
                    'resets_at' => null,
                ],
                'message' => null,
                'plan' => $this->resolvePlan($user),
            ];
        }

        $usage = $this->usageSummary($user);
        $plan = $usage['plan'];

        $state = match ($key) {
            'receipt_scans' => $usage['features']['receipt_scans'],
            'statement_uploads' => $usage['features']['statement_uploads'],
            'chat_messages' => $usage['chat']['messages'],
            'insights_daily' => $usage['insights']['daily'],
            'insights_weekly' => $usage['insights']['weekly'],
            'insights_monthly' => $usage['insights']['monthly'],
            'insights_yearly' => $usage['insights']['yearly'],
            default => null,
        };

        if ($state === null) {
            return [
                'allowed' => true,
                'required_plan' => null,
                'usage' => null,
                'message' => null,
                'plan' => $plan,
            ];
        }

        if (! $state['exhausted']) {
            return [
                'allowed' => true,
                'required_plan' => null,
                'usage' => $state,
                'message' => null,
                'plan' => $plan,
            ];
        }

        if (($state['limit'] ?? null) === 0) {
            return [
                'allowed' => false,
                'required_plan' => $plan === 'starter' ? 'pro' : 'premium',
                'usage' => $state,
                'message' => 'This feature is not included in your current plan.',
                'plan' => $plan,
            ];
        }

        $periodLabel = $state['period'] === 'year' ? 'yearly' : 'monthly';

        return [
            'allowed' => false,
            'required_plan' => $plan === 'starter' ? 'pro' : 'premium',
            'usage' => $state,
            'message' => "You've reached your {$periodLabel} limit.",
            'plan' => $plan,
        ];
    }

    public function limitResponse(User $user, string $key, string $feature): array
    {
        $limit = $this->limitState($user, $key);
        return [
            'title' => 'A little more clarity.',
            'message' => $limit['message'] ?? "You've reached your monthly limit.",
            'required_plan' => $limit['required_plan'],
            'feature' => $feature,
            'usage' => $limit['usage'],
        ];
    }

    public function statementMaxDaysPerUpload(User $user): ?int
    {
        $plan = $this->resolvePlan($user);
        $limits = $this->limitsForPlan($plan);
        return $limits['statement_uploads']['max_days_per_upload'];
    }

    public function isStarter(User $user): bool
    {
        return $this->resolvePlan($user) === 'starter';
    }

    private function countEvents(int $userId, string $eventName, Carbon $start, Carbon $end, ?string $type = null): int
    {
        $query = AnalyticsEvent::query()
            ->where('user_id', $userId)
            ->where('event_name', $eventName)
            ->whereBetween('created_at', [$start, $end]);

        if ($type !== null) {
            $query->where('event_data->type', $type);
        }

        return $query->count();
    }

    private function buildState(?int $limit, int $used, string $period, Carbon $periodEnd): array
    {
        if ($limit === null) {
            return [
                'limit' => null,
                'used' => $used,
                'remaining' => null,
                'period' => $period,
                'unlimited' => true,
                'exhausted' => false,
                'resets_at' => null,
            ];
        }

        $remaining = max(0, $limit - $used);

        return [
            'limit' => $limit,
            'used' => $used,
            'remaining' => $remaining,
            'period' => $period,
            'unlimited' => false,
            'exhausted' => $used >= $limit,
            'resets_at' => $periodEnd->toIso8601String(),
        ];
    }

    private function limitsForPlan(string $plan): array
    {
        return match ($plan) {
            'premium' => [
                'receipt_scans' => ['limit' => null, 'mode' => 'full'],
                'statement_uploads' => ['limit' => null, 'max_days_per_upload' => null, 'mode' => 'full'],
                'insights' => [
                    'daily' => ['limit' => null],
                    'weekly' => ['limit' => null],
                    'monthly' => ['limit' => null],
                    'yearly' => ['limit' => null],
                ],
                'chat' => ['limit' => null, 'mode' => 'full', 'memory_days' => null],
            ],
            'pro' => [
                'receipt_scans' => ['limit' => 20, 'mode' => 'full'],
                'statement_uploads' => ['limit' => 10, 'max_days_per_upload' => 183, 'mode' => 'full'],
                'insights' => [
                    'daily' => ['limit' => 10],
                    'weekly' => ['limit' => null],
                    'monthly' => ['limit' => 4],
                    'yearly' => ['limit' => 1],
                ],
                'chat' => ['limit' => 25, 'mode' => 'transaction_aware', 'memory_days' => 60],
            ],
            default => [
                'receipt_scans' => ['limit' => 5, 'mode' => 'basic'],
                'statement_uploads' => ['limit' => 2, 'max_days_per_upload' => 30, 'mode' => 'basic'],
                'insights' => [
                    'daily' => ['limit' => 0],
                    'weekly' => ['limit' => 2],
                    'monthly' => ['limit' => 1],
                    'yearly' => ['limit' => 0],
                ],
                'chat' => ['limit' => 10, 'mode' => 'basic', 'memory_days' => 0],
            ],
        };
    }

    private function onboardingUsageSummary(User $user): array
    {
        $plan = $this->resolvePlan($user);
        $unlimitedMonth = [
            'limit' => null,
            'used' => 0,
            'remaining' => null,
            'period' => 'month',
            'unlimited' => true,
            'exhausted' => false,
            'resets_at' => null,
        ];

        $unlimitedYear = [
            'limit' => null,
            'used' => 0,
            'remaining' => null,
            'period' => 'year',
            'unlimited' => true,
            'exhausted' => false,
            'resets_at' => null,
        ];

        return [
            'plan' => $plan,
            'onboarding_mode' => true,
            'features' => [
                'receipt_scans' => array_merge($unlimitedMonth, ['mode' => 'full']),
                'statement_uploads' => array_merge($unlimitedMonth, ['mode' => 'full', 'max_days_per_upload' => null]),
            ],
            'insights' => [
                'daily' => $unlimitedMonth,
                'weekly' => $unlimitedMonth,
                'monthly' => $unlimitedMonth,
                'yearly' => $unlimitedYear,
            ],
            'chat' => [
                'messages' => array_merge($unlimitedMonth, ['mode' => 'full', 'memory_days' => null]),
            ],
        ];
    }
}
