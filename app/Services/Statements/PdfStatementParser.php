<?php

namespace App\Services\Statements;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class PdfStatementParser
{
    public function parse(string $path): array
    {
        $text = $this->extractText($path);
        if ($text === '') {
            return [];
        }

        return $this->parseText($text);
    }

    public function extractText(string $path): string
    {
        $pdftotext = trim((string) shell_exec('command -v pdftotext'));
        if ($pdftotext !== '') {
            $outputPath = storage_path('app/tmp/'.Str::uuid().'.txt');
            if (! is_dir(dirname($outputPath))) {
                mkdir(dirname($outputPath), 0775, true);
            }

            $command = escapeshellcmd($pdftotext).' '.escapeshellarg($path).' '.escapeshellarg($outputPath);
            shell_exec($command);

            if (file_exists($outputPath)) {
                $text = (string) file_get_contents($outputPath);
                @unlink($outputPath);
                return $text;
            }
        }

        if (class_exists(\Smalot\PdfParser\Parser::class)) {
            try {
                $parser = new \Smalot\PdfParser\Parser();
                $pdf = $parser->parseFile($path);
                return (string) $pdf->getText();
            } catch (\Throwable $error) {
                Log::warning('pdf_parse_failed', ['error' => $error->getMessage()]);
            }
        }

        $ocrText = $this->ocrPdf($path);
        if ($ocrText !== '') {
            return $ocrText;
        }

        return '';
    }

    public function parseText(string $text): array
    {
        $text = $this->normalizeStatementText($text);
        $statementYear = StatementParser::extractStatementYear($text);
        $rows = $this->parseCapitalOne($text, $statementYear);
        $rowKeys = $this->rowKeys($rows);

        $lines = preg_split('/\r\n|\r|\n/', $text);
        $lines = array_filter($lines ?? [], fn ($line) => trim($line) !== '');

        $monthPattern = StatementParser::monthPattern();
        $inTable = false;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            $normalized = strtolower($line);
            if (str_contains($normalized, 'date') && str_contains($normalized, 'description') && str_contains($normalized, 'amount')) {
                $inTable = true;
                continue;
            }

            if ($inTable && (str_starts_with($normalized, 'page ') || str_contains($normalized, 'statement period') || str_starts_with($normalized, 'note:'))) {
                $inTable = false;
                continue;
            }

            if ($inTable) {
                if ($this->isSummaryLine($normalized)) {
                    continue;
                }

                if (! $this->isDateStart($line, $monthPattern)) {
                    continue;
                }

                if (! preg_match('/\b(debit|credit)\b/i', $line)
                    && ! preg_match('/[+-]?\s*\$?\d[\d,]*\.\d{2}/', $line)) {
                    continue;
                }

                $parts = preg_split('/\s{2,}/', $line);
                if (! $parts || count($parts) < 2) {
                    continue;
                }

                $dateRaw = trim($parts[0]);
                $description = trim($parts[1] ?? '');
                $category = strtolower(trim($parts[2] ?? ''));
                $amountRaw = $parts[3] ?? null;
                $balanceRaw = $parts[4] ?? null;

                if ($description === '' || $this->isBalanceLine($description)) {
                    continue;
                }

                $amounts = StatementParser::extractAmounts($line);
                if (count($amounts) >= 2) {
                    $balanceRaw = $amounts[count($amounts) - 1];
                    $amountRaw = $amounts[count($amounts) - 2];
                } elseif (count($amounts) === 1) {
                    $amountRaw = $amounts[0];
                }

                if (! $amountRaw) {
                    continue;
                }

                $date = StatementParser::parseDate($dateRaw, $statementYear);
                if (! $date) {
                    continue;
                }

                $signedAmount = StatementParser::parseAmount($amountRaw);
                $amount = abs($signedAmount);
                if ($amount <= 0) {
                    continue;
                }

                $type = StatementParser::determineType($signedAmount, $description, $amountRaw, $line);
                if ($category === 'credit') {
                    $type = 'income';
                }
                if ($category === 'debit') {
                    $type = 'spending';
                }

                $balance = null;
                if ($balanceRaw) {
                    $balanceValue = abs(StatementParser::parseAmount($balanceRaw));
                    $balance = $balanceValue > 0 ? $balanceValue : null;
                }

                if ($description === '') {
                    $amounts = StatementParser::extractAmounts($line);
                    $description = StatementParser::extractDescription($line, $dateRaw, $amounts);
                }

                $this->pushRow($rows, $rowKeys, $this->row($date, $description, $amount, $type, $balance));
                continue;
            }

            if (! $inTable && ! $this->isDateStart($line, $monthPattern)) {
                continue;
            }

            if ($this->isSummaryLine(strtolower($line))) {
                continue;
            }

            if (! preg_match('/[+-]?\s*\$?\d[\d,]*\.\d{2}/', $line)) {
                continue;
            }

            $parts = preg_split('/\s{2,}/', $line);
            if (! $parts || count($parts) < 2) {
                continue;
            }

            $dateRaw = trim(array_shift($parts));
            $description = trim(array_shift($parts) ?? '');

            if ($description === '' || $this->isBalanceLine($description)) {
                continue;
            }

            $moneyParts = [];
            foreach ($parts as $part) {
                if (preg_match('/[+-]?\s*\$?\d[\d,]*\.\d{2}/', $part)) {
                    $moneyParts[] = $part;
                }
            }

            $amountRaw = null;
            if (count($moneyParts) >= 2) {
                $amountRaw = $moneyParts[count($moneyParts) - 2];
            } elseif (count($moneyParts) === 1) {
                $amountRaw = $moneyParts[0];
            }

            if (! $amountRaw) {
                continue;
            }

            $date = StatementParser::parseDate($dateRaw, $statementYear);
            if (! $date) {
                continue;
            }

            $signedAmount = StatementParser::parseAmount($amountRaw);
            $amount = abs($signedAmount);
            if ($amount <= 0) continue;
            $type = StatementParser::determineType($signedAmount, $description, $amountRaw, $line);

            $this->pushRow($rows, $rowKeys, $this->row($date, $description, $amount, $type, null));
        }

        if (! empty($rows)) {
            return $rows;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            $dateToken = StatementParser::extractDateToken($line, $monthPattern);
            if (! $dateToken) {
                continue;
            }

            if ($this->isBalanceLine($line)) {
                continue;
            }

            $date = StatementParser::parseDate($dateToken, $statementYear);
            if (! $date) {
                continue;
            }

            $amounts = StatementParser::extractAmounts($line);
            $amountRaw = StatementParser::pickLikelyAmount($amounts);
            if (! $amountRaw) {
                continue;
            }

            $signedAmount = StatementParser::parseAmount($amountRaw);
            $amount = abs($signedAmount);
            if ($amount <= 0) continue;

            $description = StatementParser::extractDescription($line, $dateToken, $amounts);
            if ($description === '') {
                $description = $line;
            }

            $type = StatementParser::determineType($signedAmount, $description, $amountRaw, $line);

            $this->pushRow($rows, $rowKeys, $this->row($date, $description, $amount, $type, null));
        }

        return $rows;
    }

    private function parseCapitalOne(string $text, ?int $statementYear): array
    {
        $normalized = strtolower($text);
        if (! str_contains($normalized, 'capital one') && ! str_contains($normalized, 'capitalone.com')) {
            return [];
        }

        $monthPattern = StatementParser::monthPattern();
        $pattern = '/('.$monthPattern.'\s+\d{1,2})\s+(.+?)\s+(Debit|Credit)\s+([+-]?\s*\$?\d[\d,]*\.\d{2})(?:\s+\$?(\d[\d,]*\.\d{2}))?/i';

        if (! preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $rows = [];
        foreach ($matches as $match) {
            $dateRaw = trim($match[1]);
            $description = trim($match[2] ?? '');
            $category = strtolower(trim($match[3] ?? ''));
            $amountRaw = trim($match[4] ?? '');
            $balanceRaw = trim($match[5] ?? '');

            if ($description === '' || $this->isBalanceLine($description)) {
                continue;
            }

            $date = StatementParser::parseDate($dateRaw, $statementYear);
            if (! $date) {
                continue;
            }

            $signedAmount = StatementParser::parseAmount($amountRaw);
            $amount = abs($signedAmount);
            if ($amount <= 0) {
                continue;
            }

            $type = $category === 'credit' ? 'income' : 'spending';

            $balance = null;
            if ($balanceRaw !== '') {
                $balanceValue = abs(StatementParser::parseAmount($balanceRaw));
                $balance = $balanceValue > 0 ? $balanceValue : null;
            }

            $rows[] = $this->row($date, $description, $amount, $type, $balance);
        }

        return $rows;
    }

    private function rowKeys(array $rows): array
    {
        $keys = [];
        foreach ($rows as $row) {
            $keys[$this->rowKey($row)] = true;
        }
        return $keys;
    }

    private function rowKey(array $row): string
    {
        $desc = strtolower(trim($row['description'] ?? ''));
        $desc = preg_replace('/\s+/', ' ', $desc);
        $desc = mb_substr($desc, 0, 64);
        return ($row['date'] ?? '').'|'.($row['amount'] ?? '').'|'.($row['type'] ?? '').'|'.$desc;
    }

    private function pushRow(array &$rows, array &$rowKeys, array $row): void
    {
        $key = $this->rowKey($row);
        if (isset($rowKeys[$key])) {
            return;
        }
        $rowKeys[$key] = true;
        $rows[] = $row;
    }

    private function normalizeStatementText(string $text): string
    {
        $monthPattern = StatementParser::monthPattern();
        $text = str_replace(['|', '—', '–'], ' ', $text ?? '');
        $text = preg_replace('/[ \t]{2,}/', ' ', $text ?? '');
        $text = preg_replace('/(?<!\n)('.$monthPattern.'\s+\d{1,2}\b)/i', "\n$1", $text ?? '');
        $text = preg_replace('/(?<!\n)(\d{1,2}[\/-]\d{1,2}(?:[\/-]\d{2,4})?\b)/', "\n$1", $text ?? '');
        return $text ?? '';
    }

    private function row(string $date, string $description, float $amount, string $type, ?float $balance): array
    {
        return [
            'id' => (string) Str::uuid(),
            'date' => $date,
            'description' => StatementParser::sanitizeDescription($description),
            'amount' => $amount,
            'type' => $type,
            'balance' => $balance,
            'include' => true,
            'duplicate' => false,
        ];
    }

    private function isBalanceLine(string $description): bool
    {
        $normalized = strtolower($description);
        return str_contains($normalized, 'opening balance')
            || str_contains($normalized, 'beginning balance')
            || str_contains($normalized, 'balance forward')
            || str_contains($normalized, 'previous balance')
            || str_contains($normalized, 'closing balance')
            || str_contains($normalized, 'new balance')
            || str_contains($normalized, 'available balance')
            || str_contains($normalized, 'total balance')
            || str_contains($normalized, 'total ending balance')
            || str_contains($normalized, 'ending balance');
    }

    private function isSummaryLine(string $normalized): bool
    {
        return str_contains($normalized, 'account summary')
            || str_contains($normalized, 'all accounts')
            || str_contains($normalized, 'account name')
            || str_contains($normalized, 'cashflow summary')
            || str_contains($normalized, 'balance summary')
            || str_contains($normalized, 'balance information')
            || str_contains($normalized, 'total fees')
            || str_contains($normalized, 'total deposits')
            || str_contains($normalized, 'total withdrawals')
            || str_contains($normalized, 'transactions in')
            || str_contains($normalized, 'transactions out')
            || str_contains($normalized, 'statement period')
            || str_contains($normalized, 'annual percentage yield')
            || str_contains($normalized, 'apy')
            || str_contains($normalized, 'interest earned')
            || str_contains($normalized, 'ytd interest')
            || str_contains($normalized, 'days in statement')
            || str_contains($normalized, 'date description')
            || str_contains($normalized, 'transaction history')
            || str_contains($normalized, 'account activity')
            || str_contains($normalized, 'deposits and other credits')
            || str_contains($normalized, 'withdrawals and other debits')
            || str_starts_with($normalized, 'page ');
    }

    public function extractSummary(string $text): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text);
        $lines = array_filter($lines ?? [], fn ($line) => trim($line) !== '');

        $opening = null;
        $closing = null;
        $inSummary = false;

        foreach ($lines as $line) {
            $line = trim($line);
            $normalized = strtolower($line);

            if (str_contains($normalized, 'account summary')) {
                $inSummary = true;
                continue;
            }

            if ($inSummary && str_contains($normalized, 'cashflow summary')) {
                $inSummary = false;
                continue;
            }

            if ($inSummary && (str_contains($normalized, 'all accounts') || preg_match('/money\\.{2,}\\d+/i', $line))) {
                $amounts = StatementParser::extractAmounts($line);
                if (count($amounts) >= 2) {
                    $opening = abs(StatementParser::parseAmount($amounts[0]));
                    $closing = abs(StatementParser::parseAmount($amounts[1]));
                    break;
                }
            }
        }

        if ($opening === null || $closing === null) {
            foreach ($lines as $line) {
                $normalized = strtolower($line);
                if ($opening === null && (str_contains($normalized, 'opening balance') || str_contains($normalized, 'beginning balance') || str_contains($normalized, 'balance forward'))) {
                    $amounts = StatementParser::extractAmounts($line);
                    if ($amounts) {
                        $opening = abs(StatementParser::parseAmount(end($amounts)));
                    }
                }
                if ($closing === null && (str_contains($normalized, 'closing balance') || str_contains($normalized, 'ending balance') || str_contains($normalized, 'new balance'))) {
                    $amounts = StatementParser::extractAmounts($line);
                    if ($amounts) {
                        $closing = abs(StatementParser::parseAmount(end($amounts)));
                    }
                }
            }
        }

        $change = null;
        if ($opening !== null && $closing !== null) {
            $change = $closing - $opening;
        }

        return [
            'opening_balance' => $opening,
            'closing_balance' => $closing,
            'balance_change' => $change,
        ];
    }

    private function ocrPdf(string $path): string
    {
        $pdftoppm = trim((string) shell_exec('command -v pdftoppm'));
        $tesseract = trim((string) shell_exec('command -v tesseract'));

        if ($pdftoppm === '' || $tesseract === '') {
            return '';
        }

        $tmpDir = storage_path('app/tmp/'.Str::uuid());
        if (! is_dir($tmpDir) && ! mkdir($tmpDir, 0775, true) && ! is_dir($tmpDir)) {
            return '';
        }

        $prefix = $tmpDir.'/page';
        $command = escapeshellcmd($pdftoppm).' -r 200 -png '.escapeshellarg($path).' '.escapeshellarg($prefix);
        shell_exec($command);

        $images = glob($prefix.'*.png') ?: [];
        if (empty($images)) {
            $this->cleanupTempDir($tmpDir);
            return '';
        }

        $text = '';
        foreach ($images as $image) {
            $ocrCommand = escapeshellcmd($tesseract).' '.escapeshellarg($image).' stdout -l eng --oem 1 --psm 6 -c preserve_interword_spaces=1';
            $text .= "\n".(string) shell_exec($ocrCommand);
        }

        $this->cleanupTempDir($tmpDir);

        return trim($text);
    }

    private function cleanupTempDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $files = glob($dir.'/*') ?: [];
        foreach ($files as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }

    private function isDateStart(string $line, string $monthPattern): bool
    {
        return preg_match('/^'.$monthPattern.'\s+\d{1,2}\b/i', $line) === 1
            || preg_match('/^\d{1,2}[\/-]\d{1,2}(?:[\/-]\d{2,4})?\b/', $line) === 1
            || preg_match('/^\d{1,2}\s+'.$monthPattern.'\b/i', $line) === 1
            || preg_match('/^\d{4}-\d{1,2}-\d{1,2}\b/', $line) === 1;
    }
}
