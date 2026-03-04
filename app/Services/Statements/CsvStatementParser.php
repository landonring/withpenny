<?php

namespace App\Services\Statements;

use Illuminate\Support\Str;

class CsvStatementParser
{
    public function parse(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $delimiter = $this->detectDelimiter($path);
        $handle = fopen($path, 'r');
        if (! $handle) {
            return [];
        }

        $header = $this->readCsvRow($handle, $delimiter);
        if (! $header) {
            fclose($handle);
            return [];
        }

        $map = $this->mapHeaders($header);
        $rows = [];

        if ($this->rowLooksLikeData($header)) {
            $this->appendRow($rows, $header, $map);
        }

        while (($data = $this->readCsvRow($handle, $delimiter)) !== null) {
            $this->appendRow($rows, $data, $map);
        }

        fclose($handle);

        return $rows;
    }

    private function detectDelimiter(string $path): string
    {
        $candidates = [',', ';', "\t", '|'];
        $scores = array_fill_keys($candidates, 0);
        $handle = fopen($path, 'r');
        if (! $handle) {
            return ',';
        }

        $sampled = 0;
        while (! feof($handle) && $sampled < 8) {
            $line = trim((string) fgets($handle));
            if ($line === '') {
                continue;
            }

            foreach ($candidates as $delimiter) {
                $scores[$delimiter] += substr_count($line, $delimiter);
            }

            $sampled += 1;
        }

        fclose($handle);

        $bestDelimiter = ',';
        $bestScore = -1;
        foreach ($scores as $delimiter => $score) {
            if ($score > $bestScore) {
                $bestDelimiter = $delimiter;
                $bestScore = $score;
            }
        }

        return $bestScore > 0 ? $bestDelimiter : ',';
    }

    private function readCsvRow($handle, string $delimiter): ?array
    {
        $row = fgetcsv($handle, 0, $delimiter, '"', '\\');
        if ($row === false) {
            return null;
        }

        $clean = array_map(static function ($value) {
            return is_string($value) ? trim($value) : $value;
        }, $row);

        if (isset($clean[0]) && is_string($clean[0])) {
            $clean[0] = preg_replace('/^\xEF\xBB\xBF/', '', $clean[0]) ?? $clean[0];
        }

        return $clean;
    }

    private function mapHeaders(array $header): array
    {
        $normalized = array_map(function ($value) {
            $value = is_string($value) ? trim($value) : '';
            $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
            return strtolower(preg_replace('/[^a-z0-9]/', '', $value));
        }, $header);
        $map = [
            'date' => null,
            'description' => null,
            'amount' => null,
            'debit' => null,
            'credit' => null,
        ];

        foreach ($normalized as $index => $value) {
            if ($map['date'] === null && in_array($value, ['date', 'transactiondate', 'posteddate', 'postingdate'], true)) {
                $map['date'] = $index;
            }
            if ($map['description'] === null && in_array($value, ['description', 'details', 'memo', 'payee', 'name', 'transaction'], true)) {
                $map['description'] = $index;
            }
            if ($map['amount'] === null && in_array($value, ['amount', 'amt', 'value'], true)) {
                $map['amount'] = $index;
            }
            if ($map['debit'] === null && in_array($value, ['debit', 'withdrawal', 'withdrawals', 'outflow', 'paidout'], true)) {
                $map['debit'] = $index;
            }
            if ($map['credit'] === null && in_array($value, ['credit', 'deposit', 'deposits', 'inflow', 'paidin'], true)) {
                $map['credit'] = $index;
            }
        }

        if ($map['date'] === null) {
            $map['date'] = 0;
        }
        if ($map['description'] === null) {
            $map['description'] = 1;
        }
        if ($map['amount'] === null && $map['debit'] === null && $map['credit'] === null) {
            $map['amount'] = 2;
        }

        return $map;
    }

    private function rowLooksLikeData(array $row): bool
    {
        $dateRaw = $row[0] ?? null;
        if (! $dateRaw) {
            return false;
        }

        if (! StatementParser::parseDate((string) $dateRaw)) {
            return false;
        }

        foreach ($row as $value) {
            if ($value === null) {
                continue;
            }
            if (preg_match('/[+-]?\\s*\\$?\\d[\\d,]*\\.?\\d{0,2}/', (string) $value)) {
                return true;
            }
        }

        return false;
    }

    private function appendRow(array &$rows, array $data, array $map): void
    {
        $dateRaw = $data[$map['date']] ?? null;
        $descRaw = $data[$map['description']] ?? null;

        if (trim((string) $dateRaw) === '' || trim((string) $descRaw) === '') {
            return;
        }

        $date = StatementParser::parseDate((string) $dateRaw);
        if (! $date) {
            return;
        }

        $amountData = $this->parseAmountFromRow($data, $map, (string) $descRaw);
        if (! $amountData) {
            return;
        }

        $amount = $amountData['amount'];
        if ($amount <= 0) {
            return;
        }

        $rows[] = [
            'id' => (string) Str::uuid(),
            'date' => $date,
            'description' => StatementParser::sanitizeDescription((string) $descRaw),
            'amount' => $amount,
            'type' => $amountData['type'],
            'include' => true,
            'duplicate' => false,
        ];
    }

    private function parseAmountFromRow(array $data, array $map, string $description): ?array
    {
        if ($map['debit'] !== null && ! empty($data[$map['debit']])) {
            return [
                'amount' => abs(StatementParser::parseAmount((string) $data[$map['debit']])),
                'type' => 'spending',
            ];
        }

        if ($map['credit'] !== null && ! empty($data[$map['credit']])) {
            return [
                'amount' => abs(StatementParser::parseAmount((string) $data[$map['credit']])),
                'type' => 'income',
            ];
        }

        if ($map['amount'] !== null && ! empty($data[$map['amount']])) {
            $rawString = (string) $data[$map['amount']];
            $rawAmount = StatementParser::parseAmount($rawString);
            $type = StatementParser::determineType($rawAmount, $description, $rawString);
            return [
                'amount' => abs($rawAmount),
                'type' => $type,
            ];
        }

        return null;
    }
}
