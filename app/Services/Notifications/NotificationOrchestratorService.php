<?php

namespace App\Services\Notifications;

use App\Models\AnalyticsEvent;
use App\Models\InAppNotification;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class NotificationOrchestratorService
{
    public function __construct(
        private readonly NotificationWindowService $windowService,
        private readonly NotificationDeliveryService $delivery,
    ) {
    }

    public function enable(User $user, bool $showFinancialData, ?string $timezone = null): User
    {
        if ($timezone) {
            $user->timezone = trim($timezone) !== '' ? trim($timezone) : $user->timezone;
        }

        $user->notifications_enabled = true;
        $user->notifications_enabled_at ??= now();
        $user->show_financial_data_in_notifications = $showFinancialData;
        $user->save();

        return $user->refresh();
    }

    public function disable(User $user): User
    {
        $user->notifications_enabled = false;
        $user->save();

        return $user->refresh();
    }

    // Kept for backwards compatibility with existing queued job; intentionally no-op.
    public function sendWelcome(User $user): ?InAppNotification
    {
        return null;
    }

    public function runScheduledCycle(User $user, ?CarbonInterface $reference = null): ?InAppNotification
    {
        if (! $user->notifications_enabled || $user->onboarding_mode) {
            return null;
        }

        $localNow = $this->windowService->userNow($user, $reference);

        $systemCandidate = $this->buildSystemCandidate($user, $localNow);
        if ($systemCandidate) {
            return $this->delivery->deliver($user, $systemCandidate);
        }

        $window = $this->windowService->currentWindow($localNow);
        if (! $window) {
            return null;
        }

        if (! $this->shouldEvaluateBehavioral($user, $localNow, $window)) {
            return null;
        }

        $candidate = $this->buildBehavioralCandidate($user, $localNow, $window);
        if (! $candidate) {
            return null;
        }

        $candidate['data'] = array_merge(
            $candidate['data'] ?? [],
            [
                'window' => $window,
                'local_date' => $localNow->toDateString(),
            ]
        );

        return $this->delivery->deliver($user, $candidate);
    }

    public function markRead(User $user, InAppNotification $notification): InAppNotification
    {
        if ($notification->user_id !== $user->id) {
            abort(403);
        }

        if (! $notification->read_at) {
            $notification->read_at = now();
            $notification->save();
        }

        return $notification;
    }

    public function markClicked(InAppNotification $notification): void
    {
        $notification->forceFill([
            'read_at' => $notification->read_at ?? now(),
        ])->save();

        $user = $notification->user;
        if (! $user) {
            return;
        }

        $user->last_notification_opened_at = now();
        $user->save();
    }

    private function buildSystemCandidate(User $user, CarbonImmutable $localNow): ?array
    {
        $lifecycle = $this->buildNewUserLifecycleCandidate($user, $localNow);
        if ($lifecycle) {
            return $lifecycle;
        }

        return $this->buildInactiveUserCandidate($user, $localNow);
    }

    private function buildNewUserLifecycleCandidate(User $user, CarbonImmutable $localNow): ?array
    {
        $hasTransactions = Transaction::query()
            ->where('user_id', $user->id)
            ->exists();

        if ($hasTransactions) {
            return null;
        }

        $hoursSinceSignup = max(0, (int) $user->created_at?->diffInHours(now()));
        $intervals = (array) config('notifications.system.lifecycle_intervals', []);

        foreach ($intervals as $subtype => $requiredHours) {
            if ($hoursSinceSignup < (int) $requiredHours) {
                continue;
            }

            $alreadySent = InAppNotification::query()
                ->where('user_id', $user->id)
                ->where('type', 'system')
                ->where('subtype', (string) $subtype)
                ->exists();

            if ($alreadySent) {
                continue;
            }

            return [
                'type' => 'system',
                'subtype' => (string) $subtype,
                'title' => 'Ready to track your first expense?',
                'body' => 'It only takes 10 seconds to add your first transaction. Start building clarity today.',
                'deep_link' => '/transactions/new',
                'priority' => (int) config('notifications.system.priorities.lifecycle', 92),
                'data' => [
                    'interval_hours' => (int) $requiredHours,
                    'local_date' => $localNow->toDateString(),
                ],
            ];
        }

        return null;
    }

    private function buildInactiveUserCandidate(User $user, CarbonImmutable $localNow): ?array
    {
        $inactiveDays = (int) config('notifications.system.inactive_days', 5);
        $cooldownDays = (int) config('notifications.system.inactive_cooldown_days', 7);

        $lastActivityAt = $this->lastActivityAt($user);
        if (! $lastActivityAt || $lastActivityAt->gt(now()->subDays($inactiveDays))) {
            return null;
        }

        $recentNudge = InAppNotification::query()
            ->where('user_id', $user->id)
            ->where('type', 'system')
            ->where('subtype', 'inactivity_nudge')
            ->where('sent_at', '>=', now()->subDays($cooldownDays))
            ->exists();

        if ($recentNudge) {
            return null;
        }

        return [
            'type' => 'system',
            'subtype' => 'inactivity_nudge',
            'title' => 'Let’s check back in',
            'body' => 'Your spending trends are waiting. A quick look takes less than a minute.',
            'deep_link' => '/insights',
            'priority' => (int) config('notifications.system.priorities.inactivity_nudge', 88),
            'data' => [
                'inactive_days' => $lastActivityAt->diffInDays(now()),
            ],
        ];
    }

    private function shouldEvaluateBehavioral(User $user, CarbonImmutable $localNow, string $window): bool
    {
        $lastActivityAt = $this->lastActivityAt($user);
        $inactiveLimitDays = (int) config('notifications.behavioral.skip_inactive_after_days', 14);

        if (! $lastActivityAt || $lastActivityAt->lt(now()->subDays($inactiveLimitDays))) {
            return false;
        }

        $lookbackDays = (int) config('notifications.behavioral.meaningful_lookback_days', 30);
        $minimumTransactions = (int) config('notifications.behavioral.min_meaningful_transactions', 3);

        $transactionCount = Transaction::query()
            ->where('user_id', $user->id)
            ->where('transaction_date', '>=', now()->subDays($lookbackDays)->toDateString())
            ->count();

        if ($transactionCount < $minimumTransactions) {
            return false;
        }

        return $this->canSendBehavioralInWindow($user, $localNow, $window);
    }

    private function canSendBehavioralInWindow(User $user, CarbonImmutable $localNow, string $window): bool
    {
        $dailyLimit = (int) config('notifications.behavioral.daily_limit', 3);
        $range = $this->dayRangeUtc($localNow);

        $sentToday = InAppNotification::query()
            ->where('user_id', $user->id)
            ->where('type', 'behavioral')
            ->whereBetween('sent_at', [$range['start'], $range['end']])
            ->count();

        if ($sentToday >= $dailyLimit) {
            return false;
        }

        $alreadySentInWindow = InAppNotification::query()
            ->where('user_id', $user->id)
            ->where('type', 'behavioral')
            ->whereBetween('sent_at', [$range['start'], $range['end']])
            ->where('data_json->window', $window)
            ->exists();

        return ! $alreadySentInWindow;
    }

    private function buildBehavioralCandidate(User $user, CarbonImmutable $localNow, string $window): ?array
    {
        $candidates = collect([
            $this->buildWeeklyCheckInCandidate($user, $localNow, $window),
            $this->buildMorningReflectionCandidate($user, $localNow, $window),
            $this->buildSpendingInsightCandidate($user, $localNow),
            $this->buildDriftDetectionCandidate($user, $localNow),
            $this->buildTipCandidate($user, $localNow),
        ])->filter()->values();

        if ($candidates->isEmpty()) {
            return null;
        }

        return $candidates
            ->sortByDesc(fn (array $candidate): int => (int) ($candidate['priority'] ?? 0))
            ->first();
    }

    private function buildMorningReflectionCandidate(User $user, CarbonImmutable $localNow, string $window): ?array
    {
        if ($window !== 'morning') {
            return null;
        }

        if (! $this->isActiveWithinDays($user, (int) config('notifications.behavioral.active_recent_days', 7))) {
            return null;
        }

        $alreadySent = InAppNotification::query()
            ->where('user_id', $user->id)
            ->where('type', 'behavioral')
            ->where('subtype', 'reflection_prompt')
            ->where('data_json->local_date', $localNow->toDateString())
            ->exists();

        if ($alreadySent) {
            return null;
        }

        return [
            'type' => 'behavioral',
            'subtype' => 'reflection_prompt',
            'title' => 'Quick money check-in',
            'body' => 'What surprised you about your spending this week?',
            'deep_link' => '/insights',
            'priority' => (int) config('notifications.behavioral.priorities.reflection_prompt', 80),
            'data' => [
                'prompt' => 'weekly_reflection',
            ],
        ];
    }

    private function buildWeeklyCheckInCandidate(User $user, CarbonImmutable $localNow, string $window): ?array
    {
        if ($window !== 'afternoon') {
            return null;
        }

        if ((int) $localNow->dayOfWeek !== 0) {
            return null;
        }

        if (! $this->isActiveWithinDays($user, (int) config('notifications.behavioral.active_recent_days', 7))) {
            return null;
        }

        $periodKey = $localNow->startOfWeek()->format('o-\\WW');
        $alreadySent = InAppNotification::query()
            ->where('user_id', $user->id)
            ->where('type', 'behavioral')
            ->where('subtype', 'weekly_checkin')
            ->where('data_json->period_key', $periodKey)
            ->exists();

        if ($alreadySent) {
            return null;
        }

        return [
            'type' => 'behavioral',
            'subtype' => 'weekly_checkin',
            'title' => 'Weekly clarity check',
            'body' => 'Take a minute to review how this week went.',
            'deep_link' => '/insights',
            'priority' => (int) config('notifications.behavioral.priorities.weekly_checkin', 95),
            'data' => [
                'period_key' => $periodKey,
            ],
        ];
    }

    private function buildSpendingInsightCandidate(User $user, CarbonImmutable $localNow): ?array
    {
        $weekInsight = $this->buildWeekOverWeekInsight($user, $localNow);
        if ($weekInsight) {
            return $weekInsight;
        }

        $subscriptionInsight = $this->buildNewSubscriptionInsight($user, $localNow);
        if ($subscriptionInsight) {
            return $subscriptionInsight;
        }

        return $this->buildHighExpenseInsight($user, $localNow);
    }

    private function buildWeekOverWeekInsight(User $user, CarbonImmutable $localNow): ?array
    {
        $currentStart = $localNow->startOfWeek();
        $currentEnd = $currentStart->endOfWeek();
        $previousStart = $currentStart->subWeek();
        $previousEnd = $previousStart->endOfWeek();

        $currentRows = $this->spendingRowsInRange($user, $currentStart, $currentEnd);
        $previousRows = $this->spendingRowsInRange($user, $previousStart, $previousEnd);
        if ($currentRows->isEmpty() || $previousRows->isEmpty()) {
            return null;
        }

        $currentByCategory = $currentRows->groupBy(fn (Transaction $t) => (string) ($t->category ?? 'Other'));
        $previousByCategory = $previousRows->groupBy(fn (Transaction $t) => (string) ($t->category ?? 'Other'));

        $threshold = (float) config('notifications.behavioral.thresholds.week_over_week_percent', 8);
        $best = null;

        foreach ($currentByCategory as $category => $rows) {
            $currentTotal = (float) $rows->sum('amount');
            $previousTotal = (float) ($previousByCategory->get($category)?->sum('amount') ?? 0.0);
            if ($previousTotal <= 0) {
                continue;
            }

            $percent = (($currentTotal - $previousTotal) / $previousTotal) * 100;
            if (abs($percent) < $threshold) {
                continue;
            }

            if (! $best || abs($percent) > abs((float) $best['percent'])) {
                $best = [
                    'category' => (string) $category,
                    'percent' => $percent,
                    'current' => $currentTotal,
                    'previous' => $previousTotal,
                ];
            }
        }

        if (! $best) {
            return null;
        }

        $periodKey = $currentStart->format('o-\\WW');
        $alreadySent = InAppNotification::query()
            ->where('user_id', $user->id)
            ->where('type', 'behavioral')
            ->where('subtype', 'spending_insight')
            ->where('data_json->reason', 'week_over_week')
            ->where('data_json->period_key', $periodKey)
            ->where('data_json->category', $best['category'])
            ->exists();

        if ($alreadySent) {
            return null;
        }

        $categoryTitle = Str::title((string) $best['category']);
        $direction = $best['percent'] >= 0 ? 'up' : 'down';
        $percentLabel = number_format(abs((float) $best['percent']), 0);

        return [
            'type' => 'behavioral',
            'subtype' => 'spending_insight',
            'title' => "{$categoryTitle} is {$direction} this week",
            'body' => "You’ve spent {$percentLabel}% ".($best['percent'] >= 0 ? 'more' : 'less')." on {$categoryTitle} compared to last week.",
            'deep_link' => '/insights',
            'priority' => (int) config('notifications.behavioral.priorities.spending_insight', 90),
            'data' => [
                'reason' => 'week_over_week',
                'period_key' => $periodKey,
                'category' => $best['category'],
                'percent' => round((float) $best['percent'], 2),
                'current_total' => round((float) $best['current'], 2),
                'previous_total' => round((float) $best['previous'], 2),
            ],
        ];
    }

    private function buildNewSubscriptionInsight(User $user, CarbonImmutable $localNow): ?array
    {
        $rows = Transaction::query()
            ->where('user_id', $user->id)
            ->where('type', '!=', 'income')
            ->whereDate('transaction_date', '>=', $localNow->subMonths(6)->toDateString())
            ->orderBy('transaction_date')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $subscriptionKeywords = ['subscription', 'netflix', 'spotify', 'hulu', 'prime', 'membership', 'recurring', 'icloud', 'apple'];

        $grouped = $rows->groupBy(function (Transaction $row) {
            $note = Str::lower(trim((string) ($row->note ?? '')));
            $category = Str::lower(trim((string) ($row->category ?? 'misc')));
            $base = $note !== '' ? $note : $category;
            $base = preg_replace('/\s+/', ' ', $base ?? '');
            return preg_replace('/[^a-z0-9 ]+/', '', $base ?? '') ?: 'misc';
        });

        foreach ($grouped as $key => $entries) {
            /** @var Collection<int, Transaction> $entries */
            $first = $entries->first();
            if (! $first) {
                continue;
            }

            $label = Str::lower((string) ($first->note ?: $first->category));
            $looksSubscription = str_contains(Str::lower((string) $first->category), 'subscription');
            if (! $looksSubscription) {
                foreach ($subscriptionKeywords as $keyword) {
                    if (str_contains($label, $keyword)) {
                        $looksSubscription = true;
                        break;
                    }
                }
            }

            if (! $looksSubscription) {
                continue;
            }

            if (! $first->transaction_date || $first->transaction_date->lt($localNow->subDays(14))) {
                continue;
            }

            if ($entries->count() > 1) {
                continue;
            }

            $periodKey = $localNow->format('Y-m-d');
            $alreadySent = InAppNotification::query()
                ->where('user_id', $user->id)
                ->where('type', 'behavioral')
                ->where('subtype', 'spending_insight')
                ->where('data_json->reason', 'new_subscription')
                ->where('data_json->subscription_key', $key)
                ->exists();

            if ($alreadySent) {
                continue;
            }

            $labelShort = Str::limit($first->note ?: Str::title((string) $first->category), 40, '');

            return [
                'type' => 'behavioral',
                'subtype' => 'spending_insight',
                'title' => 'New subscription spotted',
                'body' => "A new recurring-style charge appeared: {$labelShort}.",
                'deep_link' => '/insights',
                'priority' => (int) config('notifications.behavioral.priorities.spending_insight', 90),
                'data' => [
                    'reason' => 'new_subscription',
                    'period_key' => $periodKey,
                    'subscription_key' => $key,
                    'amount' => round((float) $first->amount, 2),
                ],
            ];
        }

        return null;
    }

    private function buildHighExpenseInsight(User $user, CarbonImmutable $localNow): ?array
    {
        $lookbackRows = Transaction::query()
            ->where('user_id', $user->id)
            ->where('type', '!=', 'income')
            ->whereDate('transaction_date', '>=', $localNow->subDays(60)->toDateString())
            ->get();

        if ($lookbackRows->count() < 4) {
            return null;
        }

        $average = (float) $lookbackRows->avg('amount');
        $recentMax = $lookbackRows
            ->filter(fn (Transaction $row) => $row->transaction_date && $row->transaction_date->gte($localNow->subDays(14)))
            ->sortByDesc(fn (Transaction $row) => (float) $row->amount)
            ->first();

        if (! $recentMax) {
            return null;
        }

        $floor = (float) config('notifications.behavioral.thresholds.high_single_expense_floor', 150);
        $multiplier = (float) config('notifications.behavioral.thresholds.high_single_expense_multiplier', 2.4);
        $threshold = max($floor, $average * $multiplier);

        if ((float) $recentMax->amount < $threshold) {
            return null;
        }

        $expenseDate = $recentMax->transaction_date?->toDateString() ?? $localNow->toDateString();
        $alreadySent = InAppNotification::query()
            ->where('user_id', $user->id)
            ->where('type', 'behavioral')
            ->where('subtype', 'spending_insight')
            ->where('data_json->reason', 'high_single_expense')
            ->where('data_json->expense_date', $expenseDate)
            ->exists();

        if ($alreadySent) {
            return null;
        }

        return [
            'type' => 'behavioral',
            'subtype' => 'spending_insight',
            'title' => 'High single expense noticed',
            'body' => 'A larger expense was recorded recently. Worth a quick review.',
            'deep_link' => '/insights',
            'priority' => (int) config('notifications.behavioral.priorities.spending_insight', 90),
            'data' => [
                'reason' => 'high_single_expense',
                'expense_date' => $expenseDate,
                'amount' => round((float) $recentMax->amount, 2),
                'threshold' => round((float) $threshold, 2),
            ],
        ];
    }

    private function buildDriftDetectionCandidate(User $user, CarbonImmutable $localNow): ?array
    {
        $weekStart = $localNow->startOfWeek()->subWeeks(3);
        $rows = Transaction::query()
            ->where('user_id', $user->id)
            ->where('type', '!=', 'income')
            ->whereDate('transaction_date', '>=', $weekStart->toDateString())
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $threshold = (float) config('notifications.behavioral.thresholds.drift_min_percent', 8);
        $best = null;

        $byCategory = $rows->groupBy(fn (Transaction $row) => (string) ($row->category ?? 'Other'));
        foreach ($byCategory as $category => $categoryRows) {
            $weekly = collect(range(0, 3))->map(function (int $offset) use ($localNow, $categoryRows) {
                $start = $localNow->startOfWeek()->subWeeks(3 - $offset);
                $end = $start->endOfWeek();

                return (float) $categoryRows
                    ->filter(fn (Transaction $row) =>
                        $row->transaction_date
                        && $row->transaction_date->gte($start)
                        && $row->transaction_date->lte($end)
                    )
                    ->sum('amount');
            })->values()->all();

            if (count($weekly) < 3) {
                continue;
            }

            $w1 = (float) $weekly[1];
            $w2 = (float) $weekly[2];
            $w3 = (float) $weekly[3];

            if (! ($w1 > 0 && $w2 > $w1 && $w3 > $w2)) {
                continue;
            }

            $growth = (($w3 - $w1) / max($w1, 1.0)) * 100;
            if ($growth < $threshold) {
                continue;
            }

            if (! $best || $growth > (float) $best['growth']) {
                $best = [
                    'category' => (string) $category,
                    'growth' => $growth,
                ];
            }
        }

        if (! $best) {
            return null;
        }

        $periodKey = $localNow->startOfWeek()->format('o-\\WW');
        $alreadySent = InAppNotification::query()
            ->where('user_id', $user->id)
            ->where('type', 'behavioral')
            ->where('subtype', 'drift_detection')
            ->where('data_json->period_key', $periodKey)
            ->where('data_json->category', $best['category'])
            ->exists();

        if ($alreadySent) {
            return null;
        }

        $categoryLabel = Str::title((string) $best['category']);

        return [
            'type' => 'behavioral',
            'subtype' => 'drift_detection',
            'title' => 'A small pattern is forming',
            'body' => "{$categoryLabel} has increased steadily for 3 weeks.",
            'deep_link' => '/insights',
            'priority' => (int) config('notifications.behavioral.priorities.drift_detection', 85),
            'data' => [
                'period_key' => $periodKey,
                'category' => $best['category'],
                'growth_percent' => round((float) $best['growth'], 2),
            ],
        ];
    }

    private function buildTipCandidate(User $user, CarbonImmutable $localNow): ?array
    {
        $recentTip = InAppNotification::query()
            ->where('user_id', $user->id)
            ->where('type', 'behavioral')
            ->where('subtype', 'tip')
            ->where('sent_at', '>=', now()->subDays(3))
            ->exists();

        if ($recentTip) {
            return null;
        }

        $tips = (array) config('notifications.behavioral.tips', []);
        if (empty($tips)) {
            return null;
        }

        $index = abs(crc32($user->id.'-'.$localNow->toDateString())) % count($tips);
        $tip = (string) $tips[$index];

        return [
            'type' => 'behavioral',
            'subtype' => 'tip',
            'title' => 'Small tweak idea',
            'body' => $tip,
            'deep_link' => '/insights',
            'priority' => (int) config('notifications.behavioral.priorities.tip', 65),
            'data' => [
                'tip_index' => $index,
            ],
        ];
    }

    private function lastActivityAt(User $user): ?CarbonImmutable
    {
        $lastTransaction = Transaction::query()
            ->where('user_id', $user->id)
            ->latest('created_at')
            ->value('created_at');

        $lastInsightActivity = AnalyticsEvent::query()
            ->where('user_id', $user->id)
            ->whereIn('event_name', ['insight_viewed', 'reflection_generated'])
            ->latest('created_at')
            ->value('created_at');

        $timestamps = collect([$lastTransaction, $lastInsightActivity, $user->created_at])
            ->filter()
            ->map(fn ($item) => CarbonImmutable::parse((string) $item));

        return $timestamps->isEmpty() ? null : $timestamps->sort()->last();
    }

    private function isActiveWithinDays(User $user, int $days): bool
    {
        $lastActivityAt = $this->lastActivityAt($user);
        return $lastActivityAt ? $lastActivityAt->gte(now()->subDays($days)) : false;
    }

    private function spendingRowsInRange(User $user, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return Transaction::query()
            ->where('user_id', $user->id)
            ->where('type', '!=', 'income')
            ->whereBetween('transaction_date', [$start->toDateString(), $end->toDateString()])
            ->get();
    }

    /**
     * @return array{start: \Carbon\CarbonImmutable, end: \Carbon\CarbonImmutable}
     */
    private function dayRangeUtc(CarbonImmutable $localNow): array
    {
        $startLocal = $localNow->startOfDay();
        $endLocal = $localNow->endOfDay();

        return [
            'start' => $startLocal->setTimezone('UTC'),
            'end' => $endLocal->setTimezone('UTC'),
        ];
    }
}
