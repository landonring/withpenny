<?php

namespace App\Http\Controllers;

use App\Models\BankStatementImport;
use App\Models\Transaction;
use App\Services\DemoDataService;
use App\Services\OnboardingService;
use App\Services\PlanUsageService;
use App\Services\Statements\PdfStatementParser;
use App\Services\Statements\StatementParser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class BankStatementController extends Controller
{
    public function __construct(
        private readonly PlanUsageService $planUsage,
        private readonly DemoDataService $demoData,
        private readonly OnboardingService $onboarding,
    )
    {
    }

    public function upload(Request $request)
    {
        if ($request->user()->onboarding_mode) {
            $validated = $request->validate([
                'file' => ['required', 'file', 'mimes:pdf', 'max:25600'],
            ], [
                'file.mimes' => 'Only PDF bank statements are supported.',
                'file.required' => 'Please upload a PDF statement file.',
            ]);

            $import = $this->createDemoImport($request);
            $this->onboarding->rememberImportId($request, $import->id);
            $this->onboarding->setStep($request->user(), 2, $request);

            return response()->json([
                'import' => $this->serializeImport($import),
            ], 201);
        }

        $limit = $this->planUsage->limitState($request->user(), 'statement_uploads');
        if (! $limit['allowed']) {
            return response()->json(
                $this->planUsage->limitResponse($request->user(), 'statement_uploads', 'bank statement uploads'),
                429
            );
        }

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf', 'max:25600'],
        ], [
            'file.mimes' => 'Only PDF bank statements are supported.',
            'file.required' => 'Please upload a PDF statement file.',
        ]);

        [$transactions, $summary, $extractionMeta] = $this->parseSinglePdf($validated['file'], $request);

        if (empty($transactions)) {
            return response()->json([
                'message' => "We couldn't find any transactions in this PDF statement.",
            ], 422);
        }

        [$transactions, $planWindowMeta] = $this->applyStatementDateWindowForPlan($request, $transactions);

        $transactions = $this->flagDuplicates($request->user()->id, $transactions);
        if ($this->planUsage->isStarter($request->user())) {
            $transactions = $this->toBasicStatementTransactions($transactions);
        }

        $meta = $this->mergeImportMeta($summary, $planWindowMeta, $extractionMeta);

        $import = BankStatementImport::create([
            'user_id' => $request->user()->id,
            'transactions' => $transactions,
            'meta' => $meta,
            'masked_account' => null,
            'source' => 'pdf',
            'extraction_confidence' => $extractionMeta['extraction_confidence'] ?? null,
            'balance_mismatch' => (bool) ($extractionMeta['balance_mismatch'] ?? false),
            'extraction_method' => $extractionMeta['extraction_method'] ?? null,
        ]);

        return response()->json([
            'import' => $this->serializeImport($import),
        ], 201);
    }

    public function scanImages(Request $request)
    {
        if ($request->user()->onboarding_mode) {
            $validated = $request->validate([
                'files' => ['nullable', 'array', 'max:6'],
                'files.*' => ['required', 'file', 'mimes:pdf', 'max:25600'],
                'images' => ['nullable', 'array', 'max:6'],
                'images.*' => ['required', 'file', 'mimes:pdf', 'max:25600'],
            ], [
                'files.*.mimes' => 'Only PDF bank statements are supported.',
                'images.*.mimes' => 'Only PDF bank statements are supported.',
            ]);

            $import = $this->createDemoImport($request);
            $this->onboarding->rememberImportId($request, $import->id);
            $this->onboarding->setStep($request->user(), 2, $request);

            return response()->json([
                'import' => $this->serializeImport($import),
            ], 201);
        }

        $limit = $this->planUsage->limitState($request->user(), 'statement_uploads');
        if (! $limit['allowed']) {
            return response()->json(
                $this->planUsage->limitResponse($request->user(), 'statement_uploads', 'bank statement uploads'),
                429
            );
        }

        $validated = $request->validate([
            'files' => ['nullable', 'array', 'min:1', 'max:6'],
            'files.*' => ['required', 'file', 'mimes:pdf', 'max:25600'],
            'images' => ['nullable', 'array', 'min:1', 'max:6'],
            'images.*' => ['required', 'file', 'mimes:pdf', 'max:25600'],
        ], [
            'files.required' => 'Please upload at least one PDF statement file.',
            'files.*.mimes' => 'Only PDF bank statements are supported.',
            'images.*.mimes' => 'Only PDF bank statements are supported.',
        ]);

        $documents = $validated['files'] ?? $validated['images'] ?? [];
        if (empty($documents)) {
            throw ValidationException::withMessages([
                'files' => ['Please upload at least one PDF statement file.'],
            ]);
        }

        $allTransactions = [];
        $summaries = [];
        $extractionMetas = [];

        foreach ($documents as $file) {
            [$transactions, $summary, $meta] = $this->parseSinglePdf($file, $request);
            if (! empty($transactions)) {
                $allTransactions = array_merge($allTransactions, $transactions);
            }
            if (is_array($summary)) {
                $summaries[] = $summary;
            }
            if (is_array($meta)) {
                $extractionMetas[] = $meta;
            }
        }

        if (empty($allTransactions)) {
            return response()->json([
                'message' => "We couldn't find any transactions in those PDF statements.",
            ], 422);
        }

        $allTransactions = $this->dedupeTransactions($allTransactions);

        [$allTransactions, $planWindowMeta] = $this->applyStatementDateWindowForPlan($request, $allTransactions);

        $allTransactions = $this->flagDuplicates($request->user()->id, $allTransactions);
        if ($this->planUsage->isStarter($request->user())) {
            $allTransactions = $this->toBasicStatementTransactions($allTransactions);
        }

        $summary = $this->mergeStatementSummaries($summaries);
        $extractionMeta = $this->mergeExtractionMeta($extractionMetas);
        $meta = $this->mergeImportMeta($summary, $planWindowMeta, $extractionMeta);

        $import = BankStatementImport::create([
            'user_id' => $request->user()->id,
            'transactions' => $allTransactions,
            'meta' => $meta,
            'masked_account' => null,
            'source' => 'pdf',
            'extraction_confidence' => $extractionMeta['extraction_confidence'] ?? null,
            'balance_mismatch' => (bool) ($extractionMeta['balance_mismatch'] ?? false),
            'extraction_method' => $extractionMeta['extraction_method'] ?? null,
        ]);

        return response()->json([
            'import' => $this->serializeImport($import),
        ], 201);
    }

    public function show(Request $request, BankStatementImport $import)
    {
        $this->authorizeImport($request, $import);

        return response()->json([
            'import' => $this->serializeImport($import),
        ]);
    }

    public function confirm(Request $request, BankStatementImport $import)
    {
        $this->authorizeImport($request, $import);

        if ($request->user()->onboarding_mode) {
            $validated = $request->validate([
                'transactions' => ['required', 'array'],
                'transactions.*.date' => ['required', 'date'],
                'transactions.*.description' => ['required', 'string', 'max:255'],
                'transactions.*.amount' => ['required', 'numeric', 'min:0.01'],
                'transactions.*.type' => ['required', 'in:income,spending'],
                'transactions.*.category' => ['nullable', 'string', 'max:50'],
                'transactions.*.include' => ['required', 'boolean'],
            ]);

            $included = array_values(array_filter($validated['transactions'], fn ($item) => ! empty($item['include'])));
            $this->onboarding->storeConfirmedTransactions($request, $included);
            $this->onboarding->forgetImportId($request);
            $import->delete();
            $status = $this->onboarding->setStep($request->user(), 3, $request);

            return response()->json([
                'status' => 'sandbox_confirmed',
                'count' => count($included),
                'onboarding' => $status,
            ]);
        }

        $limit = $this->planUsage->limitState($request->user(), 'statement_uploads');
        if (! $limit['allowed']) {
            return response()->json(
                $this->planUsage->limitResponse($request->user(), 'statement_uploads', 'bank statement uploads'),
                429
            );
        }

        $validated = $request->validate([
            'transactions' => ['required', 'array'],
            'transactions.*.date' => ['required', 'date'],
            'transactions.*.description' => ['required', 'string', 'max:255'],
            'transactions.*.amount' => ['required', 'numeric', 'min:0.01'],
            'transactions.*.type' => ['required', 'in:income,spending'],
            'transactions.*.category' => ['nullable', 'string', 'max:50'],
            'transactions.*.include' => ['required', 'boolean'],
        ]);

        return DB::transaction(function () use ($request, $import, $validated) {
            $lockedImport = BankStatementImport::query()
                ->where('id', $import->id)
                ->where('user_id', $request->user()->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedImport) {
                return response()->json([
                    'status' => 'already_imported',
                ], 409);
            }

            $toCreate = [];
            $dates = array_map(fn ($item) => $item['date'] ?? null, $validated['transactions']);
            $dates = array_values(array_filter($dates));
            $existingCounts = [];
            if (! empty($dates)) {
                $minDate = min($dates);
                $maxDate = max($dates);
                $existing = Transaction::query()
                    ->where('user_id', $request->user()->id)
                    ->where('source', 'statement')
                    ->whereBetween('transaction_date', [$minDate, $maxDate])
                    ->get(['transaction_date', 'amount', 'note', 'type']);

                foreach ($existing as $row) {
                    $amountKey = number_format((float) $row->amount, 2, '.', '');
                    $noteKey = StatementParser::sanitizeDescription($row->note ?? '');
                    $key = strtolower($row->transaction_date.'|'.$amountKey.'|'.$row->type.'|'.$noteKey);
                    $existingCounts[$key] = ($existingCounts[$key] ?? 0) + 1;
                }
            }

            foreach ($validated['transactions'] as $item) {
                if (! $item['include']) {
                    continue;
                }
                $type = $item['type'] === 'income' ? 'income' : 'spending';
                $category = $type === 'income' ? 'Income' : $this->sanitizeStatementCategory($item['category'] ?? null);
                $date = $item['date'];
                $amount = number_format((float) $item['amount'], 2, '.', '');
                $description = StatementParser::sanitizeDescription($item['description']);
                $dedupeKey = strtolower($date.'|'.$amount.'|'.$type.'|'.$description);
                if (($existingCounts[$dedupeKey] ?? 0) > 0) {
                    $existingCounts[$dedupeKey] -= 1;
                    continue;
                }

                $toCreate[] = [
                    'user_id' => $request->user()->id,
                    'amount' => $item['amount'],
                    'category' => $category,
                    'note' => $item['description'],
                    'transaction_date' => $item['date'],
                    'source' => 'statement',
                    'type' => $type,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if (! empty($toCreate)) {
                Transaction::query()->insert($toCreate);
                analytics_track('statement_uploaded', ['mode' => 'confirm']);
            }

            $lockedImport->delete();

            return response()->json([
                'status' => 'imported',
                'count' => count($toCreate),
            ]);
        });
    }

    public function destroy(Request $request, BankStatementImport $import)
    {
        $this->authorizeImport($request, $import);
        $import->delete();
        if ($request->user()->onboarding_mode) {
            $this->onboarding->forgetImportId($request);
            $this->onboarding->setStep($request->user(), 1, $request);
        }

        return response()->json(['status' => 'discarded']);
    }

    private function authorizeImport(Request $request, BankStatementImport $import): void
    {
        if ($import->user_id !== $request->user()->id) {
            abort(403);
        }
    }

    private function sanitizeStatementCategory(?string $category): string
    {
        $allowed = [
            'Groceries',
            'Dining',
            'Transportation',
            'Housing',
            'School',
            'Shopping',
            'Subscriptions',
            'Misc',
        ];

        if ($category && in_array($category, $allowed, true)) {
            return $category;
        }

        return 'Misc';
    }

    /**
     * @return array{0: array<int, array<string,mixed>>, 1: array<string,mixed>, 2: array<string,mixed>}
     */
    private function parseSinglePdf($file, Request $request): array
    {
        $path = $file->store('tmp');
        $absolutePath = Storage::disk('local')->path($path);
        $debug = $request->boolean('debug_statement_parse') || (bool) config('statements.debug', false);

        try {
            $parser = new PdfStatementParser();
            $result = $parser->parseDocument($absolutePath, $debug);

            $transactions = $result['transactions'] ?? [];
            $summary = $result['summary'] ?? [];
            $meta = [
                'extraction_method' => $result['extraction_method'] ?? null,
                'extraction_confidence' => $result['extraction_confidence'] ?? null,
                'balance_mismatch' => (bool) ($result['balance_mismatch'] ?? false),
                'parser_stats' => $result['stats'] ?? [],
            ];

            if ($debug && isset($result['debug'])) {
                $meta['parser_debug'] = $result['debug'];
            }

            return [$transactions, $summary, $meta];
        } catch (\Throwable $error) {
            Log::warning('statement_parse_failed', [
                'error' => $error->getMessage(),
                'file_name' => $file->getClientOriginalName(),
                'mime' => $file->getClientMimeType(),
            ]);

            throw ValidationException::withMessages([
                'file' => ['We could not process that PDF statement. Try a clearer digital export.'],
            ]);
        } finally {
            Storage::disk('local')->delete($path);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $transactions
     * @return array<int, array<string, mixed>>
     */
    private function dedupeTransactions(array $transactions): array
    {
        $seen = [];
        $deduped = [];

        foreach ($transactions as $item) {
            $description = strtolower(trim((string) ($item['description'] ?? '')));
            $description = preg_replace('/\s+/', ' ', $description);
            $key = strtolower((string) ($item['date'] ?? ''))
                .'|'.number_format((float) ($item['amount'] ?? 0), 2, '.', '')
                .'|'.strtolower((string) ($item['type'] ?? 'spending'))
                .'|'.$description;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $deduped[] = $item;
        }

        usort($deduped, fn ($a, $b) => strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? '')));

        return $deduped;
    }

    /**
     * @param array<int, array<string,mixed>> $summaries
     * @return array<string,mixed>|null
     */
    private function mergeStatementSummaries(array $summaries): ?array
    {
        if (empty($summaries)) {
            return null;
        }

        $firstOpening = null;
        $lastClosing = null;

        foreach ($summaries as $summary) {
            if ($firstOpening === null && isset($summary['opening_balance']) && is_numeric($summary['opening_balance'])) {
                $firstOpening = (float) $summary['opening_balance'];
            }
            if (isset($summary['closing_balance']) && is_numeric($summary['closing_balance'])) {
                $lastClosing = (float) $summary['closing_balance'];
            }
        }

        $change = null;
        if ($firstOpening !== null && $lastClosing !== null) {
            $change = $lastClosing - $firstOpening;
        }

        return [
            'opening_balance' => $firstOpening,
            'closing_balance' => $lastClosing,
            'balance_change' => $change,
        ];
    }

    /**
     * @param array<int, array<string,mixed>> $metas
     * @return array<string,mixed>
     */
    private function mergeExtractionMeta(array $metas): array
    {
        if (empty($metas)) {
            return [
                'extraction_method' => null,
                'extraction_confidence' => 'low',
                'balance_mismatch' => false,
            ];
        }

        $methods = array_values(array_filter(array_map(fn ($meta) => $meta['extraction_method'] ?? null, $metas)));
        $confidences = array_values(array_filter(array_map(fn ($meta) => $meta['extraction_confidence'] ?? null, $metas)));

        $hasLow = in_array('low', $confidences, true);
        $hasMedium = in_array('medium', $confidences, true);

        $confidence = $hasLow ? 'low' : ($hasMedium ? 'medium' : 'high');
        $balanceMismatch = collect($metas)->contains(fn ($meta) => (bool) ($meta['balance_mismatch'] ?? false));

        $method = null;
        if (! empty($methods)) {
            $unique = array_values(array_unique($methods));
            $method = count($unique) === 1 ? $unique[0] : 'mixed';
        }

        return [
            'extraction_method' => $method,
            'extraction_confidence' => $confidence,
            'balance_mismatch' => $balanceMismatch,
            'sources' => $methods,
            'parser_stats' => $metas,
        ];
    }

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
            $key = $row->transaction_date.'|'.$row->amount.'|'.($row->type ?? 'spending');
            $existingMap[$key][] = $this->normalizeDescription($row->note ?? '');
        }

        return array_map(function ($item) use ($existingMap) {
            $key = $item['date'].'|'.$item['amount'].'|'.($item['type'] ?? 'spending');
            if (isset($existingMap[$key])) {
                $candidate = $this->normalizeDescription($item['description']);
                foreach ($existingMap[$key] as $existingDescription) {
                    if ($this->isSimilarDescription($candidate, $existingDescription)) {
                        $item['duplicate'] = true;
                        break;
                    }
                }
            }
            return $item;
        }, $transactions);
    }

    private function normalizeDescription(string $value): string
    {
        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $value)));
        return preg_replace('/[^a-z0-9 ]/', '', $normalized);
    }

    private function isSimilarDescription(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }

        if ($a === $b) {
            return true;
        }

        similar_text($a, $b, $percent);
        return $percent >= 85.0;
    }

    private function applyStatementDateWindowForPlan(Request $request, array $transactions): array
    {
        $maxDays = $this->planUsage->statementMaxDaysPerUpload($request->user());
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

    private function mergeImportMeta(?array $summary, ?array $planWindowMeta, ?array $extractionMeta = null): ?array
    {
        if ($summary === null && $planWindowMeta === null && $extractionMeta === null) {
            return null;
        }

        $meta = is_array($summary) ? $summary : [];

        if ($planWindowMeta !== null) {
            $meta['plan_window'] = $planWindowMeta;
        }

        if ($extractionMeta !== null) {
            $meta['extraction'] = $extractionMeta;
        }

        return $meta;
    }

    private function toBasicStatementTransactions(array $transactions): array
    {
        return array_map(function ($item) {
            $type = ($item['type'] ?? 'spending') === 'income' ? 'income' : 'spending';

            return [
                'id' => $item['id'] ?? null,
                'date' => $item['date'] ?? null,
                'description' => $item['description'] ?? '',
                'amount' => $item['amount'] ?? 0,
                'type' => $type,
                'category' => $type === 'income' ? 'Income' : 'Misc',
                'duplicate' => (bool) ($item['duplicate'] ?? false),
                'include' => $item['include'] ?? true,
            ];
        }, $transactions);
    }

    private function serializeImport(BankStatementImport $import): array
    {
        return [
            'id' => $import->id,
            'transactions' => $import->transactions,
            'meta' => $import->meta,
            'extraction_confidence' => $import->extraction_confidence,
            'balance_mismatch' => (bool) $import->balance_mismatch,
            'extraction_method' => $import->extraction_method,
        ];
    }

    private function createDemoImport(Request $request): BankStatementImport
    {
        $payload = $this->demoData->statementImportPayload();

        return BankStatementImport::create([
            'user_id' => $request->user()->id,
            'transactions' => $payload['transactions'],
            'meta' => $payload['meta'],
            'masked_account' => 'Demo account',
            'source' => 'onboarding_demo',
            'extraction_confidence' => $payload['extraction_confidence'] ?? 'high',
            'balance_mismatch' => (bool) ($payload['balance_mismatch'] ?? false),
            'extraction_method' => $payload['extraction_method'] ?? 'pdf_text',
        ]);
    }
}
