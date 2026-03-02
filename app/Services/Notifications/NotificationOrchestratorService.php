<?php

namespace App\Services\Notifications;

use App\Models\InAppNotification;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class NotificationOrchestratorService
{
    private const NEEDS_CATEGORIES = [
        'groceries',
        'transportation',
        'housing',
        'school',
        'subscriptions',
    ];

    private const WANTS_CATEGORIES = [
        'dining',
        'shopping',
        'misc',
    ];

    public function __construct(
        private readonly NotificationWindowService $windowService,
        private readonly NotificationDeliveryService $delivery,
    )
    {
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

    public function sendWelcome(User $user): ?InAppNotification
    {
        if (! $user->notifications_enabled || $user->welcome_notification_sent_at) {
            return null;
        }

        $localNow = $this->windowService->userNow($user);
        $window = $this->windowService->currentWindow($localNow);
        if (! $window) {
            return null;
        }

        if (! $this->canSendInWindow($user, $window, $localNow)) {
            return null;
        }

        $notification = $this->delivery->deliver($user, [
            'type' => 'welcome',
            'title' => 'Welcome to Penny',
            'body' => 'We’re building this slowly and intentionally. Thank you for being part of the journey.',
            'priority' => $this->priority('welcome'),
            'route' => '/app',
            'data' => [
                'kind' => 'welcome',
            ],
        ]);

        $user->welcome_notification_sent_at = now();
        $this->markSent($user, $window, $localNow, false);

        return $notification;
    }

    public function runScheduledCycle(User $user, ?CarbonInterface $reference = null): ?InAppNotification
    {
        if (! $user->notifications_enabled || $user->onboarding_mode) {
            return null;
        }

        $localNow = $this->windowService->userNow($user, $reference);
        $window = $this->windowService->currentWindow($localNow);
        if (! $window) {
            return null;
        }

        if (! $this->canSendInWindow($user, $window, $localNow)) {
            return null;
        }

        $candidates = collect([
            $this->buildMonthlySnapshotCandidate($user, $localNow),
            $this->buildSpendingShiftCandidate($user, $localNow),
            $this->buildWeeklyReflectionCandidate($user, $localNow),
            $this->buildCelebrationCandidate($user, $localNow),
            $this->buildMicroTipCandidate($user, $localNow),
        ])->filter()->values();

        if ($candidates->isEmpty()) {
            return null;
        }

        $selected = $candidates
            ->sortByDesc(fn (array $candidate): int => (int) ($candidate['priority'] ?? 0))
            ->first();

        $notification = $this->delivery->deliver($user, $selected);
        $isMicroTip = ($selected['type'] ?? null) === 'micro_tip';
        $this->markSent($user, $window, $localNow, $isMicroTip);

        return $notification;
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

    private function canSendInWindow(User $user, string $window, CarbonImmutable $localNow): bool
    {
        $today = $localNow->toDateString();
        $lastDate = optional($user->last_notification_date)->toDateString();

        if ($lastDate !== $today) {
            $user->notifications_sent_today_count = 0;
            $user->last_notification_window = null;
            $user->last_notification_date = $localNow->toDateString();
            $user->save();
        }

        $dailyLimit = (int) config('notifications.daily_limit', 3);
        if ($user->notifications_sent_today_count >= $dailyLimit) {
            return false;
        }

        if (
            $user->last_notification_window === $window
            && optional($user->last_notification_date)->toDateString() === $today
        ) {
            return false;
        }

        return true;
    }

    private function markSent(User $user, string $window, CarbonImmutable $localNow, bool $isMicroTip): void
    {
        $user->notifications_sent_today_count = min(
            (int) config('notifications.daily_limit', 3),
            ((int) $user->notifications_sent_today_count) + 1
        );
        $user->last_notification_window = $window;
        $user->last_notification_date = $localNow->toDateString();
        $user->last_notification_sent_at = now();

        if ($isMicroTip) {
            $user->last_micro_tip_sent_at = now();
        }

        $user->save();
    }

    private function buildMonthlySnapshotCandidate(User $user, CarbonImmutable $localNow): ?array
    {
        if ((int) $localNow->day !== 1 || (int) $localNow->hour !== 9) {
            return null;
        }

        $periodStart = $localNow->subMonthNoOverflow()->startOfMonth();
        $periodEnd = $periodStart->endOfMonth();
        $periodKey = $periodStart->format('Y-m');

        $alreadySent = InAppNotification::query()
            ->where('user_id', $user->id)
            ->where('type', 'monthly_snapshot')
            ->where('data_json->period_key', $periodKey)
            ->exists();
        if ($alreadySent) {
            return null;
        }

        $transactions = $this->transactionsInRange($user, $periodStart, $periodEnd);
        if ($transactions->isEmpty()) {
            return null;
        }

        $income = (float) $transactions->where('type', 'income')->sum('amount');
        $spending = (float) $transactions->where('type', '!=', 'income')->sum('amount');
        $net = $income - $spending;
        [$needsPct, $wantsPct, $futurePct] = $this->needsWantsFuturePercents($transactions);

        $monthLabel = $periodStart->format('F');
        $body = $user->show_financial_data_in_notifications
            ? "{$monthLabel} spending totaled ".$this->money($spending).". Your overview is ready."
            : "Your {$monthLabel} overview is ready.";

        return [
            'type' => 'monthly_snapshot',
            'title' => 'Monthly snapshot ready',
            'body' => $body,
            'priority' => $this->priority('monthly_snapshot'),
            'route' => '/insights',
            'data' => [
                'period_key' => $periodKey,
                'month' => $monthLabel,
                'income' => round($income, 2),
                'spending' => round($spending, 2),
                'net' => round($net, 2),
                'needs_percent' => $needsPct,
                'wants_percent' => $wantsPct,
                'future_percent' => $futurePct,
            ],
        ];
    }

    private function buildWeeklyReflectionCandidate(User $user, CarbonImmutable $localNow): ?array
    {
        if ((int) $localNow->dayOfWeek !== 0 || (int) $localNow->hour !== 18) {
            return null;
        }

        $weekStart = $localNow->startOfWeek();
        $weekEnd = $weekStart->endOfWeek();
        $periodKey = $weekStart->format('o-\WW');

        $alreadySent = InAppNotification::query()
            ->where('user_id', $user->id)
            ->where('type', 'weekly_reflection')
            ->where('data_json->period_key', $periodKey)
            ->exists();
        if ($alreadySent) {
            return null;
        }

        $transactions = $this->transactionsInRange($user, $weekStart, $weekEnd);
        if ($transactions->count() < 3) {
            return null;
        }

        $higherInLast3Days = InAppNotification::query()
            ->where('user_id', $user->id)
            ->whereIn('type', ['welcome', 'monthly_snapshot', 'spending_shift'])
            ->where('sent_at', '>=', now()->subDays(3))
            ->exists();
        if ($higherInLast3Days) {
            return null;
        }

        $largestCategory = $this->largestSpendingCategory($transactions) ?? 'Spending';
        [$needsPct, $wantsPct] = $this->needsWantsPercents($transactions);

        $previousStart = $weekStart->subWeek();
        $previousEnd = $previousStart->endOfWeek();
        $previousTransactions = $this->transactionsInRange($user, $previousStart, $previousEnd);
        $spending = (float) $transactions->where('type', '!=', 'income')->sum('amount');
        $previousSpending = (float) $previousTransactions->where('type', '!=', 'income')->sum('amount');
        $change = $spending - $previousSpending;

        $body = $user->show_financial_data_in_notifications
            ? "{$largestCategory} was your largest category this week."
            : 'Your largest category shifted this week. Worth a glance?';

        return [
            'type' => 'weekly_reflection',
            'title' => 'Weekly reflection',
            'body' => $body,
            'priority' => $this->priority('weekly_reflection'),
            'route' => '/insights',
            'data' => [
                'period_key' => $periodKey,
                'largest_category' => $largestCategory,
                'needs_percent' => $needsPct,
                'wants_percent' => $wantsPct,
                'spending_change' => round($change, 2),
            ],
        ];
    }

    private function buildSpendingShiftCandidate(User $user, CarbonImmutable $localNow): ?array
    {
        $currentStart = $localNow->startOfMonth();
        $currentEnd = $localNow->endOfMonth();
        $previousStart = $localNow->subMonthNoOverflow()->startOfMonth();
        $previousEnd = $previousStart->endOfMonth();

        $current = $this->transactionsInRange($user, $currentStart, $currentEnd)
            ->where('type', '!=', 'income');
        $previous = $this->transactionsInRange($user, $previousStart, $previousEnd)
            ->where('type', '!=', 'income');

        if ($current->isEmpty() || $previous->isEmpty()) {
            return null;
        }

        $currentByCategory = $current->groupBy(fn (Transaction $transaction) => (string) $transaction->category);
        $previousByCategory = $previous->groupBy(fn (Transaction $transaction) => (string) $transaction->category);
        $periodKey = $localNow->format('Y-m');

        foreach ($currentByCategory as $category => $rows) {
            if ($rows->count() < 3) {
                continue;
            }

            $currentTotal = (float) $rows->sum('amount');
            $previousTotal = (float) ($previousByCategory->get($category)?->sum('amount') ?? 0.0);
            if ($previousTotal <= 0) {
                continue;
            }

            $difference = $currentTotal - $previousTotal;
            $increaseRatio = $currentTotal / max(1.0, $previousTotal);
            if ($increaseRatio < 1.2 || $difference < 100) {
                continue;
            }

            $alreadySent = InAppNotification::query()
                ->where('user_id', $user->id)
                ->where('type', 'spending_shift')
                ->where('data_json->period_key', $periodKey)
                ->where('data_json->category', $category)
                ->exists();
            if ($alreadySent) {
                continue;
            }

            $body = $user->show_financial_data_in_notifications
                ? "{$category} is trending higher than last month."
                : 'A spending category is trending higher than last month.';

            return [
                'type' => 'spending_shift',
                'title' => 'Spending shift detected',
                'body' => $body,
                'priority' => $this->priority('spending_shift'),
                'route' => '/insights',
                'data' => [
                    'period_key' => $periodKey,
                    'category' => $category,
                    'difference' => round($difference, 2),
                    'previous_total' => round($previousTotal, 2),
                    'current_total' => round($currentTotal, 2),
                ],
            ];
        }

        return null;
    }

    private function buildCelebrationCandidate(User $user, CarbonImmutable $localNow): ?array
    {
        $recentCelebration = InAppNotification::query()
            ->where('user_id', $user->id)
            ->where('type', 'celebration')
            ->where('sent_at', '>=', now()->subDays(7))
            ->exists();
        if ($recentCelebration) {
            return null;
        }

        $currentStart = $localNow->startOfMonth();
        $currentEnd = $localNow->endOfMonth();
        $previousStart = $localNow->subMonthNoOverflow()->startOfMonth();
        $previousEnd = $previousStart->endOfMonth();

        $current = $this->transactionsInRange($user, $currentStart, $currentEnd)->where('type', '!=', 'income');
        $previous = $this->transactionsInRange($user, $previousStart, $previousEnd)->where('type', '!=', 'income');
        $currentWants = (float) $current->filter(fn (Transaction $t) => $this->budgetType($t) === 'Wants')->sum('amount');
        $previousWants = (float) $previous->filter(fn (Transaction $t) => $this->budgetType($t) === 'Wants')->sum('amount');
        $currentFuture = (float) $current->filter(fn (Transaction $t) => $this->budgetType($t) === 'Future')->sum('amount');
        $previousFuture = (float) $previous->filter(fn (Transaction $t) => $this->budgetType($t) === 'Future')->sum('amount');

        $weeklyOpened = InAppNotification::query()
            ->where('user_id', $user->id)
            ->where('type', 'weekly_reflection')
            ->whereNotNull('read_at')
            ->latest('sent_at')
            ->limit(4)
            ->count();

        $body = null;
        if ($previousFuture > 0 && $currentFuture >= ($previousFuture * 1.1)) {
            $body = 'Future contributions improved this month.';
        } elseif ($previousWants > 0 && $currentWants <= ($previousWants * 0.85)) {
            $body = 'Wants spending is lower than last month.';
        } elseif ($weeklyOpened >= 4) {
            $body = 'Your weekly check-in rhythm is building strong clarity.';
        }

        if (! $body) {
            return null;
        }

        return [
            'type' => 'celebration',
            'title' => 'Progress noted',
            'body' => $body,
            'priority' => $this->priority('celebration'),
            'route' => '/insights',
            'data' => [
                'period_key' => $localNow->format('Y-m'),
            ],
        ];
    }

    private function buildMicroTipCandidate(User $user, CarbonImmutable $localNow): ?array
    {
        $lastThreeDays = InAppNotification::query()
            ->where('user_id', $user->id)
            ->where('sent_at', '>=', now()->subDays(3))
            ->exists();
        if ($lastThreeDays) {
            return null;
        }

        if ($user->last_micro_tip_sent_at && $user->last_micro_tip_sent_at->gt(now()->subDays(10))) {
            return null;
        }

        $tips = (array) config('notifications.micro_tips', []);
        if (empty($tips)) {
            return null;
        }

        $index = abs(crc32($user->id.'-'.$localNow->toDateString())) % count($tips);
        $tip = $tips[$index];

        return [
            'type' => 'micro_tip',
            'title' => 'A gentle reminder',
            'body' => $tip,
            'priority' => $this->priority('micro_tip'),
            'route' => '/app',
            'data' => [
                'tip_index' => $index,
            ],
        ];
    }

    private function transactionsInRange(User $user, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return Transaction::query()
            ->where('user_id', $user->id)
            ->whereBetween('transaction_date', [$start->toDateString(), $end->toDateString()])
            ->get();
    }

    private function largestSpendingCategory(Collection $transactions): ?string
    {
        $spending = $transactions->where('type', '!=', 'income');
        if ($spending->isEmpty()) {
            return null;
        }

        /** @var Collection<int, Collection<int, Transaction>> $grouped */
        $grouped = $spending->groupBy(fn (Transaction $transaction) => (string) $transaction->category);
        $largest = $grouped->sortByDesc(fn (Collection $rows) => $rows->sum('amount'))->keys()->first();
        return $largest ? (string) $largest : null;
    }

    private function needsWantsFuturePercents(Collection $transactions): array
    {
        $spending = $transactions->where('type', '!=', 'income');
        $total = (float) $spending->sum('amount');
        if ($total <= 0) {
            return [0, 0, 0];
        }

        $needs = (float) $spending->filter(fn (Transaction $transaction) => $this->budgetType($transaction) === 'Needs')->sum('amount');
        $wants = (float) $spending->filter(fn (Transaction $transaction) => $this->budgetType($transaction) === 'Wants')->sum('amount');
        $future = (float) $spending->filter(fn (Transaction $transaction) => $this->budgetType($transaction) === 'Future')->sum('amount');

        return [
            (int) round(($needs / $total) * 100),
            (int) round(($wants / $total) * 100),
            (int) round(($future / $total) * 100),
        ];
    }

    private function needsWantsPercents(Collection $transactions): array
    {
        [$needs, $wants] = $this->needsWantsFuturePercents($transactions);
        return [$needs, $wants];
    }

    private function budgetType(Transaction $transaction): string
    {
        $category = strtolower((string) ($transaction->category ?? ''));
        if ($category === 'future') {
            return 'Future';
        }

        if (in_array($category, self::NEEDS_CATEGORIES, true)) {
            return 'Needs';
        }

        if (in_array($category, self::WANTS_CATEGORIES, true)) {
            return 'Wants';
        }

        return 'Wants';
    }

    private function money(float $value): string
    {
        return '$'.number_format($value, 2);
    }

    private function priority(string $type): int
    {
        return (int) config("notifications.priorities.{$type}", 0);
    }
}

