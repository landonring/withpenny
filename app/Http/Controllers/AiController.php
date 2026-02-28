<?php

namespace App\Http\Controllers;

use App\Models\SavingsJourney;
use App\Models\Transaction;
use App\Services\DemoDataService;
use App\Services\OnboardingService;
use App\Services\PlanUsageService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use OpenAI\Laravel\Facades\OpenAI;

class AiController extends Controller
{
    public function __construct(
        private readonly PlanUsageService $planUsage,
        private readonly DemoDataService $demoData,
        private readonly OnboardingService $onboarding,
    )
    {
    }

    private const SYSTEM_PROMPT = "You are Penny — a calm, supportive money companion.
Your role is to help people who aren’t naturally good with money build better habits over time.
You are not a financial advisor.
You are warm, grounded, honest, encouraging, realistic, and non-judgmental.
You are not sarcastic, condescending, overly cheerful, robotic, or passive when honesty is needed.
You sound like a kind friend who tells the truth because they care.

Speak in plain, human language with short, clear sentences.
Avoid jargon or financial buzzwords.
Be conversational, not instructional.
Use one consistent voice everywhere.
Do not talk about yourself. Avoid first-person language like “I”, “me”, “my”, or “Penny”.
Use second-person language and focus on the user (“you”).
Avoid phrases like “let’s” or “we”.

Honesty is allowed and encouraged. If something isn’t going well:
- Say it clearly
- Explain why
- Offer a small, practical, optional next step
Always pair honesty with empathy and context.

Assume the user may feel stressed, ashamed, or overwhelmed.
Normalize mistakes, reduce fear around numbers, and focus on patterns over perfection.
Celebrate small improvements.
Never imply the user is lazy or should know better.

When giving feedback, follow this structure:
1) Observation
2) Context
3) Gentle suggestion

Tips should be small, realistic, actionable, and optional.
Avoid long lists, strict rules, or moral language.
Never shame, scold, threaten, or use fear-based language.

If asked for advice, explain patterns and remind the user the decision is theirs.
Your goal is to help the user feel calmer and more capable than when they opened the app.";

    private const LIFE_PHASE_GUIDANCE = [
        'early_builder' => [
            'label' => 'Early Builder (15-18)',
            'focus' => 'Foundational habits and first money basics',
        ],
        'foundation' => [
            'label' => 'Foundation (19-25)',
            'focus' => 'Consistency over perfection and building structure',
        ],
        'stability' => [
            'label' => 'Stability (26-35)',
            'focus' => 'Steady growth, planning, and sustainable habits',
        ],
        'growth' => [
            'label' => 'Growth (36-50)',
            'focus' => 'Long-term direction with competing priorities',
        ],
        'consolidation' => [
            'label' => 'Consolidation (51-65)',
            'focus' => 'Security, simplification, and clarity',
        ],
        'preservation' => [
            'label' => 'Preservation (65+)',
            'focus' => 'Maintenance, stability, and clarity',
        ],
    ];

    public function monthlyReflection(Request $request)
    {
        if ($request->user()->onboarding_mode) {
            return response()->json([
                'message' => $this->demoData->insights()['monthly'],
            ]);
        }

        $limit = $this->planUsage->limitState($request->user(), 'insights_monthly');
        if (! $limit['allowed']) {
            return response()->json(
                $this->planUsage->limitResponse($request->user(), 'insights_monthly', 'insights'),
                429
            );
        }

        $this->debugEnabled = $request->boolean('debug');
        $user = $request->user();
        $month = $request->input('month');

        [$start, $end] = $this->resolveMonthRange($month);

        $transactions = Transaction::query()
            ->where('user_id', $user->id)
            ->whereBetween('transaction_date', [$start->toDateString(), $end->toDateString()])
            ->get();

        $summary = $this->summarizeTransactions($transactions);
        $savings = $this->summarizeSavings($user->id);
        $incomeTrend = $this->summarizeIncomeTrend($user->id);
        $lifePhaseContext = $this->lifePhaseContext($user);

        $prompt = "Monthly overview request.\n"
            .$lifePhaseContext
            ."Month: ".$start->format('F Y').".\n"
            ."Transactions count: {$summary['count']}.\n"
            ."Total spent: {$summary['spending_total']}.\n"
            ."Total income: {$summary['income_total']}.\n"
            ."Top categories: {$summary['top_categories']}.\n"
            ."Average monthly income (last 3 months): {$incomeTrend['average']}.\n"
            ."Income stability: {$incomeTrend['stability']}.\n"
            ."Savings journeys active: {$savings['active_count']}.\n"
            ."Savings total saved: {$savings['total_saved']}.\n"
            ."Savings total target: {$savings['total_target']}.\n"
            ."If there is little or no data, offer a gentle invitation without pressure.\n"
            ."Write 3-4 short sentences (40-70 words total) with one observation and one reassurance. End with a complete sentence.";

        $response = $this->respondWithAi($prompt, 180);
        if ($response->getStatusCode() === 200) {
            analytics_track('reflection_generated', ['type' => 'monthly']);
        }

        return $response;
    }

    public function dailyOverview(Request $request)
    {
        if ($request->user()->onboarding_mode) {
            return response()->json([
                'message' => $this->demoData->insights()['daily'],
            ]);
        }

        $limit = $this->planUsage->limitState($request->user(), 'insights_daily');
        if (! $limit['allowed']) {
            return response()->json(
                $this->planUsage->limitResponse($request->user(), 'insights_daily', 'insights'),
                429
            );
        }

        $this->debugEnabled = $request->boolean('debug');
        $user = $request->user();
        $dateInput = $request->input('date');

        $date = $dateInput ? Carbon::parse($dateInput) : now();
        $start = $date->copy()->startOfDay();
        $end = $date->copy()->endOfDay();

        $transactions = Transaction::query()
            ->where('user_id', $user->id)
            ->whereBetween('transaction_date', [$start->toDateString(), $end->toDateString()])
            ->get();

        $summary = $this->summarizeTransactions($transactions);
        $incomeTrend = $this->summarizeIncomeTrend($user->id);
        $lifePhaseContext = $this->lifePhaseContext($user);

        $prompt = "Daily overview request.\n"
            .$lifePhaseContext
            ."Date: ".$start->format('F j, Y').".\n"
            ."Transactions count: {$summary['count']}.\n"
            ."Total spent: {$summary['spending_total']}.\n"
            ."Total income: {$summary['income_total']}.\n"
            ."Top categories: {$summary['top_categories']}.\n"
            ."Average monthly income (last 3 months): {$incomeTrend['average']}.\n"
            ."Income stability: {$incomeTrend['stability']}.\n"
            ."If there is little or no data, offer a gentle invitation without pressure.\n"
            ."Write 2-3 short sentences (25-45 words total) with one observation and one reassurance. End with a complete sentence.";

        $response = $this->respondWithAi($prompt, 120);
        if ($response->getStatusCode() === 200) {
            analytics_track('reflection_generated', ['type' => 'daily']);
        }

        return $response;
    }

    public function weeklyCheckIn(Request $request)
    {
        if ($request->user()->onboarding_mode) {
            return response()->json([
                'message' => $this->demoData->insights()['weekly'],
            ]);
        }

        $limit = $this->planUsage->limitState($request->user(), 'insights_weekly');
        if (! $limit['allowed']) {
            return response()->json(
                $this->planUsage->limitResponse($request->user(), 'insights_weekly', 'insights'),
                429
            );
        }

        $this->debugEnabled = $request->boolean('debug');
        $user = $request->user();
        $end = now()->endOfDay();
        $start = now()->subDays(6)->startOfDay();

        $transactions = Transaction::query()
            ->where('user_id', $user->id)
            ->whereBetween('transaction_date', [$start->toDateString(), $end->toDateString()])
            ->get();

        $summary = $this->summarizeTransactions($transactions);
        $incomeTrend = $this->summarizeIncomeTrend($user->id);
        $lifePhaseContext = $this->lifePhaseContext($user);

        $prompt = "Weekly check-in request.\n"
            .$lifePhaseContext
            ."Week range: ".$start->format('M j')." to ".$end->format('M j').".\n"
            ."Transactions count: {$summary['count']}.\n"
            ."Total spent: {$summary['spending_total']}.\n"
            ."Total income: {$summary['income_total']}.\n"
            ."Top categories: {$summary['top_categories']}.\n"
            ."Average monthly income (last 3 months): {$incomeTrend['average']}.\n"
            ."Income stability: {$incomeTrend['stability']}.\n"
            ."Write 2-3 sentences (25-45 words total) focused on encouragement. End with a complete sentence.";

        $response = $this->respondWithAi($prompt, 120);
        if ($response->getStatusCode() === 200) {
            analytics_track('reflection_generated', ['type' => 'weekly']);
        }

        return $response;
    }

    public function yearlyReflection(Request $request)
    {
        if ($request->user()->onboarding_mode) {
            return response()->json([
                'message' => $this->demoData->insights()['yearly'],
            ]);
        }

        $limit = $this->planUsage->limitState($request->user(), 'insights_yearly');
        if (! $limit['allowed']) {
            return response()->json(
                $this->planUsage->limitResponse($request->user(), 'insights_yearly', 'insights'),
                429
            );
        }

        $this->debugEnabled = $request->boolean('debug');
        $user = $request->user();
        $year = (int) $request->input('year', now()->year);

        [$start, $end] = $this->resolveYearRange($year);

        $transactions = Transaction::query()
            ->where('user_id', $user->id)
            ->whereBetween('transaction_date', [$start->toDateString(), $end->toDateString()])
            ->get();

        $summary = $this->summarizeYearTransactions($transactions);
        $savings = $this->summarizeSavings($user->id);
        $lifePhaseContext = $this->lifePhaseContext($user);

        $prompt = "Yearly overview request.\n"
            .$lifePhaseContext
            ."Year: {$year}.\n"
            ."Transactions count: {$summary['count']}.\n"
            ."Total spent: {$summary['spending_total']}.\n"
            ."Total income: {$summary['income_total']}.\n"
            ."Average monthly spent: {$summary['average_spent']}.\n"
            ."Average monthly income: {$summary['average_income']}.\n"
            ."Top categories: {$summary['top_categories']}.\n"
            ."Savings journeys active: {$savings['active_count']}.\n"
            ."Savings total saved: {$savings['total_saved']}.\n"
            ."If there is little or no data, offer a gentle invitation without pressure.\n"
            ."Write 4-5 short sentences (60-90 words total) with one observation and one reassurance. End with a complete sentence.";

        $response = $this->respondWithAi($prompt, 220);
        if ($response->getStatusCode() === 200) {
            analytics_track('reflection_generated', ['type' => 'yearly']);
        }

        return $response;
    }

    public function chat(Request $request)
    {
        $user = $request->user();
        $isOnboarding = (bool) $user->onboarding_mode;

        if (! $isOnboarding) {
            $limit = $this->planUsage->limitState($user, 'chat_messages');
            if (! $limit['allowed']) {
                return response()->json(
                    $this->planUsage->limitResponse($user, 'chat_messages', 'AI chat'),
                    429
                );
            }
        }

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $this->debugEnabled = $request->boolean('debug');
        $plan = $this->planUsage->resolvePlan($user);
        $lifePhaseContext = $this->lifePhaseContext($user);

        $prompt = '';
        if ($isOnboarding) {
            $history = array_slice($this->onboarding->chatHistory($request), -6);
            if ($history) {
                $prompt .= "Recent conversation context:\n";
                foreach ($history as $turn) {
                    $userText = trim((string) ($turn['user'] ?? ''));
                    $assistantText = trim((string) ($turn['assistant'] ?? ''));
                    if ($userText !== '') {
                        $prompt .= "User: {$userText}\n";
                    }
                    if ($assistantText !== '') {
                        $prompt .= "Assistant: {$assistantText}\n";
                    }
                }
                $prompt .= "\n";
            }
        }

        $prompt .= "User message: {$validated['message']}\n".$lifePhaseContext;

        if ($isOnboarding) {
            $demoMonth = now()->format('Y-m');
            $demoTransactions = collect($this->demoData->dashboardTransactions($demoMonth));
            $summary = $this->summarizeDemoTransactions($demoTransactions);
            $incomeTrend = $this->summarizeDemoIncomeTrend();

            $prompt .= "Context window: guided demo sandbox.\n"
                ."Transactions {$summary['count']}, spent {$summary['spending_total']}, income {$summary['income_total']}, top categories {$summary['top_categories']}. "
                ."Average monthly income (last 3 months): {$incomeTrend['average']}, stability {$incomeTrend['stability']}.\n"
                ."Use this demo context when answering. Do not mention this is demo data unless asked.\n";
        } elseif ($plan === 'starter') {
            $prompt .= "Context level: basic.\n"
                ."Use only the user message and gentle guidance. Avoid referencing old conversations.\n";
        } else {
            $memoryDays = $plan === 'pro' ? 60 : 365;
            $memoryStart = now()->subDays($memoryDays - 1)->startOfDay();
            $transactions = Transaction::query()
                ->where('user_id', $user->id)
                ->whereBetween('transaction_date', [$memoryStart->toDateString(), now()->toDateString()])
                ->get();

            $summary = $this->summarizeTransactions($transactions);
            $incomeTrend = $this->summarizeIncomeTrend($user->id);
            $savings = $this->summarizeSavings($user->id);

            $prompt .= "Context window: {$memoryDays} days.\n"
                ."Transactions {$summary['count']}, spent {$summary['spending_total']}, income {$summary['income_total']}, top categories {$summary['top_categories']}. "
                ."Average monthly income (last 3 months): {$incomeTrend['average']}, stability {$incomeTrend['stability']}. "
                ."Savings active {$savings['active_count']}, saved {$savings['total_saved']}.\n";
        }

        $prompt .= "If the user expresses self-criticism, start with reassurance before any reflection. "
            ."Reply in 1-2 short sentences (12-24 words). End with a complete sentence.";

        $response = $this->respondWithAi($prompt, 80);
        if ($response->getStatusCode() === 200) {
            $payload = $response->getData(true);
            $assistantMessage = trim((string) ($payload['message'] ?? ''));
            if ($isOnboarding && $assistantMessage !== '') {
                $this->onboarding->appendChatHistory($request, $validated['message'], $assistantMessage);
            }
            if (! $isOnboarding) {
                analytics_track('chat_message_sent', ['plan' => $plan]);
            }
        }

        return $response;
    }

    private function respondWithAi(string $prompt, int $maxTokens = 80)
    {
        @set_time_limit(120);
        $apiKey = (string) config('services.openai.key', '');
        $model = config('services.openai.model', 'gpt-4o-mini');
        $timeout = max(15, (int) config('services.openai.timeout', 60));

        if ($apiKey === '') {
            return response()->json(array_merge([
                'message' => 'Penny is resting right now. You can try again in a little while.',
            ], $this->debugData([
                'reason' => 'openai_missing_key',
                'provider' => 'openai',
                'model' => $model,
            ])), 503);
        }

        config([
            'openai.api_key' => $apiKey,
            'openai.request_timeout' => $timeout,
        ]);

        try {
            $response = OpenAI::chat()->create([
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'max_tokens' => $maxTokens,
                'temperature' => 0.5,
            ]);
        } catch (\Throwable $exception) {
            return response()->json(array_merge([
                'message' => 'Penny is resting right now. You can try again in a little while.',
            ], $this->debugData([
                'reason' => 'exception',
                'provider' => 'openai',
                'model' => $model,
                'timeout' => $timeout,
                'exception' => get_class($exception),
                'error' => $exception->getMessage(),
            ])), 503);
        }

        $content = $response->choices[0]->message->content ?? '';
        if (is_array($content)) {
            $parts = [];
            foreach ($content as $item) {
                $segment = (string) ($item['text'] ?? ($item['content'] ?? ''));
                if ($segment !== '') {
                    $parts[] = $segment;
                }
            }
            $content = implode("\n", $parts);
        }

        $text = trim((string) $content);

        if ($text === '') {
            $text = 'Penny is resting right now. You can try again in a little while.';
        }

        if (! preg_match('/[.!?]["\']?$/', $text)) {
            $text = rtrim($text).'.';
        }

        return response()->json([
            'message' => $text,
        ]);
    }

    /**
     * @param Collection<int, array<string, mixed>> $transactions
     */
    private function summarizeDemoTransactions(Collection $transactions): array
    {
        $count = $transactions->count();
        $categoryTotals = [];
        $spendingTotal = 0.0;
        $incomeTotal = 0.0;

        foreach ($transactions as $transaction) {
            $amount = (float) ($transaction['amount'] ?? 0);
            $type = (string) ($transaction['type'] ?? 'spending');
            if ($type === 'income') {
                $incomeTotal += $amount;
                continue;
            }

            $spendingTotal += $amount;
            $category = (string) ($transaction['category'] ?? 'Uncategorized');
            $categoryTotals[$category] = ($categoryTotals[$category] ?? 0) + $amount;
        }

        arsort($categoryTotals);
        $top = array_slice($categoryTotals, 0, 3, true);
        $topParts = [];
        foreach ($top as $category => $amount) {
            $topParts[] = $category.' ($'.number_format((float) $amount, 2).')';
        }

        return [
            'spending_total' => '$'.number_format($spendingTotal, 2),
            'income_total' => '$'.number_format($incomeTotal, 2),
            'count' => $count,
            'top_categories' => $topParts ? implode(', ', $topParts) : 'none yet',
        ];
    }

    private function summarizeDemoIncomeTrend(): array
    {
        $months = [];
        for ($i = 0; $i < 3; $i++) {
            $monthKey = now()->startOfMonth()->subMonths($i)->format('Y-m');
            $income = collect($this->demoData->dashboardTransactions($monthKey))
                ->where('type', 'income')
                ->sum('amount');
            $months[] = (float) $income;
        }

        $average = array_sum($months) / max(1, count($months));
        $max = max($months);
        $min = min($months);

        $stability = 'steady';
        if ($average <= 0.01) {
            $stability = 'not enough data';
        } elseif (($max - $min) > $average * 0.35) {
            $stability = 'varied';
        }

        return [
            'average' => '$'.number_format($average, 2),
            'stability' => $stability,
        ];
    }

    private bool $debugEnabled = false;

    private function debugData(array $data): array
    {
        if (! $this->debugEnabled && ! config('app.debug')) {
            return [];
        }

        return ['debug' => $data];
    }

    private function resolveMonthRange(?string $month): array
    {
        if ($month) {
            try {
                $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
            } catch (\Throwable $error) {
                $start = now()->startOfMonth();
            }
        } else {
            $start = now()->startOfMonth();
        }

        $end = (clone $start)->endOfMonth();
        return [$start, $end];
    }

    private function resolveYearRange(int $year): array
    {
        try {
            $start = Carbon::create($year, 1, 1)->startOfDay();
        } catch (\Throwable $error) {
            $start = now()->startOfYear();
        }

        $end = (clone $start)->endOfYear();
        return [$start, $end];
    }

    private function lifePhaseContext($user): string
    {
        if (! $user || empty($user->life_phase)) {
            return '';
        }

        $phase = (string) $user->life_phase;
        $context = self::LIFE_PHASE_GUIDANCE[$phase] ?? null;
        if (! $context) {
            return '';
        }

        return "User Life Phase: {$context['label']}.\n"
            ."Adjust tone and guidance to match typical priorities for this stage. Avoid judgment. Stay calm, supportive, and practical.\n"
            ."Focus: {$context['focus']}.\n";
    }

    private function summarizeTransactions($transactions): array
    {
        $count = $transactions->count();
        $categoryTotals = [];
        $spendingTotal = 0;
        $incomeTotal = 0;

        foreach ($transactions as $transaction) {
            $amount = (float) $transaction->amount;
            if (($transaction->type ?? 'spending') === 'income') {
                $incomeTotal += $amount;
                continue;
            }
            $spendingTotal += $amount;
            $categoryTotals[$transaction->category] = ($categoryTotals[$transaction->category] ?? 0) + $amount;
        }

        arsort($categoryTotals);
        $top = array_slice($categoryTotals, 0, 3, true);
        $topParts = [];

        foreach ($top as $category => $amount) {
            $topParts[] = $category.' ($'.number_format($amount, 2).')';
        }

        return [
            'spending_total' => '$'.number_format($spendingTotal, 2),
            'income_total' => '$'.number_format($incomeTotal, 2),
            'count' => $count,
            'top_categories' => $topParts ? implode(', ', $topParts) : 'none yet',
        ];
    }

    private function summarizeYearTransactions($transactions): array
    {
        $count = $transactions->count();
        $categoryTotals = [];
        $spendingTotal = 0;
        $incomeTotal = 0;

        foreach ($transactions as $transaction) {
            $amount = (float) $transaction->amount;
            if (($transaction->type ?? 'spending') === 'income') {
                $incomeTotal += $amount;
                continue;
            }
            $spendingTotal += $amount;
            $categoryTotals[$transaction->category] = ($categoryTotals[$transaction->category] ?? 0) + $amount;
        }

        arsort($categoryTotals);
        $top = array_slice($categoryTotals, 0, 3, true);
        $topParts = [];

        foreach ($top as $category => $amount) {
            $topParts[] = $category.' ($'.number_format($amount, 2).')';
        }

        $monthsWithData = $transactions
            ->groupBy(fn ($transaction) => $transaction->transaction_date->format('Y-m'))
            ->count();
        $monthsWithData = max(1, $monthsWithData);

        $avgSpent = $spendingTotal / $monthsWithData;
        $avgIncome = $incomeTotal / $monthsWithData;

        return [
            'spending_total' => '$'.number_format($spendingTotal, 2),
            'income_total' => '$'.number_format($incomeTotal, 2),
            'average_spent' => '$'.number_format($avgSpent, 2),
            'average_income' => '$'.number_format($avgIncome, 2),
            'count' => $count,
            'top_categories' => $topParts ? implode(', ', $topParts) : 'none yet',
        ];
    }

    private function summarizeSavings(int $userId): array
    {
        $journeys = SavingsJourney::query()->where('user_id', $userId)->get();
        $activeCount = $journeys->where('status', 'active')->count();
        $totalSaved = $journeys->sum('current_amount');
        $totalTarget = $journeys->sum('target_amount');

        return [
            'active_count' => $activeCount,
            'total_saved' => '$'.number_format($totalSaved, 2),
            'total_target' => $totalTarget ? '$'.number_format($totalTarget, 2) : 'not set',
        ];
    }

    private function summarizeIncomeTrend(int $userId): array
    {
        $start = now()->startOfMonth()->subMonths(2);
        $end = now()->endOfMonth();

        $incomeByMonth = Transaction::query()
            ->where('user_id', $userId)
            ->where('type', 'income')
            ->whereBetween('transaction_date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->groupBy(function ($transaction) {
                return (string) $transaction->transaction_date->format('Y-m');
            })
            ->map(fn ($items) => $items->sum('amount'));

        $months = [];
        for ($i = 0; $i < 3; $i++) {
            $monthKey = now()->startOfMonth()->subMonths($i)->format('Y-m');
            $months[] = (float) ($incomeByMonth[$monthKey] ?? 0);
        }

        $average = array_sum($months) / 3;
        $max = max($months);
        $min = min($months);

        $stability = 'steady';
        if ($average <= 0.01) {
            $stability = 'not enough data';
        } elseif (($max - $min) > $average * 0.35) {
            $stability = 'varied';
        }

        return [
            'average' => '$'.number_format($average, 2),
            'stability' => $stability,
        ];
    }
}
