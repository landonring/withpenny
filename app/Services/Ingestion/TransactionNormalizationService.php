<?php

namespace App\Services\Ingestion;

use App\Services\Statements\StatementParser;
use Illuminate\Support\Str;

class TransactionNormalizationService
{
    public function __construct(private readonly CategorySuggestionService $categorySuggestion)
    {
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public function normalizeBankRows(array $rows, float $uploadConfidence = 0.0): array
    {
        $normalized = [];

        foreach ($rows as $row) {
            $date = StatementParser::parseDate((string) ($row['date'] ?? ''));
            $description = StatementParser::sanitizeDescription((string) ($row['description'] ?? ''));
            $type = $this->normalizeType((string) ($row['type'] ?? 'spending'));
            $amount = abs((float) ($row['amount'] ?? 0));

            if ($date === null || $description === '' || $amount <= 0) {
                continue;
            }

            $suggestion = $this->categorySuggestion->suggest($description);
            $category = $type === 'income' ? 'Income' : ($suggestion['category'] ?? null);

            $normalized[] = [
                'id' => (string) Str::uuid(),
                'source' => 'bank_upload',
                'date' => $date,
                'description' => $description,
                'amount' => $amount,
                'category' => $category,
                'confidence_score' => $this->resolveConfidence($row, $uploadConfidence),
                'flagged' => (bool) ($row['flagged'] ?? false),
                'type' => $type,
                'include' => true,
                'duplicate' => false,
                'category_confidence' => (float) ($suggestion['confidence'] ?? 0),
                'suggested_framework_category' => $suggestion['framework'] ?? null,
            ];
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function normalizeReceiptPayload(array $payload, float $confidence, bool $flagged): array
    {
        $merchant = StatementParser::sanitizeDescription((string) ($payload['merchant'] ?? ''));
        $date = StatementParser::parseDate((string) ($payload['date'] ?? ''));

        $total = $payload['total'];
        $total = is_numeric($total) ? abs((float) $total) : null;

        $lineItems = [];
        foreach (($payload['line_items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $name = StatementParser::sanitizeDescription((string) ($item['name'] ?? ''));
            $amount = is_numeric($item['amount'] ?? null) ? abs((float) $item['amount']) : null;

            if ($name === '' || $amount === null || $amount <= 0) {
                continue;
            }

            $lineItems[] = [
                'name' => $name,
                'amount' => $amount,
            ];
        }

        return [
            'merchant' => $merchant !== '' ? $merchant : null,
            'date' => $date,
            'total' => $total,
            'tax' => is_numeric($payload['tax'] ?? null) ? abs((float) $payload['tax']) : null,
            'line_items' => $lineItems,
            'confidence_score' => round(max(0, min(100, $confidence)), 2),
            'flagged' => $flagged,
        ];
    }

    /**
     * @param array<string, mixed> $normalized
     * @return array<string, mixed>
     */
    public function toTransactionInsert(array $normalized, int $userId, ?int $receiptId = null): array
    {
        return [
            'user_id' => $userId,
            'receipt_id' => $receiptId,
            'amount' => (float) ($normalized['amount'] ?? 0),
            'category' => (string) ($normalized['category'] ?? 'Misc'),
            'note' => (string) ($normalized['description'] ?? ''),
            'transaction_date' => (string) ($normalized['date'] ?? now()->toDateString()),
            'source' => (string) ($normalized['source'] ?? 'manual'),
            'type' => $this->normalizeType((string) ($normalized['type'] ?? 'spending')),
            'confidence_score' => $normalized['confidence_score'] ?? null,
            'flagged' => (bool) ($normalized['flagged'] ?? false),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function normalizeType(string $type): string
    {
        $lower = strtolower($type);

        if (in_array($lower, ['credit', 'income'], true)) {
            return 'income';
        }

        return 'spending';
    }

    /**
     * @param array<string,mixed> $row
     */
    private function resolveConfidence(array $row, float $uploadConfidence): float
    {
        $rowConfidence = (float) ($row['confidence_score'] ?? 0);
        if ($rowConfidence > 0) {
            return round(max(0, min(100, $rowConfidence)), 2);
        }

        return round(max(0, min(100, $uploadConfidence)), 2);
    }
}
