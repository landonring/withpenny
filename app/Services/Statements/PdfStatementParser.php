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
                return '';
            }
        }

        return '';
    }

    public function parseText(string $text): array
    {
        $statementYear = StatementParser::extractStatementYear($text);
        $lines = preg_split('/\r\n|\r|\n/', $text);
        $lines = array_filter($lines ?? [], fn ($line) => trim($line) !== '');

        $rows = [];
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

            if ($inTable && preg_match('/^'.$monthPattern.'\s+\d{1,2}\b/i', $line) !== 1) {
                continue;
            }

            if (! $inTable && preg_match('/^'.$monthPattern.'\s+\d{1,2}\b/i', $line) !== 1) {
                continue;
            }

            if (preg_match('/^'.$monthPattern.'\s+\d{1,2}\s+(.+?)\s{2,}(Debit|Credit)\s+([+-]?\s*\$?\d[\d,]*\.\d{2})/i', $line, $matches)) {
                $dateRaw = trim(preg_replace('/\s{2,}.*/', '', $line));
                $description = trim($matches[1]);
                $amountRaw = $matches[3];

                if ($this->isBalanceLine($description)) {
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

                $rows[] = $this->row($date, $description, $amount, $type);
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

            $rows[] = $this->row($date, $description, $amount, $type);
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

            $rows[] = $this->row($date, $description, $amount, $type);
        }

        return $rows;
    }

    private function row(string $date, string $description, float $amount, string $type): array
    {
        return [
            'id' => (string) Str::uuid(),
            'date' => $date,
            'description' => StatementParser::sanitizeDescription($description),
            'amount' => $amount,
            'type' => $type,
            'include' => true,
            'duplicate' => false,
        ];
    }

    private function isBalanceLine(string $description): bool
    {
        $normalized = strtolower($description);
        return str_contains($normalized, 'opening balance')
            || str_contains($normalized, 'closing balance')
            || str_contains($normalized, 'total ending balance')
            || str_contains($normalized, 'ending balance');
    }
}
