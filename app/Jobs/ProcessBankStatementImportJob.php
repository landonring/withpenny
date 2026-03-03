<?php

namespace App\Jobs;

use App\Models\BankStatementImport;
use App\Models\Transaction;
use App\Services\Ingestion\StatementIngestionService;
use App\Services\PlanUsageService;
use App\Services\Statements\StatementParser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class ProcessBankStatementImportJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param array<int, array{name:string, storage_path:string, mime?:string|null}> $files
     */
    public function __construct(
        public readonly int $importId,
        public readonly array $files,
    )
    {
    }

    public function handle(StatementIngestionService $ingestion, PlanUsageService $planUsage): void
    {
        $import = BankStatementImport::query()->with('user')->find($this->importId);
        if (! $import || ! $import->user) {
            $this->cleanupFiles();
            return;
        }

        if (in_array((string) $import->processing_status, ['completed', 'failed'], true)) {
            $this->cleanupFiles();
            return;
        }

        $import->update([
            'processing_status' => 'processing',
            'processing_error' => null,
            'processing_started_at' => now(),
        ]);

        try {
            $descriptors = [];
            foreach ($this->files as $file) {
                $storagePath = (string) ($file['storage_path'] ?? '');
                if ($storagePath === '') {
                    continue;
                }

                $descriptors[] = [
                    'name' => (string) ($file['name'] ?? 'statement.pdf'),
                    'mime' => $file['mime'] ?? null,
                    'path' => Storage::disk('local')->path($storagePath),
                ];
            }

            $result = $ingestion->processFiles($descriptors);
            $transactions = $result['transactions'] ?? [];
            [$transactions, $planWindowMeta] = $this->applyStatementDateWindowForPlan(
                $import->user,
                $transactions,
                $planUsage
            );
            $transactions = $this->flagDuplicates($import->user_id, $transactions);

            if ($planUsage->isStarter($import->user)) {
                $transactions = array_map(function ($item) {
                    $type = ($item['type'] ?? 'spending') === 'income' ? 'income' : 'spending';

                    return [
                        'id' => $item['id'] ?? null,
                        'source' => $item['source'] ?? 'bank_upload',
                        'date' => $item['date'] ?? null,
                        'description' => $item['description'] ?? '',
                        'amount' => $item['amount'] ?? 0,
                        'category' => $type === 'income' ? 'Income' : 'Misc',
                        'confidence_score' => $item['confidence_score'] ?? null,
                        'flagged' => (bool) ($item['flagged'] ?? false),
                        'type' => $type,
                        'include' => true,
                        'duplicate' => (bool) ($item['duplicate'] ?? false),
                    ];
                }, $transactions);
            }

            $meta = $result['summary'] ?? [];
            if ($planWindowMeta !== null) {
                $meta['plan_window'] = $planWindowMeta;
            }

            if (empty($transactions)) {
                $meta['review']['recommended'] = true;
                $meta['review']['message'] = 'No transactions were extracted automatically. Manual review is required.';
            }

            $import->update([
                'transactions' => $transactions,
                'meta' => $meta,
                'source' => (string) ($result['source'] ?? 'mixed'),
                'file_name' => (string) ($this->files[0]['name'] ?? null),
                'file_format' => (string) ($result['source'] ?? 'mixed'),
                'extraction_method' => (string) ($result['extraction_method'] ?? 'mixed'),
                'extraction_confidence' => (string) ($result['extraction_confidence'] ?? 'low'),
                'balance_mismatch' => false,
                'confidence_score' => $result['confidence_score'] ?? 0,
                'flagged_rows' => $result['flagged_rows'] ?? 0,
                'total_rows' => $result['total_rows'] ?? 0,
                'raw_extraction_cache' => $result['raw_extraction_cache'] ?? null,
                'processing_status' => 'completed',
                'processing_error' => null,
                'processing_completed_at' => now(),
            ]);
        } catch (\Throwable $error) {
            $import->update([
                'processing_status' => 'failed',
                'processing_error' => $error->getMessage(),
                'processing_completed_at' => now(),
                'meta' => array_merge($import->meta ?? [], [
                    'review' => [
                        'recommended' => true,
                        'message' => 'Parsing could not be automated. Manual review is required.',
                    ],
                ]),
            ]);
        } finally {
            $this->cleanupFiles();
        }
    }

    private function cleanupFiles(): void
    {
        foreach ($this->files as $file) {
            $storagePath = (string) ($file['storage_path'] ?? '');
            if ($storagePath !== '') {
                Storage::disk('local')->delete($storagePath);
            }
        }
    }

    /**
     * @param array<int, array<string,mixed>> $transactions
     * @return array{0: array<int, array<string,mixed>>, 1: ?array<string,mixed>}
     */
    private function applyStatementDateWindowForPlan($user, array $transactions, PlanUsageService $planUsage): array
    {
        $maxDays = $planUsage->statementMaxDaysPerUpload($user);
        if ($maxDays === null) {
            return [$transactions, null];
        }

        $dates = collect($transactions)
            ->pluck('date')
            ->filter()
            ->map(fn ($date) => strtotime((string) $date))
            ->filter(fn ($timestamp) => $timestamp !== false)
            ->values();

        if ($dates->count() < 2) {
            return [$transactions, null];
        }

        $minTimestamp = (int) $dates->min();
        $maxTimestamp = (int) $dates->max();
        $spanDays = (int) floor(($maxTimestamp - $minTimestamp) / 86400) + 1;

        if ($spanDays <= $maxDays) {
            return [$transactions, null];
        }

        $minAllowedTimestamp = $maxTimestamp - (($maxDays - 1) * 86400);
        $filteredTransactions = array_values(array_filter($transactions, function ($item) use ($minAllowedTimestamp, $maxTimestamp) {
            $timestamp = strtotime((string) ($item['date'] ?? ''));
            if ($timestamp === false) {
                return true;
            }

            return $timestamp >= $minAllowedTimestamp && $timestamp <= $maxTimestamp;
        }));

        if (empty($filteredTransactions)) {
            return [$transactions, null];
        }

        return [
            $filteredTransactions,
            [
                'applied' => true,
                'limit_days' => $maxDays,
                'original_span_days' => $spanDays,
                'message' => "This statement spans {$spanDays} days. Penny imported the most recent {$maxDays} days for your plan.",
            ],
        ];
    }

    /**
     * @param array<int, array<string,mixed>> $transactions
     * @return array<int, array<string,mixed>>
     */
    private function flagDuplicates(int $userId, array $transactions): array
    {
        $dates = array_column($transactions, 'date');
        if (empty($dates)) {
            return $transactions;
        }

        $min = min($dates);
        $max = max($dates);

        $existing = Transaction::query()
            ->where('user_id', $userId)
            ->whereBetween('transaction_date', [$min, $max])
            ->get(['transaction_date', 'amount', 'note', 'type']);

        $existingMap = [];
        foreach ($existing as $row) {
            $amountKey = number_format((float) $row->amount, 2, '.', '');
            $noteKey = StatementParser::sanitizeDescription($row->note ?? '');
            $key = strtolower($row->transaction_date.'|'.$amountKey.'|'.($row->type ?? 'spending').'|'.$noteKey);
            $existingMap[$key] = true;
        }

        return array_map(function ($item) use ($existingMap) {
            $amountKey = number_format((float) ($item['amount'] ?? 0), 2, '.', '');
            $noteKey = StatementParser::sanitizeDescription((string) ($item['description'] ?? ''));
            $key = strtolower((string) ($item['date'] ?? '').'|'.$amountKey.'|'.($item['type'] ?? 'spending').'|'.$noteKey);
            $item['duplicate'] = isset($existingMap[$key]);
            return $item;
        }, $transactions);
    }
}
