<?php

namespace App\Services\Statements;

use Carbon\Carbon;
use Illuminate\Support\Str;

class GenericStatementTransactionParser
{
    private const DATE_PATTERN = '/\b\d{1,2}[\/-]\d{1,2}(?:[\/-]\d{2,4})?\b/';

    private const AMOUNT_PATTERN = '/(?:\(\$?\d[\d,]*\.\d{2}\)|[+\-]?\$?\d[\d,]*\.\d{2}-?(?:\s?(?:CR|DR))?)/i';

    /**
     * @param array<int, string> $lines
     * @param array{start:?string,end:?string}|null $statementPeriod
     * @return array<int, array<string, mixed>>
     */
    public function parse(array $lines, ?array $statementPeriod = null): array
    {
        $transactions = [];
        $seen = [];

        foreach ($lines as $line) {
            $parsed = $this->parseLine($line, $statementPeriod);
            if ($parsed === null) {
                continue;
            }

            $key = strtolower($parsed['date'].'|'.number_format((float) $parsed['amount'], 2, '.', '').'|'.$parsed['type'].'|'.$parsed['description']);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $transactions[] = $parsed;
        }

        return $transactions;
    }

    /**
     * @param array{start:?string,end:?string}|null $statementPeriod
     * @return array<string,mixed>|null
     */
    private function parseLine(string $line, ?array $statementPeriod): ?array
    {
        if ($this->isIgnorableLine($line)) {
            return null;
        }

        if (! preg_match(self::DATE_PATTERN, $line, $dateMatch, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        preg_match_all(self::AMOUNT_PATTERN, $line, $amountMatches, PREG_OFFSET_CAPTURE);
        $matches = $amountMatches[0] ?? [];
        if ($matches === []) {
            return null;
        }

        $amountToken = $this->pickAmountToken($line, $matches);
        if ($amountToken === null) {
            return null;
        }

        $dateToken = (string) $dateMatch[0][0];
        $date = $this->normalizeDate($dateToken, $statementPeriod);
        if ($date === null) {
            return null;
        }

        $dateOffset = (int) $dateMatch[0][1];
        $amountOffset = (int) $amountToken['offset'];
        $descriptionStart = $dateOffset + strlen($dateToken);
        $descriptionLength = max(0, $amountOffset - $descriptionStart);
        $description = trim(substr($line, $descriptionStart, $descriptionLength));
        if ($description === '') {
            $description = trim(preg_replace([self::DATE_PATTERN, self::AMOUNT_PATTERN], ' ', $line, 1) ?? $line);
        }

        $description = preg_replace('/\b(?:debit|credit|dr|cr)\b/i', ' ', $description) ?? $description;
        $description = StatementParser::sanitizeDescription($description);
        if ($description === '' || $this->isIgnorableLine($description)) {
            return null;
        }

        $signedAmount = StatementParser::parseAmount((string) $amountToken['raw']);
        $amount = abs($signedAmount);
        if ($amount <= 0) {
            return null;
        }

        $type = $this->resolveType($line, $description, $signedAmount);

        return [
            'id' => (string) Str::uuid(),
            'date' => $date,
            'description' => $description,
            'amount' => $amount,
            'type' => $type,
            'category' => $type === 'income' ? 'Income' : 'Misc',
            'include' => true,
            'duplicate' => false,
        ];
    }

    private function isIgnorableLine(string $line): bool
    {
        $lower = mb_strtolower(trim($line));
        if ($lower === '') {
            return true;
        }

        return (bool) preg_match('/\b(balance|available|ending|beginning|opening|closing|daily balance|summary|total|interest rate|account number)\b/i', $lower);
    }

    /**
     * @param array<int, array{0:string,1:int}> $matches
     * @return array{raw:string,offset:int}|null
     */
    private function pickAmountToken(string $line, array $matches): ?array
    {
        $candidates = [];
        foreach ($matches as $match) {
            $raw = trim((string) $match[0]);
            $value = abs(StatementParser::parseAmount($raw));
            if ($value <= 0) {
                continue;
            }

            $candidates[] = [
                'raw' => $raw,
                'offset' => (int) $match[1],
                'value' => $value,
            ];
        }

        if ($candidates === []) {
            return null;
        }

        $primary = $candidates;
        if (count($candidates) >= 3) {
            $primary = array_slice($candidates, 0, 2);
        } elseif (count($candidates) === 2 && str_contains(mb_strtolower($line), 'balance')) {
            $primary = [$candidates[0]];
        }

        foreach ($primary as $candidate) {
            if ($candidate['value'] > 0.009) {
                return [
                    'raw' => $candidate['raw'],
                    'offset' => $candidate['offset'],
                ];
            }
        }

        return null;
    }

    /**
     * @param array{start:?string,end:?string}|null $statementPeriod
     */
    private function normalizeDate(string $token, ?array $statementPeriod): ?string
    {
        if (preg_match('/\d{4}/', $token)) {
            return StatementParser::parseDate($token);
        }

        if (! preg_match('/^(?<month>\d{1,2})[\/-](?<day>\d{1,2})$/', $token, $matches)) {
            return StatementParser::parseDate($token);
        }

        $month = (int) $matches['month'];
        $day = (int) $matches['day'];

        if (! empty($statementPeriod['end'])) {
            $end = Carbon::parse((string) $statementPeriod['end']);
            $candidate = Carbon::create($end->year, $month, $day);

            if (! empty($statementPeriod['start'])) {
                $start = Carbon::parse((string) $statementPeriod['start']);

                if ($candidate->gt($end) && $start->year < $end->year) {
                    $candidate->subYear();
                }

                if ($candidate->lt($start) && $start->year === $end->year) {
                    return null;
                }
            }

            return $candidate->toDateString();
        }

        return StatementParser::parseDate($token, now()->year);
    }

    private function resolveType(string $line, string $description, float $signedAmount): string
    {
        $lower = mb_strtolower($line.' '.$description);

        if (preg_match('/\b(credit|deposit|payroll|refund|interest|transfer in|ach credit|direct deposit|income)\b/i', $lower)) {
            return 'income';
        }

        if (preg_match('/\b(debit|purchase|withdrawal|fee|payment|pos|card purchase|transfer out|bill pay)\b/i', $lower)) {
            return 'spending';
        }

        return StatementParser::determineType($signedAmount, $description, null, $line);
    }
}
