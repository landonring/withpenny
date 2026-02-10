<?php

namespace App\Http\Controllers;

use App\Models\SavingsJourney;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class AiController extends Controller
{
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

    public function monthlyReflection(Request $request)
    {
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

        $prompt = "Monthly overview request.\n"
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

        return $this->respondWithAi($prompt, 180);
    }

    public function weeklyCheckIn(Request $request)
    {
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

        $prompt = "Weekly check-in request.\n"
            ."Week range: ".$start->format('M j')." to ".$end->format('M j').".\n"
            ."Transactions count: {$summary['count']}.\n"
            ."Total spent: {$summary['spending_total']}.\n"
            ."Total income: {$summary['income_total']}.\n"
            ."Top categories: {$summary['top_categories']}.\n"
            ."Average monthly income (last 3 months): {$incomeTrend['average']}.\n"
            ."Income stability: {$incomeTrend['stability']}.\n"
            ."Write 2-3 sentences (25-45 words total) focused on encouragement. End with a complete sentence.";

        return $this->respondWithAi($prompt, 120);
    }

    public function yearlyReflection(Request $request)
    {
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

        $prompt = "Yearly overview request.\n"
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

        return $this->respondWithAi($prompt, 220);
    }

    public function chat(Request $request)
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $this->debugEnabled = $request->boolean('debug');
        $user = $request->user();

        $transactions = Transaction::query()
            ->where('user_id', $user->id)
            ->whereBetween('transaction_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
            ->get();

        $summary = $this->summarizeTransactions($transactions);
        $incomeTrend = $this->summarizeIncomeTrend($user->id);
        $savings = $this->summarizeSavings($user->id);

        $prompt = "User message: {$validated['message']}\n"
            ."Context (optional): This month transactions {$summary['count']}, spent {$summary['spending_total']}, income {$summary['income_total']}, top categories {$summary['top_categories']}. "
            ."Average monthly income (last 3 months): {$incomeTrend['average']}, stability {$incomeTrend['stability']}. "
            ."Savings active {$savings['active_count']}, saved {$savings['total_saved']}.\n"
            ."If the user expresses self-criticism, start with reassurance before any reflection. Reply in 1-2 short sentences (12-24 words). End with a complete sentence.";

        return $this->respondWithAi($prompt, 80);
    }

    private function respondWithAi(string $prompt, int $maxTokens = 80)
    {
        @set_time_limit(120);
        $model = config('services.ollama.model', 'llama3.1');
        $configured = rtrim(config('services.ollama.base_url', 'http://127.0.0.1:11434'), '/');
        $candidates = array_values(array_unique([
            $configured,
            'http://127.0.0.1:11434',
            'http://localhost:11434',
        ]));

        $baseUrl = null;
        foreach ($candidates as $candidate) {
            if ($this->ollamaReachable($candidate)) {
                $baseUrl = $candidate;
                break;
            }
        }

        if (! $baseUrl) {
            return response()->json(array_merge([
                'message' => 'Penny is resting right now. You can try again in a little while.',
            ], $this->debugData([
                'reason' => 'ollama_unreachable',
                'candidates' => $candidates,
                'model' => $model,
            ])), 503);
        }

        try {
            $response = Http::withOptions(['proxy' => null])
                ->connectTimeout(5)
                ->timeout(60)
                ->post("{$baseUrl}/api/generate", [
                    'model' => $model,
                    'system' => self::SYSTEM_PROMPT,
                    'prompt' => $prompt,
                    'stream' => false,
                    'keep_alive' => '10m',
                    'options' => [
                        'num_predict' => $maxTokens,
                    ],
                ]);
        } catch (\Throwable $exception) {
            return response()->json(array_merge([
                'message' => 'Penny is resting right now. You can try again in a little while.',
            ], $this->debugData([
                'reason' => 'exception',
                'base_url' => $baseUrl,
                'model' => $model,
                'exception' => get_class($exception),
                'error' => $exception->getMessage(),
            ])), 503);
        }

        if (! $response->successful()) {
            return response()->json(array_merge([
                'message' => 'Penny is resting right now. You can try again in a little while.',
            ], $this->debugData([
                'reason' => 'ollama_error',
                'status' => $response->status(),
                'body' => mb_substr((string) $response->body(), 0, 300),
            ])), 503);
        }

        $data = $response->json();
        $text = trim((string) ($data['response'] ?? ''));

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

    private function ollamaReachable(string $baseUrl): bool
    {
        $parts = parse_url($baseUrl);
        if (! $parts || empty($parts['host'])) {
            return false;
        }

        $host = $parts['host'];
        $port = $parts['port'] ?? (($parts['scheme'] ?? 'http') === 'https' ? 443 : 80);

        $fp = @fsockopen($host, $port, $errno, $errstr, 1.5);
        if (! $fp) {
            return false;
        }

        fclose($fp);
        return true;
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
