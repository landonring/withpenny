<?php

namespace App\Services;

use Carbon\Carbon;

class DemoDataService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function dashboardTransactions(?string $monthKey = null): array
    {
        $month = $this->resolveMonth($monthKey);
        $template = [
            ['day' => 1, 'type' => 'income', 'amount' => 2450.00, 'category' => 'Income', 'note' => 'Payroll deposit'],
            ['day' => 2, 'type' => 'spending', 'amount' => 965.00, 'category' => 'Housing', 'note' => 'Rent'],
            ['day' => 3, 'type' => 'spending', 'amount' => 82.45, 'category' => 'Groceries', 'note' => 'Whole Foods'],
            ['day' => 4, 'type' => 'spending', 'amount' => 14.99, 'category' => 'Subscriptions', 'note' => 'Music subscription'],
            ['day' => 5, 'type' => 'income', 'amount' => 120.00, 'category' => 'Income', 'note' => 'Freelance payout'],
            ['day' => 6, 'type' => 'spending', 'amount' => 34.12, 'category' => 'Dining', 'note' => 'Lunch'],
            ['day' => 7, 'type' => 'spending', 'amount' => 22.30, 'category' => 'Transportation', 'note' => 'Gas'],
            ['day' => 8, 'type' => 'spending', 'amount' => 58.44, 'category' => 'Shopping', 'note' => 'Home supplies'],
            ['day' => 9, 'type' => 'spending', 'amount' => 48.10, 'category' => 'Groceries', 'note' => 'Trader Joe\'s'],
            ['day' => 10, 'type' => 'income', 'amount' => 75.25, 'category' => 'Income', 'note' => 'Interest and bonus'],
            ['day' => 11, 'type' => 'spending', 'amount' => 41.60, 'category' => 'Dining', 'note' => 'Dinner'],
            ['day' => 12, 'type' => 'spending', 'amount' => 17.30, 'category' => 'Transportation', 'note' => 'Parking'],
            ['day' => 13, 'type' => 'spending', 'amount' => 36.15, 'category' => 'School', 'note' => 'Course materials'],
            ['day' => 14, 'type' => 'income', 'amount' => 2450.00, 'category' => 'Income', 'note' => 'Payroll deposit'],
            ['day' => 15, 'type' => 'spending', 'amount' => 90.00, 'category' => 'Groceries', 'note' => 'Costco'],
            ['day' => 16, 'type' => 'spending', 'amount' => 65.20, 'category' => 'Transportation', 'note' => 'Fuel'],
            ['day' => 18, 'type' => 'spending', 'amount' => 145.00, 'category' => 'Shopping', 'note' => 'Clothing'],
            ['day' => 19, 'type' => 'spending', 'amount' => 52.40, 'category' => 'Dining', 'note' => 'Family dinner'],
            ['day' => 20, 'type' => 'spending', 'amount' => 18.00, 'category' => 'Subscriptions', 'note' => 'Cloud storage'],
            ['day' => 21, 'type' => 'income', 'amount' => 65.00, 'category' => 'Income', 'note' => 'Cashback rewards'],
            ['day' => 22, 'type' => 'spending', 'amount' => 59.99, 'category' => 'Misc', 'note' => 'Household'],
            ['day' => 24, 'type' => 'spending', 'amount' => 28.75, 'category' => 'Transportation', 'note' => 'Ride share'],
            ['day' => 26, 'type' => 'spending', 'amount' => 70.00, 'category' => 'Future', 'note' => 'Savings transfer'],
            ['day' => 28, 'type' => 'spending', 'amount' => 84.20, 'category' => 'Groceries', 'note' => 'Grocery run'],
        ];

        return array_map(function (array $item, int $index) use ($month): array {
            $date = $month->copy()->day(min($item['day'], $month->daysInMonth));

            return [
                'id' => 'demo-tx-'.($index + 1),
                'user_id' => null,
                'amount' => $item['amount'],
                'category' => $item['category'],
                'note' => $item['note'],
                'transaction_date' => $date->toDateString(),
                'type' => $item['type'],
                'source' => 'demo',
                'created_at' => $date->copy()->setTime(10, 0)->toIso8601String(),
                'updated_at' => $date->copy()->setTime(10, 0)->toIso8601String(),
            ];
        }, $template, array_keys($template));
    }

    /**
     * @return array<string, mixed>
     */
    public function statementImportPayload(): array
    {
        $month = now()->startOfMonth();
        $rows = [
            ['day' => 2, 'description' => 'Payroll Direct Deposit - ACME INDUSTRIES', 'type' => 'income', 'amount' => 1650.00, 'category' => 'Income'],
            ['day' => 6, 'description' => 'Debit Card Purchase - Trader Joe\'s #142', 'type' => 'spending', 'amount' => 86.44, 'category' => 'Groceries'],
        ];

        $openingBalance = 2390.28;
        $incomeTotal = (float) collect($rows)->where('type', 'income')->sum('amount');
        $spendingTotal = (float) collect($rows)->where('type', 'spending')->sum('amount');
        $netMovement = $incomeTotal - $spendingTotal;
        $closingBalance = $openingBalance + $netMovement;
        $incomeEntries = (int) collect($rows)->where('type', 'income')->count();
        $spendingEntries = (int) collect($rows)->where('type', 'spending')->count();

        $transactions = array_map(function (array $row, int $index) use ($month): array {
            $date = $month->copy()->day(min($row['day'], $month->daysInMonth));

            return [
                'id' => 'demo-import-'.($index + 1),
                'date' => $date->toDateString(),
                'description' => $row['description'],
                'amount' => $row['amount'],
                'type' => $row['type'],
                'category' => $row['category'],
                'include' => true,
                'duplicate' => false,
            ];
        }, $rows, array_keys($rows));

        return [
            'transactions' => $transactions,
            'meta' => [
                'opening_balance' => round($openingBalance, 2),
                'closing_balance' => round($closingBalance, 2),
                'included_entries' => count($rows),
                'income_entries' => $incomeEntries,
                'spending_entries' => $spendingEntries,
                'total_income_entries' => round($incomeTotal, 2),
                'total_spending_entries' => round($spendingTotal, 2),
                'net_movement_entries' => round($netMovement, 2),
                'plan_window' => null,
                'extraction' => [
                    'extraction_method' => 'pdf_text',
                    'extraction_confidence' => 'high',
                    'balance_mismatch' => false,
                ],
            ],
            'extraction_confidence' => 'high',
            'balance_mismatch' => false,
            'extraction_method' => 'pdf_text',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function insights(): array
    {
        return [
            'daily' => 'Today shows steady essentials and no unusual spikes. This is a stable day. Keep the same pace and review again tomorrow.',
            'weekly' => 'This week stayed balanced between needs and flexible spending. Income covered expenses with room left over. A small transfer to savings keeps momentum calm.',
            'monthly' => 'This month shows reliable income and controlled spending in key categories. Essentials remain the largest share, which is expected. Your cash flow stayed positive overall.',
            'yearly' => 'The year so far is steady. Income and spending trends are consistent, with no severe volatility. Maintaining this rhythm can support stronger savings decisions over time.',
        ];
    }

    /**
     * @param array<int, array<string, string>> $history
     */
    public function chatReply(string $message, array $history = []): string
    {
        $text = mb_strtolower(trim($message));

        if ($text === '') {
            return 'Share one money question and Penny will help you break it into a small next step.';
        }

        if (str_contains($text, 'budget') || str_contains($text, 'plan')) {
            return 'Start with one fixed bill and one flexible category. Set a weekly check-in so adjustments stay small and manageable.';
        }

        if (str_contains($text, 'debt') || str_contains($text, 'card')) {
            return 'List balances from highest rate to lowest. Paying a little extra on the highest rate first usually reduces cost over time.';
        }

        if (str_contains($text, 'save') || str_contains($text, 'savings')) {
            return 'Pick a target amount and date, then set a repeat transfer that feels sustainable. Consistency is more important than size.';
        }

        if (str_contains($text, 'stress') || str_contains($text, 'overwhelm') || str_contains($text, 'anxious')) {
            return 'Take one calm pass through this month only. Focus on what is due first, then choose a single category to tighten this week.';
        }

        $last = $history[count($history) - 1]['user'] ?? null;
        if (is_string($last) && $last !== '') {
            return 'That builds on your previous note. Keep this focused on one decision you can complete today, then review impact in a week.';
        }

        return 'A good next step is to separate fixed bills from flexible spending, then decide one adjustment you can hold for the next seven days.';
    }

    private function resolveMonth(?string $monthKey): Carbon
    {
        if (is_string($monthKey) && preg_match('/^\d{4}-\d{2}$/', $monthKey)) {
            try {
                return Carbon::createFromFormat('Y-m', $monthKey)->startOfMonth();
            } catch (\Throwable) {
                // ignore and use current month
            }
        }

        return now()->startOfMonth();
    }
}
