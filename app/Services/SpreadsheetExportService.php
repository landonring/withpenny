<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
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
        $this->setupSheet($overviewSheet, [36, 22, 20]);
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
            $spendingTransactions
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
        Collection $spendingTransactions
    ): void {
        $rangeLabel = $startDate->format('Y-m') === $endDate->format('Y-m')
            ? 'Month: '.$startDate->format('F Y')
            : 'Range: '.$startDate->format('M j, Y').' - '.$endDate->format('M j, Y');

        $sheet->setCellValue('A1', 'Penny Budget Overview');
        $sheet->setCellValue('A2', $rangeLabel);
        $sheet->getStyle('A1')->applyFromArray($this->titleStyle());

        $sheet->fromArray(
            [
                ['Metric', 'Amount'],
                ['Total Income', $totalIncome],
                ['Total Expenses', $totalExpenses],
                ['Net Balance', $netBalance],
            ],
            null,
            'A4',
            true
        );
        $this->applyHeaderStyle($sheet, 'A4:B4');
        $sheet->getStyle('B5:B7')->getNumberFormat()->setFormatCode(self::CURRENCY_FORMAT);
        $sheet->getStyle('B5:B7')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        $sheet->setCellValue('A9', 'Needs / Wants / Future');
        $sheet->getStyle('A9')->applyFromArray($this->sectionLabelStyle());
        $sheet->fromArray(
            [
                ['Category Type', 'Total', '% of Spending'],
                ['Needs', $needsTotal, $this->safePercent($needsTotal, $totalExpenses)],
                ['Wants', $wantsTotal, $this->safePercent($wantsTotal, $totalExpenses)],
                ['Future', $futureTotal, $this->safePercent($futureTotal, $totalExpenses)],
            ],
            null,
            'A10',
            true
        );
        $this->applyHeaderStyle($sheet, 'A10:C10');
        $sheet->getStyle('B11:B13')->getNumberFormat()->setFormatCode(self::CURRENCY_FORMAT);
        $sheet->getStyle('C11:C13')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_PERCENTAGE_00);
        $sheet->getStyle('B11:C13')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        [$largestCategory, $largestAmount] = $this->largestSpendingCategory($spendingTransactions);
        $sheet->setCellValue('A15', 'Insight Summary');
        $sheet->getStyle('A15')->applyFromArray($this->sectionLabelStyle());

        if ($largestCategory === null) {
            $sheet->setCellValue('A16', 'Largest spending category: No spending categories yet.');
            $sheet->setCellValue('A17', 'Needs represent 0% of your total spending.');
        } else {
            $sheet->setCellValue(
                'A16',
                sprintf('Largest spending category: %s (%s)', $largestCategory, $this->formatCurrency($largestAmount))
            );
            $sheet->setCellValue(
                'A17',
                sprintf(
                    'Needs represent %d%% of your total spending.',
                    (int) round($this->safePercent($needsTotal, $totalExpenses) * 100)
                )
            );
        }

        $sheet->setCellValue('A19', 'Generated by Penny - AI Budgeting Assistant');
        $sheet->getStyle('A19')->applyFromArray([
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
