<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class SpreadsheetExportService
{
    private const CURRENCY_FORMAT = '"$"#,##0.00';

    private const CATEGORY_GROUPS = [
        'Groceries' => 'Needs',
        'Dining' => 'Wants',
        'Transportation' => 'Needs',
        'Housing' => 'Needs',
        'School' => 'Needs',
        'Shopping' => 'Wants',
        'Subscriptions' => 'Needs',
        'Misc' => 'Wants',
        'Future' => 'Future',
    ];

    /**
     * @return array{filename: string, binary: string}
     */
    public function generate(User $user, Carbon $startDate, Carbon $endDate, bool $allowMonthlySnapshot): array
    {
        $transactions = $this->loadTransactions($user, $startDate, $endDate);
        $spendingTransactions = $transactions->where('transaction_type', 'spending')->values();
        $incomeTransactions = $transactions->where('transaction_type', 'income')->values();

        $needsTotal = (float) $spendingTransactions->where('budget_type', 'Needs')->sum('amount');
        $wantsTotal = (float) $spendingTransactions->where('budget_type', 'Wants')->sum('amount');
        $futureTotal = (float) $spendingTransactions->where('budget_type', 'Future')->sum('amount');
        $totalExpenses = $needsTotal + $wantsTotal + $futureTotal;
        $totalIncome = (float) $incomeTransactions->sum('amount');
        $netBalance = $totalIncome - $totalExpenses;

        $workbook = new Spreadsheet();

        $overviewSheet = $workbook->getActiveSheet();
        $overviewSheet->setTitle('Overview');
        $this->setupSheet($overviewSheet, [32, 4, 14, 3, 28, 12, 12, 12]);
        $this->populateOverviewSheet(
            $overviewSheet,
            $startDate,
            $endDate,
            $totalIncome,
            $totalExpenses,
            $netBalance,
            $needsTotal,
            $wantsTotal,
            $futureTotal,
            $spendingTransactions,
            $incomeTransactions
        );

        $spendingByCategorySheet = $workbook->createSheet();
        $spendingByCategorySheet->setTitle('Spending by Category');
        $this->setupSheet($spendingByCategorySheet, [32, 18, 22]);
        $this->populateSpendingByCategorySheet($spendingByCategorySheet, $spendingTransactions);

        $transactionsSheet = $workbook->createSheet();
        $transactionsSheet->setTitle('Transactions');
        $this->setupSheet($transactionsSheet, [14, 48, 20, 18, 16]);
        $this->populateTransactionsSheet($transactionsSheet, $transactions);

        $rangeSpansMultipleMonths = $startDate->format('Y-m') !== $endDate->format('Y-m');
        if ($allowMonthlySnapshot && $rangeSpansMultipleMonths) {
            $monthlySnapshotSheet = $workbook->createSheet();
            $monthlySnapshotSheet->setTitle('Monthly Snapshot');
            $this->setupSheet($monthlySnapshotSheet, [20, 16, 16, 16]);
            $this->populateMonthlySnapshotSheet($monthlySnapshotSheet, $transactions, $startDate, $endDate);
        }

        $workbook->setActiveSheetIndex(0);

        $binary = $this->writeBinary($workbook);

        return [
            'filename' => sprintf('penny-budget-%s.xlsx', $startDate->format('Y-m')),
            'binary' => $binary,
        ];
    }

    private function loadTransactions(User $user, Carbon $startDate, Carbon $endDate): Collection
    {
        return Transaction::query()
            ->where('user_id', $user->id)
            ->whereDate('transaction_date', '>=', $startDate->toDateString())
            ->whereDate('transaction_date', '<=', $endDate->toDateString())
            ->where(function ($query) {
                $query->whereNull('source')
                    ->orWhereNotIn('source', ['demo', 'onboarding_demo']);
            })
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get()
            ->map(function (Transaction $transaction) {
                $type = $transaction->type === 'income' ? 'income' : 'spending';
                $category = trim((string) $transaction->category);
                $description = trim((string) ($transaction->note ?? ''));
                $budgetType = $type === 'income'
                    ? 'Income'
                    : $this->resolveBudgetType($category);

                return [
                    'date' => optional($transaction->transaction_date)->format('Y-m-d') ?: now()->toDateString(),
                    'description' => $description !== '' ? $description : 'Unlabeled transaction',
                    'category' => $category !== '' ? $category : 'Uncategorized',
                    'transaction_type' => $type,
                    'budget_type' => $budgetType,
                    'amount' => (float) $transaction->amount,
                ];
            })
            ->values();
    }

    private function resolveBudgetType(string $category): string
    {
        if (isset(self::CATEGORY_GROUPS[$category])) {
            return self::CATEGORY_GROUPS[$category];
        }

        return strcasecmp($category, 'Future') === 0 ? 'Future' : 'Wants';
    }

    private function setupSheet(Worksheet $sheet, array $columnWidths): void
    {
        $sheet->setShowGridlines(false);

        foreach ($columnWidths as $index => $width) {
            $column = Coordinate::stringFromColumnIndex($index + 1);
            $sheet->getColumnDimension($column)->setWidth((float) $width);
        }

        $sheet->getDefaultRowDimension()->setRowHeight(22);
    }

    private function populateOverviewSheet(
        Worksheet $sheet,
        Carbon $startDate,
        Carbon $endDate,
        float $totalIncome,
        float $totalExpenses,
        float $netBalance,
        float $needsTotal,
        float $wantsTotal,
        float $futureTotal,
        Collection $spendingTransactions,
        Collection $incomeTransactions
    ): void {
        $monthLabel = $startDate->format('Y-m') === $endDate->format('Y-m')
            ? $startDate->format('F Y')
            : $startDate->format('M j, Y').' - '.$endDate->format('M j, Y');

        $sheet->getStyle('A1:H60')->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'F7F3ED'],
            ],
        ]);

        $sheet->mergeCells('A1:C1');
        $sheet->setCellValue('A1', $monthLabel);
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 24, 'color' => ['rgb' => '2F4B40']],
        ]);

        $sheet->mergeCells('E1:H1');
        $sheet->setCellValue('E1', 'Penny Monthly Budget Sheet');
        $sheet->getStyle('E1:H1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 13, 'color' => ['rgb' => '4A4A4A']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
        ]);

        $incomeGoal = round($totalIncome * 1.05, 2);
        $billsLimit = round($needsTotal * 0.45, 2);
        $debtLimit = round($totalExpenses * 0.20, 2);
        $expensesLimit = round(max($totalExpenses, ($needsTotal + $wantsTotal + $futureTotal)), 2);
        $savingsGoal = round($totalIncome * 0.15, 2);

        $this->stylePanel($sheet, 'A4:C11', 'A4:C4', 'A4', 'Monthly goals', 'B99669');
        $sheet->fromArray(
            [
                ['Goal', '', 'Amount'],
                ['Savings Goal', '', $savingsGoal],
                ['Income Goal', '', $totalIncome],
                ['Bills Limit', '', $billsLimit],
                ['Debt Limit', '', $debtLimit],
                ['Expenses Limit', '', $expensesLimit],
            ],
            null,
            'A5',
            true
        );
        $this->applyTableHeaderStyle($sheet, 'A5:C5');
        $sheet->getStyle('C6:C10')->getNumberFormat()->setFormatCode(self::CURRENCY_FORMAT);
        $sheet->getStyle('C6:C10')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        $this->stylePanel($sheet, 'E4:H11', 'E4:H4', 'E4', 'Amount left to spend', '8CA689');
        $sheet->fromArray(
            [
                ['Metric', '', '', 'Value'],
                ['Current balance', '', '', $netBalance],
                ['Money in', '', '', $totalIncome],
                ['Total spent', '', '', $totalExpenses],
                ['Amount left to spend', '', '', $netBalance],
                ['Needs / Wants / Future', '', '', sprintf('%d%% / %d%% / %d%%',
                    (int) round($this->safePercent($needsTotal, $totalExpenses) * 100),
                    (int) round($this->safePercent($wantsTotal, $totalExpenses) * 100),
                    (int) round($this->safePercent($futureTotal, $totalExpenses) * 100)
                )],
            ],
            null,
            'E5',
            true
        );
        $this->applyTableHeaderStyle($sheet, 'E5:H5');
        $sheet->getStyle('H6:H9')->getNumberFormat()->setFormatCode(self::CURRENCY_FORMAT);
        $sheet->getStyle('H6:H11')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        $this->stylePanel($sheet, 'A13:H16', 'A13:H13', 'A13', 'Money mindset', 'C9B08D');
        $sheet->mergeCells('A14:H14');
        $sheet->mergeCells('A15:H15');
        $sheet->mergeCells('A16:H16');
        $sheet->setCellValue('A14', 'Every decision is a seed for abundance, growing into freedom and stability.');
        $sheet->setCellValue('A15', 'I transform challenges into opportunities, mastering money with calm intention.');
        $sheet->setCellValue('A16', 'I am the architect of my financial future, building wealth that honors my goals.');
        $sheet->getStyle('A14:A16')->applyFromArray([
            'font' => ['italic' => true, 'size' => 10, 'color' => ['rgb' => '5A5A5A']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        $spendingByCategory = $spendingTransactions
            ->groupBy('category')
            ->map(fn (Collection $items) => (float) $items->sum('amount'))
            ->sortDesc();

        $debtActual = (float) $spendingByCategory
            ->filter(fn (float $value, string $category) => $this->isDebtCategory($category))
            ->sum();
        $billsActual = (float) $spendingByCategory
            ->filter(fn (float $value, string $category) => $this->isBillCategory($category))
            ->sum();

        $this->stylePanel($sheet, 'A18:C27', 'A18:C18', 'A18', 'Cashflow summary', 'B99669');
        $sheet->fromArray(
            [
                ['Item', 'Budget', 'Actual'],
                ['Rollover', 0, 0],
                ['Income', $incomeGoal, $totalIncome],
                ['Expenses', $expensesLimit, $totalExpenses],
                ['Savings', $savingsGoal, $futureTotal],
                ['Debt', $debtLimit, $debtActual],
                ['Bills', $billsLimit, $billsActual],
                ['Total Leftover', $incomeGoal - $expensesLimit, $netBalance],
            ],
            null,
            'A19',
            true
        );
        $this->applyTableHeaderStyle($sheet, 'A19:C19');
        $sheet->getStyle('B20:C26')->getNumberFormat()->setFormatCode(self::CURRENCY_FORMAT);
        $sheet->getStyle('B20:C26')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        $this->stylePanel($sheet, 'E18:H27', 'E18:H18', 'E18', 'Expenses summary', 'B99669');
        $sheet->fromArray([['Category', '', 'Budget', 'Actual']], null, 'E19', true);
        $this->applyTableHeaderStyle($sheet, 'E19:H19');

        $expenseRow = 20;
        foreach ($spendingByCategory->take(7) as $category => $amount) {
            $sheet->fromArray([[(string) $category, '', round((float) $amount * 1.1, 2), (float) $amount]], null, "E{$expenseRow}", true);
            $expenseRow++;
        }
        if ($expenseRow === 20) {
            $sheet->fromArray([['No spending entries', '', 0, 0]], null, "E{$expenseRow}", true);
            $expenseRow++;
        }
        $sheet->getStyle('G20:H'.max($expenseRow - 1, 20))->getNumberFormat()->setFormatCode(self::CURRENCY_FORMAT);
        $sheet->getStyle('G20:H'.max($expenseRow - 1, 20))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        $this->stylePanel($sheet, 'A29:C39', 'A29:C29', 'A29', 'Bill tracker', '8CA689');
        $sheet->fromArray([['Bill', 'Budget', 'Actual']], null, 'A30', true);
        $this->applyTableHeaderStyle($sheet, 'A30:C30');

        $billRow = 31;
        foreach ($spendingByCategory->filter(fn (float $value, string $category) => $this->isBillCategory($category))->take(8) as $category => $amount) {
            $sheet->fromArray([[(string) $category, round((float) $amount * 1.1, 2), (float) $amount]], null, "A{$billRow}", true);
            $billRow++;
        }
        if ($billRow === 31) {
            $sheet->fromArray([['No recurring bills detected', 0, 0]], null, "A{$billRow}", true);
            $billRow++;
        }
        $sheet->getStyle('B31:C'.max($billRow - 1, 31))->getNumberFormat()->setFormatCode(self::CURRENCY_FORMAT);
        $sheet->getStyle('B31:C'.max($billRow - 1, 31))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        $this->stylePanel($sheet, 'E29:H39', 'E29:H29', 'E29', 'Income tracker', '8CA689');
        $sheet->fromArray([['Income source', '', 'Expected', 'Actual']], null, 'E30', true);
        $this->applyTableHeaderStyle($sheet, 'E30:H30');

        $incomeRow = 31;
        if ($incomeTransactions->isEmpty()) {
            $sheet->fromArray([['No income entries', '', 0, 0]], null, "E{$incomeRow}", true);
            $sheet->getStyle("G{$incomeRow}:H{$incomeRow}")->getNumberFormat()->setFormatCode(self::CURRENCY_FORMAT);
            $sheet->getStyle("G{$incomeRow}:H{$incomeRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        } else {
            $incomeExpected = round($totalIncome / max(1, $incomeTransactions->count()), 2);
            foreach ($incomeTransactions->take(8) as $index => $income) {
                $label = (string) $income['description'];
                if ($label === '' || strcasecmp($label, 'Unlabeled transaction') === 0) {
                    $label = 'Income stream '.($index + 1);
                }
                $sheet->fromArray([[$label, '', $incomeExpected, (float) $income['amount']]], null, "E{$incomeRow}", true);
                $incomeRow++;
            }
            $sheet->getStyle('G31:H'.($incomeRow - 1))->getNumberFormat()->setFormatCode(self::CURRENCY_FORMAT);
            $sheet->getStyle('G31:H'.($incomeRow - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }

        $this->stylePanel($sheet, 'A41:C52', 'A41:C41', 'A41', 'Savings tracker', '8CA689');
        $sheet->fromArray(
            [
                ['Goal', 'Expected', 'Actual'],
                ['Emergency Fund', round($savingsGoal * 0.50, 2), round($futureTotal * 0.50, 2)],
                ['Vacation Fund', round($savingsGoal * 0.30, 2), round($futureTotal * 0.30, 2)],
                ['Education Fund', round($savingsGoal * 0.20, 2), round($futureTotal * 0.20, 2)],
            ],
            null,
            'A42',
            true
        );
        $this->applyTableHeaderStyle($sheet, 'A42:C42');
        $sheet->getStyle('B43:C45')->getNumberFormat()->setFormatCode(self::CURRENCY_FORMAT);
        $sheet->getStyle('B43:C45')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        $this->stylePanel($sheet, 'E41:H52', 'E41:H41', 'E41', 'Debt tracker', 'B99669');
        $sheet->fromArray([['Debt', '', 'Budget', 'Actual']], null, 'E42', true);
        $this->applyTableHeaderStyle($sheet, 'E42:H42');

        $debtRow = 43;
        foreach ($spendingByCategory->filter(fn (float $value, string $category) => $this->isDebtCategory($category))->take(7) as $category => $amount) {
            $sheet->fromArray([[(string) $category, '', round((float) $amount * 1.1, 2), (float) $amount]], null, "E{$debtRow}", true);
            $debtRow++;
        }
        if ($debtRow === 43) {
            $sheet->fromArray([['No debt payments recorded', '', 0, 0]], null, "E{$debtRow}", true);
            $debtRow++;
        }
        $sheet->getStyle('G43:H'.max($debtRow - 1, 43))->getNumberFormat()->setFormatCode(self::CURRENCY_FORMAT);
        $sheet->getStyle('G43:H'.max($debtRow - 1, 43))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        [$largestCategory, $largestAmount] = $this->largestSpendingCategory($spendingTransactions);
        $sheet->mergeCells('A54:H54');
        $sheet->mergeCells('A55:H55');
        $sheet->setCellValue(
            'A54',
            $largestCategory === null
                ? 'Largest spending category: no spending categories yet.'
                : sprintf('Largest spending category: %s (%s)', $largestCategory, $this->formatCurrency($largestAmount))
        );
        $sheet->setCellValue(
            'A55',
            sprintf('Needs represent %d%% of total spending.', (int) round($this->safePercent($needsTotal, $totalExpenses) * 100))
        );
        $sheet->getStyle('A54:A55')->applyFromArray([
            'font' => ['size' => 11, 'color' => ['rgb' => '4B4B4B']],
        ]);

        $sheet->setCellValue('A57', 'Generated by Penny - AI Budgeting Assistant');
        $sheet->getStyle('A57')->applyFromArray([
            'font' => ['size' => 10, 'color' => ['rgb' => '7A7A7A']],
        ]);
    }

    private function populateSpendingByCategorySheet(Worksheet $sheet, Collection $spendingTransactions): void
    {
        $sheet->setCellValue('A1', 'Spending by Category');
        $sheet->getStyle('A1')->applyFromArray($this->titleStyle());

        $sheet->fromArray([['Category', 'Total Spent', 'Transaction Count']], null, 'A2', true);
        $this->applyHeaderStyle($sheet, 'A2:C2');

        $grouped = $spendingTransactions
            ->groupBy(fn (array $transaction) => $transaction['category'])
            ->map(fn (Collection $entries, string $category) => [
                'category' => $category !== '' ? $category : 'Uncategorized',
                'total' => (float) $entries->sum('amount'),
                'count' => (int) $entries->count(),
            ])
            ->sortByDesc('total')
            ->values();

        $row = 3;
        if ($grouped->isEmpty()) {
            $sheet->fromArray([['No spending entries in this period.', 0, 0]], null, "A{$row}", true);
        } else {
            foreach ($grouped as $entry) {
                $sheet->fromArray([[$entry['category'], $entry['total'], $entry['count']]], null, "A{$row}", true);
                $row++;
            }
        }

        $lastDataRow = max(3, $row - 1);
        $sheet->getStyle("B3:B{$lastDataRow}")->getNumberFormat()->setFormatCode(self::CURRENCY_FORMAT);
        $sheet->getStyle("B3:C{$lastDataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    }

    private function populateTransactionsSheet(Worksheet $sheet, Collection $transactions): void
    {
        $sheet->setCellValue('A1', 'Transactions');
        $sheet->getStyle('A1')->applyFromArray($this->titleStyle());

        $sheet->fromArray([['Date', 'Description', 'Category', 'Type', 'Amount']], null, 'A2', true);
        $this->applyHeaderStyle($sheet, 'A2:E2');

        $row = 3;
        if ($transactions->isEmpty()) {
            $sheet->fromArray([['', 'No transactions in this period.', '', '', 0]], null, "A{$row}", true);
            $sheet->getStyle("E{$row}")->getNumberFormat()->setFormatCode(self::CURRENCY_FORMAT);
            return;
        }

        foreach ($transactions as $transaction) {
            $sheet->fromArray([[
                $transaction['date'],
                $transaction['description'],
                $transaction['category'],
                $transaction['budget_type'],
                $transaction['amount'],
            ]], null, "A{$row}", true);
            $row++;
        }

        $sheet->getStyle('E3:E'.($row - 1))->getNumberFormat()->setFormatCode(self::CURRENCY_FORMAT);
    }

    private function populateMonthlySnapshotSheet(
        Worksheet $sheet,
        Collection $transactions,
        Carbon $startDate,
        Carbon $endDate
    ): void {
        $sheet->setCellValue('A1', 'Monthly Snapshot');
        $sheet->getStyle('A1')->applyFromArray($this->titleStyle());

        $sheet->fromArray([['Month', 'Income', 'Expenses', 'Net']], null, 'A2', true);
        $this->applyHeaderStyle($sheet, 'A2:D2');

        $monthly = [];
        $cursor = $startDate->copy()->startOfMonth();
        $lastMonth = $endDate->copy()->startOfMonth();
        while ($cursor->lte($lastMonth)) {
            $key = $cursor->format('Y-m');
            $monthly[$key] = [
                'label' => $cursor->format('F Y'),
                'income' => 0.0,
                'expenses' => 0.0,
                'net' => 0.0,
            ];
            $cursor->addMonth();
        }

        foreach ($transactions as $transaction) {
            $monthKey = Carbon::parse($transaction['date'])->format('Y-m');
            if (! isset($monthly[$monthKey])) {
                continue;
            }

            if ($transaction['transaction_type'] === 'income') {
                $monthly[$monthKey]['income'] += (float) $transaction['amount'];
            } else {
                $monthly[$monthKey]['expenses'] += (float) $transaction['amount'];
            }
        }

        $row = 3;
        foreach ($monthly as $month) {
            $month['net'] = $month['income'] - $month['expenses'];
            $sheet->fromArray([[
                $month['label'],
                $month['income'],
                $month['expenses'],
                $month['net'],
            ]], null, "A{$row}", true);
            $row++;
        }

        if ($row === 3) {
            $sheet->fromArray([['No monthly data in this range.', 0, 0, 0]], null, "A{$row}", true);
            $row++;
        }

        $sheet->getStyle('B3:D'.($row - 1))->getNumberFormat()->setFormatCode(self::CURRENCY_FORMAT);
    }

    private function applyHeaderStyle(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray($this->headerStyle());
    }

    private function titleStyle(): array
    {
        return [
            'font' => [
                'bold' => true,
                'size' => 16,
                'color' => ['rgb' => '1F2D2A'],
            ],
        ];
    }

    private function sectionLabelStyle(): array
    {
        return [
            'font' => [
                'bold' => true,
                'size' => 11,
                'color' => ['rgb' => '30403D'],
            ],
        ];
    }

    private function headerStyle(): array
    {
        return [
            'font' => [
                'bold' => true,
                'size' => 11,
                'color' => ['rgb' => '2D2D2D'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'F3EFE7'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ];
    }

    private function applyTableHeaderStyle(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 10,
                'color' => ['rgb' => '2F2F2F'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'F3EEE5'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'D7CFC3'],
                ],
            ],
        ]);
    }

    private function stylePanel(
        Worksheet $sheet,
        string $panelRange,
        string $headerRange,
        string $headerCell,
        string $title,
        string $headerColor
    ): void {
        $sheet->getStyle($panelRange)->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'FFFFFF'],
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'D7CFC3'],
                ],
            ],
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        $sheet->mergeCells($headerRange);
        $sheet->setCellValue($headerCell, $title);
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 11,
                'color' => ['rgb' => 'FFFFFF'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => $headerColor],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'D7CFC3'],
                ],
            ],
        ]);
    }

    private function isBillCategory(string $category): bool
    {
        $normalized = strtolower($category);

        foreach (['housing', 'rent', 'mortgage', 'subscription', 'insurance', 'utilities', 'school', 'transportation'] as $keyword) {
            if (str_contains($normalized, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function isDebtCategory(string $category): bool
    {
        $normalized = strtolower($category);

        foreach (['debt', 'loan', 'credit', 'card'] as $keyword) {
            if (str_contains($normalized, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function writeBinary(Spreadsheet $workbook): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'penny-xlsx-');
        if ($tempFile === false) {
            throw new \RuntimeException('Unable to create temporary spreadsheet file.');
        }

        try {
            $writer = new Xlsx($workbook);
            $writer->save($tempFile);

            $binary = file_get_contents($tempFile);
            if ($binary === false) {
                throw new \RuntimeException('Unable to read generated spreadsheet.');
            }

            return $binary;
        } finally {
            @unlink($tempFile);
            $workbook->disconnectWorksheets();
            unset($workbook);
        }
    }

    private function safePercent(float $part, float $whole): float
    {
        if ($whole <= 0) {
            return 0.0;
        }

        return $part / $whole;
    }

    /**
     * @return array{0:?string,1:float}
     */
    private function largestSpendingCategory(Collection $spendingTransactions): array
    {
        if ($spendingTransactions->isEmpty()) {
            return [null, 0.0];
        }

        $grouped = $spendingTransactions
            ->groupBy('category')
            ->map(fn (Collection $items) => (float) $items->sum('amount'))
            ->sortDesc();

        $topCategory = $grouped->keys()->first();
        $topAmount = (float) ($grouped->first() ?? 0.0);

        return [$topCategory, $topAmount];
    }

    private function formatCurrency(float $amount): string
    {
        return '$'.number_format($amount, 2, '.', ',');
    }
}
