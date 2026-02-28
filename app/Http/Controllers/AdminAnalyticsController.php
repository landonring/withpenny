<?php

namespace App\Http\Controllers;

use App\Models\AnalyticsEvent;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Cashier\Subscription;

class AdminAnalyticsController extends Controller
{
    private const ACTIVE_STATUSES = [
        'active',
        'trialing',
        'past_due',
        'incomplete',
    ];

    public function overview(): JsonResponse
    {
        $totalUsers = User::query()->count();
        $activeUsers7d = User::query()
            ->where('last_login_at', '>=', now()->subDays(7))
            ->count();
        $activeUsers30d = User::query()
            ->where('last_login_at', '>=', now()->subDays(30))
            ->count();

        $activeSubscriptions = $this->activeSubscriptions()->get(['user_id', 'stripe_price']);
        [$proUsers, $premiumUsers, $payingUsers, $mrr] = $this->summarizeSubscriptions($activeSubscriptions);
        $freeUsers = max(0, $totalUsers - $payingUsers);

        $churnRate = $this->calculateChurnRate();

        return response()->json([
            'total_users' => $totalUsers,
            'active_users_7d' => $activeUsers7d,
            'active_users_30d' => $activeUsers30d,
            'free_users' => $freeUsers,
            'pro_users' => $proUsers,
            'premium_users' => $premiumUsers,
            'paying_users' => $payingUsers,
            'mrr' => $mrr,
            'churn_rate' => $churnRate,
        ]);
    }

    public function growth(): JsonResponse
    {
        $weeks = 12;
        $end = now()->startOfWeek();
        $start = (clone $end)->subWeeks($weeks - 1);
        $labels = $this->buildWeeklyLabels($start, $end);

        $signups = $this->groupByWeek(
            User::query()
                ->where('created_at', '>=', $start)
                ->get(['created_at']),
            $labels
        );

        $upgrades = $this->groupByWeek(
            AnalyticsEvent::query()
                ->where('event_name', 'plan_upgraded')
                ->where('created_at', '>=', $start)
                ->get(['created_at']),
            $labels
        );

        $cancellations = $this->groupByWeek(
            AnalyticsEvent::query()
                ->where('event_name', 'plan_cancelled')
                ->where('created_at', '>=', $start)
                ->get(['created_at']),
            $labels
        );

        return response()->json([
            'signups_by_date' => $signups,
            'upgrades_by_date' => $upgrades,
            'cancellations_by_date' => $cancellations,
        ]);
    }

    public function featureUsage(): JsonResponse
    {
        $events = AnalyticsEvent::query()
            ->whereIn('event_name', [
                'receipt_uploaded',
                'reflection_generated',
                'life_phase_selected',
            ])
            ->select('event_name', DB::raw('COUNT(*) as total'))
            ->groupBy('event_name')
            ->get()
            ->pluck('total', 'event_name');

        return response()->json([
            'receipt_uploaded' => (int) ($events['receipt_uploaded'] ?? 0),
            'reflection_generated' => (int) ($events['reflection_generated'] ?? 0),
            'life_phase_selected' => (int) ($events['life_phase_selected'] ?? 0),
        ]);
    }

    public function users(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $plan = trim((string) $request->query('plan', ''));
        $sort = trim((string) $request->query('sort', 'created_at'));
        $direction = $request->query('direction') === 'asc' ? 'asc' : 'desc';
        $perPage = (int) $request->query('per_page', 20);
        if ($perPage < 1) {
            $perPage = 20;
        }
        if ($perPage > 200) {
            $perPage = 200;
        }

        $priceMap = $this->priceMap();
        $planPriceIds = $this->priceIdsByPlan();

        $query = User::query()
            ->select([
                'users.id',
                'users.name',
                'users.email',
                'users.life_phase',
                'users.last_login_at',
                'users.created_at',
            ])
            ->addSelect([
                'subscription_price' => Subscription::query()
                    ->select('stripe_price')
                    ->whereColumn('user_id', 'users.id')
                    ->orderByDesc('created_at')
                    ->limit(1),
                'subscription_status' => Subscription::query()
                    ->select('stripe_status')
                    ->whereColumn('user_id', 'users.id')
                    ->orderByDesc('created_at')
                    ->limit(1),
                'subscription_ends_at' => Subscription::query()
                    ->select('ends_at')
                    ->whereColumn('user_id', 'users.id')
                    ->orderByDesc('created_at')
                    ->limit(1),
            ]);

        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder->where('users.name', 'like', "%{$search}%")
                    ->orWhere('users.email', 'like', "%{$search}%");
            });
        }

        if (in_array($plan, ['starter', 'pro', 'premium'], true)) {
            $activeSubQuery = Subscription::query()
                ->select('user_id')
                ->whereIn('stripe_status', self::ACTIVE_STATUSES)
                ->where(function ($builder) {
                    $builder->whereNull('ends_at')
                        ->orWhere('ends_at', '>', now());
                });

            if ($plan === 'starter') {
                $paidPriceIds = array_merge($planPriceIds['pro'] ?? [], $planPriceIds['premium'] ?? []);
                if (! empty($paidPriceIds)) {
                    $activeSubQuery->whereIn('stripe_price', $paidPriceIds);
                }
                $query->whereNotIn('users.id', $activeSubQuery);
            } else {
                $priceIds = $planPriceIds[$plan] ?? [];
                $activeSubQuery->whereIn('stripe_price', $priceIds);
                $query->whereIn('users.id', $activeSubQuery);
            }
        }

        $sortable = [
            'name' => 'users.name',
            'email' => 'users.email',
            'created_at' => 'users.created_at',
            'last_login_at' => 'users.last_login_at',
            'life_phase' => 'users.life_phase',
            'subscription_status' => 'subscription_status',
        ];

        $sortColumn = $sortable[$sort] ?? 'users.created_at';
        $query->orderBy($sortColumn, $direction);

        $users = $query->paginate($perPage)->through(function ($user) use ($priceMap) {
            $priceId = $user->subscription_price ?? null;
            $plan = $priceMap[$priceId]['plan'] ?? 'starter';
            $status = $user->subscription_status ?? 'none';
            $endsAt = $user->subscription_ends_at ? Carbon::parse($user->subscription_ends_at) : null;

            if (! in_array($status, self::ACTIVE_STATUSES, true) || ($endsAt && $endsAt->isPast())) {
                $plan = 'starter';
            }

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'plan' => $plan,
                'life_phase' => $user->life_phase,
                'last_login' => $user->last_login_at ? Carbon::parse($user->last_login_at)->toDateTimeString() : null,
                'created_at' => $user->created_at ? Carbon::parse($user->created_at)->toDateTimeString() : null,
                'subscription_status' => $status,
            ];
        });

        return response()->json($users);
    }

    public function networkData(): JsonResponse
    {
        $priceMap = $this->priceMap();

        $users = User::query()
            ->select([
                'users.id',
                'users.name',
                'users.email',
                'users.life_phase',
                'users.last_login_at',
                'users.created_at',
            ])
            ->addSelect([
                'subscription_price' => Subscription::query()
                    ->select('stripe_price')
                    ->whereColumn('user_id', 'users.id')
                    ->orderByDesc('created_at')
                    ->limit(1),
                'subscription_status' => Subscription::query()
                    ->select('stripe_status')
                    ->whereColumn('user_id', 'users.id')
                    ->orderByDesc('created_at')
                    ->limit(1),
                'subscription_ends_at' => Subscription::query()
                    ->select('ends_at')
                    ->whereColumn('user_id', 'users.id')
                    ->orderByDesc('created_at')
                    ->limit(1),
            ])
            ->orderByDesc('users.created_at')
            ->get()
            ->map(function ($user) use ($priceMap) {
                $priceId = $user->subscription_price ?? null;
                $status = $user->subscription_status ?? 'none';
                $endsAt = $user->subscription_ends_at ? Carbon::parse($user->subscription_ends_at) : null;
                $plan = $priceMap[$priceId]['plan'] ?? 'starter';

                if (! in_array($status, self::ACTIVE_STATUSES, true) || ($endsAt && $endsAt->isPast())) {
                    $plan = 'starter';
                }

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'plan' => $plan,
                    'status' => $plan === 'starter' ? 'free' : 'paying',
                    'avatar' => null,
                    'created_at' => $user->created_at ? Carbon::parse($user->created_at)->toDateTimeString() : null,
                    'last_login' => $user->last_login_at ? Carbon::parse($user->last_login_at)->toDateTimeString() : null,
                    'location' => null,
                    'connections' => null,
                    'life_phase' => $user->life_phase,
                ];
            });

        return response()->json([
            'center' => [
                'label' => 'Penny Health',
            ],
            'users' => $users,
        ]);
    }

    private function activeSubscriptions()
    {
        return Subscription::query()
            ->whereIn('stripe_status', self::ACTIVE_STATUSES)
            ->where(function ($builder) {
                $builder->whereNull('ends_at')
                    ->orWhere('ends_at', '>', now());
            });
    }

    private function summarizeSubscriptions(Collection $subscriptions): array
    {
        $priceMap = $this->priceMap();
        $proUsers = [];
        $premiumUsers = [];
        $payingUsers = [];
        $mrr = 0.0;

        foreach ($subscriptions as $subscription) {
            $priceId = $subscription->stripe_price ?? null;
            if (! $priceId || ! isset($priceMap[$priceId])) {
                continue;
            }
            $plan = $priceMap[$priceId]['plan'];
            if ($plan === 'pro') {
                $proUsers[$subscription->user_id] = true;
            } elseif ($plan === 'premium') {
                $premiumUsers[$subscription->user_id] = true;
            } else {
                continue;
            }
            $payingUsers[$subscription->user_id] = true;

            $amount = (float) $priceMap[$priceId]['amount'];
            $interval = $priceMap[$priceId]['interval'];
            $monthly = $interval === 'yearly' ? ($amount / 12) : $amount;
            $mrr += $monthly;
        }

        return [
            count($proUsers),
            count($premiumUsers),
            count($payingUsers),
            round($mrr, 2),
        ];
    }

    private function calculateChurnRate(): float
    {
        $periodStart = now()->subDays(30);

        $cancellations = AnalyticsEvent::query()
            ->where('event_name', 'plan_cancelled')
            ->where('created_at', '>=', $periodStart)
            ->whereNotNull('user_id')
            ->distinct('user_id')
            ->count('user_id');

        $priceMap = $this->priceMap();
        $activeAtStart = $this->activeSubscriptions()
            ->where('created_at', '<=', $periodStart)
            ->get(['user_id', 'stripe_price'])
            ->filter(function ($subscription) use ($priceMap) {
                $priceId = $subscription->stripe_price ?? null;
                if (! $priceId || ! isset($priceMap[$priceId])) {
                    return false;
                }
                $plan = $priceMap[$priceId]['plan'];
                return in_array($plan, ['pro', 'premium'], true);
            })
            ->pluck('user_id')
            ->unique()
            ->count();

        if ($activeAtStart <= 0) {
            return 0.0;
        }

        return round(($cancellations / $activeAtStart) * 100, 2);
    }

    private function priceMap(): array
    {
        $map = [];
        $plans = config('subscriptions.plans', []);

        foreach ($plans as $planKey => $planConfig) {
            foreach (['monthly', 'yearly'] as $interval) {
                $priceId = $planConfig[$interval]['stripe_price'] ?? null;
                if (! $priceId) {
                    continue;
                }
                $map[$priceId] = [
                    'plan' => $planKey,
                    'interval' => $interval,
                    'amount' => $planConfig[$interval]['amount'] ?? 0,
                ];
            }
        }

        return $map;
    }

    private function priceIdsByPlan(): array
    {
        $map = ['pro' => [], 'premium' => []];
        $plans = config('subscriptions.plans', []);

        foreach (['pro', 'premium'] as $planKey) {
            $planConfig = $plans[$planKey] ?? null;
            if (! $planConfig) {
                continue;
            }
            foreach (['monthly', 'yearly'] as $interval) {
                $priceId = $planConfig[$interval]['stripe_price'] ?? null;
                if ($priceId) {
                    $map[$planKey][] = $priceId;
                }
            }
        }

        return $map;
    }

    private function buildWeeklyLabels(Carbon $start, Carbon $end): array
    {
        $labels = [];
        $period = CarbonPeriod::create($start, '1 week', $end);
        foreach ($period as $date) {
            $labels[] = $date->startOfWeek()->toDateString();
        }
        return $labels;
    }

    private function groupByWeek(Collection $rows, array $labels): array
    {
        $buckets = array_fill_keys($labels, 0);

        foreach ($rows as $row) {
            $date = Carbon::parse($row->created_at)->startOfWeek()->toDateString();
            if (! array_key_exists($date, $buckets)) {
                continue;
            }
            $buckets[$date] += 1;
        }

        $output = [];
        foreach ($buckets as $date => $count) {
            $output[] = [
                'date' => $date,
                'count' => $count,
            ];
        }

        return $output;
    }
}
