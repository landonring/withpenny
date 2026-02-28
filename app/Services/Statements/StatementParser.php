<?php

namespace App\Services\Statements;

use Carbon\Carbon;

class StatementParser
{
    public static function amountPattern(): string
    {
        return '[+-]?\s*\$?\d[\d,]*(?:[.,]\d{2})';
    }

    public static function monthPattern(): string
    {
        return '(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec|January|February|March|April|May|June|July|August|September|October|November|December)';
    }

    public static function parseDate(string $value, ?int $year = null): ?string
    {
        $value = trim($value);
        $formats = [
            'm/d/Y', 'm/d/y', 'm-d-Y', 'm-d-y',
            'Y-m-d', 'Y/m/d',
            'd/m/Y', 'd/m/y',
            'd-m-Y', 'd-m-y',
            'j M Y', 'j F Y',
        ];

        foreach ($formats as $format) {
            try {
                return Carbon::createFromFormat($format, $value)->toDateString();
            } catch (\Throwable $error) {
                continue;
            }
        }

        if ($year) {
            if (preg_match('/^\d{1,2}[\/-]\d{1,2}$/', $value)) {
                $withYear = $value.'/'.$year;
                try {
                    return Carbon::createFromFormat('m/d/Y', $withYear)->toDateString();
                } catch (\Throwable $error) {
                    // continue
                }
                try {
                    return Carbon::createFromFormat('m-d-Y', str_replace('/', '-', $withYear))->toDateString();
                } catch (\Throwable $error) {
                    // continue
                }
            }

            try {
                return Carbon::createFromFormat('M j Y', "$value $year")->toDateString();
            } catch (\Throwable $error) {
                // continue
            }

            try {
                return Carbon::createFromFormat('F j Y', "$value $year")->toDateString();
            } catch (\Throwable $error) {
                // continue
            }
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable $error) {
            return null;
        }
    }

    public static function parseAmount(string $value): float
    {
        $clean = trim($value);
        $negative = false;
        $positive = false;

        if (preg_match('/^\s*-\s*/', $clean)) {
            $negative = true;
        }

        if (preg_match('/^\s*\+\s*/', $clean)) {
            $positive = true;
        }

        if (preg_match('/\bCR\b/i', $clean)) {
            $positive = true;
        }

        if (preg_match('/\bDR\b/i', $clean)) {
            $negative = true;
        }

        if (preg_match('/-\s*$/', $clean)) {
            $negative = true;
        }

        $clean = preg_replace('/\b(CR|DR)\b/i', '', $clean);
        $clean = preg_replace('/^[+-]\s*/', '', $clean);
        $clean = preg_replace('/-\s*$/', '', $clean);

        if (str_starts_with($clean, '(') && str_ends_with($clean, ')')) {
            $negative = true;
            $clean = trim($clean, '()');
        }

        $clean = str_replace(['$', ' ', 'USD'], '', $clean);
        if (str_contains($clean, ',') && ! str_contains($clean, '.') && preg_match('/,\d{2}$/', $clean)) {
            $clean = str_replace(',', '.', $clean);
        } else {
            $clean = str_replace(',', '', $clean);
        }
        $amount = (float) $clean;

        if ($negative && ! $positive) {
            return -$amount;
        }

        return $amount;
    }

    public static function sanitizeDescription(string $value): string
    {
        $value = preg_replace('/\s+/', ' ', trim($value));
        $value = preg_replace('/\d{8,}/', '••••', $value);
        return mb_substr($value, 0, 255);
    }

    public static function determineType(float $signedAmount, string $description, ?string $rawAmount = null, ?string $line = null): string
    {
        if ($line) {
            if (preg_match('/\bdebit\b/i', $line)) {
                return 'spending';
            }
            if (preg_match('/\bcredit\b/i', $line)) {
                return 'income';
            }
        }

        if ($signedAmount < 0) {
            return 'spending';
        }

        if ($signedAmount > 0) {
            if (($rawAmount && str_contains($rawAmount, '+')) || self::hasIncomeKeyword($description)) {
                return 'income';
            }
            return 'spending';
        }

        return self::hasIncomeKeyword($description) ? 'income' : 'spending';
    }

    public static function hasIncomeKeyword(string $description): bool
    {
        $normalized = strtoupper($description);
        $keywords = [
            'PAYROLL',
            'DIRECT DEP',
            'DEPOSIT',
            'ACH CREDIT',
            'TRANSFER IN',
        ];

        foreach ($keywords as $keyword) {
            if (str_contains($normalized, $keyword)) {
                return true;
            }
        }

        return false;
    }

    public static function extractStatementYear(string $text): ?int
    {
        if (preg_match('/STATEMENT PERIOD.*?([A-Za-z]{3,9})\s+\d{1,2}\s*-\s*[A-Za-z]{3,9}\s+\d{1,2},\s*(\d{4})/is', $text, $matches)) {
            return (int) $matches[2];
        }

        if (preg_match('/([A-Za-z]{3,9})\s+\d{1,2}\s*-\s*[A-Za-z]{3,9}\s+\d{1,2},\s*(\d{4})/i', $text, $matches)) {
            return (int) $matches[2];
        }

        if (preg_match('/\b(\d{1,2})[\/-](\d{1,2})[\/-](\d{2,4})\s*-\s*(\d{1,2})[\/-](\d{1,2})[\/-](\d{2,4})\b/', $text, $matches)) {
            $year = (int) $matches[3];
            if ($year < 100) {
                $year += 2000;
            }
            return $year;
        }

        if (preg_match('/\bStatement Date:\s*(\d{1,2})[\/-](\d{1,2})[\/-](\d{2,4})\b/i', $text, $matches)) {
            $year = (int) $matches[3];
            if ($year < 100) {
                $year += 2000;
            }
            return $year;
        }

        return null;
    }

    public static function extractDateToken(string $line, string $monthPattern): ?string
    {
        if (preg_match('/\b(\d{1,2}\/\d{1,2}\/\d{2,4})\b/', $line, $matches)) {
            return $matches[1];
        }

        if (preg_match('/\b(\d{1,2}[\/-]\d{1,2})\b/', $line, $matches)) {
            return $matches[1];
        }

        if (preg_match('/\b(\d{4}-\d{1,2}-\d{1,2})\b/', $line, $matches)) {
            return $matches[1];
        }

        if (preg_match('/\b'.$monthPattern.'\s*\d{1,2}\b/i', $line, $matches)) {
            return $matches[0];
        }

        if (preg_match('/\b\d{1,2}\s+'.$monthPattern.'\b/i', $line, $matches)) {
            return $matches[0];
        }

        return null;
    }

    public static function extractAmounts(string $line): array
    {
        preg_match_all('/'.self::amountPattern().'/', $line, $matches);
        $candidates = [];
        foreach ($matches[0] ?? [] as $candidate) {
            $trim = trim($candidate);
            if ($trim === '') {
                continue;
            }
            $candidates[] = $trim;
        }

        return $candidates;
    }

    public static function pickLikelyAmount(array $amounts): ?string
    {
        $best = null;
        $bestValue = null;
        foreach ($amounts as $amount) {
            $value = abs(self::parseAmount($amount));
            if ($value <= 0) {
                continue;
            }
            if ($bestValue === null || $value < $bestValue) {
                $bestValue = $value;
                $best = $amount;
            }
        }

        return $best;
    }

    public static function pickLikelyAmountFromLine(string $line, array $amounts): ?string
    {
        if (empty($amounts)) {
            return null;
        }

        if (preg_match_all('/[+-]\s*\$?\d[\d,]*\.\d{2}/', $line, $matches) && !empty($matches[0])) {
            return trim($matches[0][0]);
        }

        if (preg_match('/(\$?\d[\d,]*\.\d{2})\s*(CR|DR)\b/i', $line, $matches)) {
            return trim($matches[1]);
        }

        if (count($amounts) >= 2) {
            $best = null;
            $bestValue = null;
            foreach ($amounts as $amount) {
                $value = abs(self::parseAmount($amount));
                if ($value <= 0) {
                    continue;
                }
                if ($bestValue === null || $value < $bestValue) {
                    $bestValue = $value;
                    $best = $amount;
                }
            }
            return $best;
        }

        return $amounts[0];
    }

    public static function pickLikelyBalance(array $amounts, ?string $amountRaw): ?string
    {
        if (count($amounts) < 2) {
            return null;
        }

        $amountValue = $amountRaw ? abs(self::parseAmount($amountRaw)) : null;
        $best = null;
        $bestValue = null;

        foreach ($amounts as $amount) {
            $value = abs(self::parseAmount($amount));
            if ($value <= 0) {
                continue;
            }
            if ($amountValue !== null && abs($value - $amountValue) < 0.01) {
                continue;
            }
            if ($bestValue === null || $value > $bestValue) {
                $bestValue = $value;
                $best = $amount;
            }
        }

        return $best;
    }

    public static function extractDescription(string $line, string $dateToken, array $amounts): string
    {
        $description = str_replace($dateToken, '', $line);
        foreach ($amounts as $amount) {
            $description = str_replace($amount, '', $description);
        }
        $description = preg_replace('/\b(debit|credit)\b/i', '', $description);
        $description = preg_replace('/\s+/', ' ', trim($description));

        return $description;
    }
}
