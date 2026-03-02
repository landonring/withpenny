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
use Illuminate\Support\Str;
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

    private const SYSTEM_PROMPT = "You are Penny AI, a calm financial analyst for budgeting.
Penny AI is observational, practical, structured, brief, and neutral.
Penny AI is not a therapist, not motivational, and not dramatic.

Output rules:
- Refer to yourself only as 'Penny AI'.
- Never use first-person language: no 'I', 'me', 'my', or 'we'.
- Avoid direct instruction phrasing like 'you should'.
- Use neutral phrasing such as 'Penny AI suggests' or 'Penny AI observes'.
- No exclamation marks.
- Keep response length under 120 words unless detail is explicitly requested.
- Provide at most one actionable suggestion.

When financial data is available and analysis is requested, use this structure:
Observation:
Meaning:
Action:

When financial data is insufficient, return exactly:
'Penny AI does not see enough data yet. Start by tracking every purchase for 7 days to establish a baseline.'

When describing spreadsheet generation, keep it brief and structural:
'Penny AI generated a monthly budget spreadsheet organized by Needs, Wants, and Future categories. Totals and summaries are automated.'";

    private const NO_DATA_MESSAGE = 'Penny AI does not see enough data yet. Start by tracking every purchase for 7 days to establish a baseline.';

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
        if (($summary['count'] ?? 0) === 0) {
            return response()->json(['message' => self::NO_DATA_MESSAGE]);
        }

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
            ."If transactions count is zero, return exactly: '".self::NO_DATA_MESSAGE."'\n"
            ."Return under 120 words.\n"
            ."Use exactly this format with one action only:\n"
            ."Observation:\nMeaning:\nAction: Penny AI suggests ...";

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
        if (($summary['count'] ?? 0) === 0) {
            return response()->json(['message' => self::NO_DATA_MESSAGE]);
        }

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
            ."If transactions count is zero, return exactly: '".self::NO_DATA_MESSAGE."'\n"
            ."Return under 120 words.\n"
            ."Use exactly this format with one action only:\n"
            ."Observation:\nMeaning:\nAction: Penny AI suggests ...";

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
        if (($summary['count'] ?? 0) === 0) {
            return response()->json(['message' => self::NO_DATA_MESSAGE]);
        }

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
            ."If transactions count is zero, return exactly: '".self::NO_DATA_MESSAGE."'\n"
            ."Return under 120 words.\n"
            ."Use exactly this format with one action only:\n"
            ."Observation:\nMeaning:\nAction: Penny AI suggests ...";

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
        if (($summary['count'] ?? 0) === 0) {
            return response()->json(['message' => self::NO_DATA_MESSAGE]);
        }

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
            ."If transactions count is zero, return exactly: '".self::NO_DATA_MESSAGE."'\n"
            ."Return under 120 words.\n"
            ."Use exactly this format with one action only:\n"
            ."Observation:\nMeaning:\nAction: Penny AI suggests ...";

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
        $rawMessage = trim((string) $validated['message']);

        if (! $isOnboarding && ! $this->userHasTransactions($user->id)) {
            return response()->json(['message' => self::NO_DATA_MESSAGE]);
        }

        if ($this->isSpreadsheetRequest($rawMessage)) {
            return response()->json([
                'message' => 'Penny AI generated a monthly budget spreadsheet organized by Needs, Wants, and Future categories. Totals and summaries are automated.',
                'action' => [
                    'type' => 'download_spreadsheet',
                    'label' => 'Download spreadsheet',
                    'payload' => [
                        'month' => now()->format('Y-m'),
                    ],
                ],
            ]);
        }

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

        $prompt .= "User message: {$rawMessage}\n".$lifePhaseContext;

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

        $prompt .= "If there is insufficient financial data, return exactly: '".self::NO_DATA_MESSAGE."'\n"
            ."If financial analysis is requested, use this exact structure with one action only:\n"
            ."Observation:\nMeaning:\nAction: Penny AI suggests ...\n"
            ."Keep output neutral, structured, and under 120 words. No exclamation marks.";

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
                'message' => 'Penny AI is temporarily unavailable. Please try again in a moment.',
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
                'message' => 'Penny AI is temporarily unavailable. Please try again in a moment.',
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
            $text = 'Penny AI is temporarily unavailable. Please try again in a moment.';
        }
        $text = $this->normalizeAiText($text);

        return response()->json([
            'message' => $text,
        ]);
    }

    private function normalizeAiText(string $text): string
    {
        $normalized = trim(preg_replace('/\s+/', ' ', str_replace('!', '.', $text)) ?? '');
        if ($normalized === '') {
            return self::NO_DATA_MESSAGE;
        }

        $replacements = [
            "/\bI'm\b/i" => 'Penny AI is',
            "/\bI am\b/i" => 'Penny AI is',
            "/\bI\b/i" => 'Penny AI',
            "/\bme\b/i" => 'Penny AI',
            "/\bmy\b/i" => "Penny AI's",
            "/\bwe\b/i" => 'Penny AI',
            '/\byou should\b/i' => 'Penny AI suggests',
        ];
        foreach ($replacements as $pattern => $replacement) {
            $normalized = preg_replace($pattern, $replacement, $normalized) ?? $normalized;
        }

        if (! Str::contains($normalized, 'Penny AI')) {
            $normalized = 'Penny AI notes: '.$normalized;
        }

        $words = preg_split('/\s+/', trim($normalized)) ?: [];
        if (count($words) > 120) {
            $normalized = implode(' ', array_slice($words, 0, 120));
        }

        $normalized = trim($normalized);
        if (! preg_match('/[.?]["\']?$/', $normalized)) {
            $normalized .= '.';
        }

        return $normalized;
    }

    private function userHasTransactions(int $userId): bool
    {
        return Transaction::query()->where('user_id', $userId)->exists();
    }

    private function isSpreadsheetRequest(string $message): bool
    {
        $normalized = Str::of($message)->lower()->toString();

        $mentionsSpreadsheet = Str::contains($normalized, [
            'spreadsheet',
            'budget sheet',
            'excel',
            'xlsx',
            'download sheet',
        ]);
        $mentionsGeneration = Str::contains($normalized, [
            'generate',
            'make',
            'create',
            'build',
            'download',
            'export',
        ]);

        return $mentionsSpreadsheet && $mentionsGeneration;
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
