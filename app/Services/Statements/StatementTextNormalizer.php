<?php

namespace App\Services\Statements;

use Carbon\Carbon;

class StatementTextNormalizer
{
    /**
     * @return array{lines:array<int,string>,statement_period:array{start:?string,end:?string}}
     */
    public function normalize(string $rawText): array
    {
        $pages = preg_split("/\f+/", $rawText) ?: [$rawText];
        $pageLines = [];

        foreach ($pages as $page) {
            $lines = preg_split('/\r\n|\r|\n/', (string) $page) ?: [];
            $pageLines[] = array_values(array_filter(array_map(
                fn (string $line) => $this->normalizeLine($line),
                $lines
            )));
        }

        $repeating = $this->detectRepeatingLines($pageLines);
        $cleaned = [];

        foreach ($pageLines as $lines) {
            foreach ($lines as $line) {
                $signature = mb_strtolower($line);
                if (isset($repeating[$signature]) && ! $this->looksTransactional($line)) {
                    continue;
                }

                if ($this->isPageNumber($line)) {
                    continue;
                }

                $cleaned[] = $line;
            }
        }

        $merged = $this->mergeBrokenLines($cleaned);

        return [
            'lines' => array_values(array_filter($merged)),
            'statement_period' => $this->detectStatementPeriod($rawText),
        ];
    }

    private function normalizeLine(string $line): string
    {
        $line = str_replace(["\u{00A0}", "\u{2007}", "\u{202F}", "\t"], ' ', $line);
        $line = str_replace(['−', '–', '—'], '-', $line);
        $line = preg_replace('/[[:cntrl:]&&[^\n]]/u', ' ', $line) ?? $line;
        $line = preg_replace('/\s+/', ' ', trim($line)) ?? trim($line);

        return trim($line);
    }

    /**
     * @param array<int, array<int, string>> $pageLines
     * @return array<string, true>
     */
    private function detectRepeatingLines(array $pageLines): array
    {
        $counts = [];

        foreach ($pageLines as $lines) {
            foreach (array_unique($lines) as $line) {
                $signature = mb_strtolower($line);
                $counts[$signature] = ($counts[$signature] ?? 0) + 1;
            }
        }

        $repeating = [];
        foreach ($counts as $signature => $count) {
            if ($count > 1) {
                $repeating[$signature] = true;
            }
        }

        return $repeating;
    }

    private function isPageNumber(string $line): bool
    {
        return (bool) preg_match('/^(?:page\s+)?\d+(?:\s+of\s+\d+)?$/i', trim($line));
    }

    private function looksTransactional(string $line): bool
    {
        return (bool) preg_match('/\b\d{1,2}[\/-]\d{1,2}(?:[\/-]\d{2,4})?\b/', $line)
            && (bool) preg_match('/(?:\(\$?\d[\d,]*\.\d{2}\)|[+\-]?\$?\d[\d,]*\.\d{2}-?(?:\s?(?:CR|DR))?)/i', $line);
    }

    /**
     * @param array<int, string> $lines
     * @return array<int, string>
     */
    private function mergeBrokenLines(array $lines): array
    {
        $merged = [];
        $buffer = '';

        foreach ($lines as $line) {
            if ($buffer === '') {
                $buffer = $line;
                if ($this->looksTransactional($buffer)) {
                    $merged[] = $buffer;
                    $buffer = '';
                }
                continue;
            }

            if ($this->startsNewEntry($line) && $this->looksTransactional($buffer)) {
                $merged[] = $buffer;
                $buffer = $line;

                if ($this->looksTransactional($buffer)) {
                    $merged[] = $buffer;
                    $buffer = '';
                }

                continue;
            }

            $buffer = trim($buffer.' '.$line);
            if ($this->looksTransactional($buffer)) {
                $merged[] = $buffer;
                $buffer = '';
            }
        }

        if ($buffer !== '') {
            $merged[] = $buffer;
        }

        return array_map(
            fn (string $line) => preg_replace('/\s+/', ' ', trim($line)) ?? trim($line),
            $merged
        );
    }

    private function startsNewEntry(string $line): bool
    {
        return (bool) preg_match('/^\d{1,2}[\/-]\d{1,2}(?:[\/-]\d{2,4})?\b/', $line);
    }

    /**
     * @return array{start:?string,end:?string}
     */
    private function detectStatementPeriod(string $rawText): array
    {
        if (preg_match('/(\d{1,2}[\/-]\d{1,2}[\/-]\d{2,4})\s*(?:to|-)\s*(\d{1,2}[\/-]\d{1,2}[\/-]\d{2,4})/i', $rawText, $matches)) {
            return [
                'start' => StatementParser::parseDate($matches[1]),
                'end' => StatementParser::parseDate($matches[2]),
            ];
        }

        preg_match_all('/\b\d{1,2}[\/-]\d{1,2}[\/-]\d{2,4}\b/', $rawText, $matches);
        $dates = collect($matches[0] ?? [])
            ->map(fn (string $value) => StatementParser::parseDate($value))
            ->filter()
            ->map(fn (string $value) => Carbon::parse($value))
            ->sort()
            ->values();

        return [
            'start' => $dates->first()?->toDateString(),
            'end' => $dates->last()?->toDateString(),
        ];
    }
}
