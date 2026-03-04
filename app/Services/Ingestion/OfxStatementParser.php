<?php

namespace App\Services\Ingestion;

use App\Services\Statements\StatementParser;
use Illuminate\Support\Str;

class OfxStatementParser
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function parse(string $content): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $content);
        if ($normalized === '') {
            return [];
        }

        $blocks = $this->extractTransactionBlocks($normalized);
        if ($blocks === []) {
            return [];
        }

        $rows = [];
        foreach ($blocks as $block) {
            $dateRaw = $this->extractTag($block, 'DTPOSTED');
            $amountRaw = $this->extractTag($block, 'TRNAMT');
            $typeRaw = strtoupper((string) $this->extractTag($block, 'TRNTYPE'));
            $name = trim((string) $this->extractTag($block, 'NAME'));
            $memo = trim((string) $this->extractTag($block, 'MEMO'));

            $date = $this->normalizeOfxDate($dateRaw);
            if ($date === null) {
                continue;
            }

            if (! is_numeric($amountRaw)) {
                continue;
            }

            $signedAmount = (float) $amountRaw;
            $direction = $this->resolveDirection($signedAmount, $typeRaw);
            $amount = abs($signedAmount);
            if ($amount <= 0) {
                continue;
            }

            $description = trim(html_entity_decode($name.' '.$memo, ENT_QUOTES | ENT_HTML5));
            $description = StatementParser::sanitizeDescription($description);
            if ($description === '') {
                continue;
            }

            $rows[] = [
                'id' => (string) Str::uuid(),
                'date' => $date,
                'description' => $description,
                'amount' => $amount,
                'type' => $direction,
                'include' => true,
                'duplicate' => false,
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, string>
     */
    private function extractTransactionBlocks(string $content): array
    {
        if (preg_match_all('/<STMTTRN>(.*?)<\/STMTTRN>/is', $content, $matches) && ! empty($matches[1])) {
            return array_values(array_filter(array_map('trim', $matches[1])));
        }

        $parts = preg_split('/<STMTTRN>/i', $content) ?: [];
        if (count($parts) <= 1) {
            return [];
        }

        array_shift($parts);

        return array_values(array_filter(array_map(static fn ($part) => trim((string) $part), $parts)));
    }

    private function extractTag(string $block, string $tag): ?string
    {
        if (preg_match('/<'.preg_quote($tag, '/').'>\s*([^<\n\r]+)/i', $block, $match)) {
            return trim((string) $match[1]);
        }

        return null;
    }

    private function normalizeOfxDate(?string $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        if (preg_match('/^(\d{8})/', $raw, $match)) {
            $token = $match[1];
            $formatted = substr($token, 0, 4).'-'.substr($token, 4, 2).'-'.substr($token, 6, 2);
            return StatementParser::parseDate($formatted);
        }

        return StatementParser::parseDate($raw);
    }

    private function resolveDirection(float $signedAmount, string $typeRaw): string
    {
        if (in_array($typeRaw, ['CREDIT', 'DEP', 'DIRECTDEP', 'INT', 'DIV', 'PAYMENT'], true)) {
            return 'income';
        }

        if (in_array($typeRaw, ['DEBIT', 'WITHDRAWAL', 'CHECK', 'POS', 'ATM', 'FEE'], true)) {
            return 'spending';
        }

        return $signedAmount >= 0 ? 'income' : 'spending';
    }
}
