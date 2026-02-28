<?php

namespace Tests\Unit;

use App\Services\Statements\PdfStatementParser;
use PHPUnit\Framework\TestCase;

class PdfStatementParserTest extends TestCase
{
    public function test_pipeline_recovers_rows_from_fragmented_table_lines(): void
    {
        $parser = new PdfStatementParser();

        $lines = [
            $this->line('h1', 1, 'STATEMENT PERIOD Jan 1 - Jan 31, 2026', 0.10, 0.92, 0.08),
            $this->line('h2', 1, 'DATE DESCRIPTION CATEGORY AMOUNT BALANCE', 0.10, 0.92, 0.12),
            $this->line('r1d', 1, 'Jan 13', 0.12, 0.18, 0.22),
            $this->line('r1x', 1, 'Deposit from Goldman Sachs TRANSFER', 0.22, 0.60, 0.2208),
            $this->line('r1c', 1, 'Credit', 0.62, 0.70, 0.2212),
            $this->line('r1a', 1, '+$60.00', 0.74, 0.80, 0.2206),
            $this->line('r1b', 1, '$2,450.28', 0.84, 0.92, 0.2209),
            $this->line('r2d', 1, 'Jan 17', 0.12, 0.18, 0.25),
            $this->line('r2x', 1, 'Debit Card Purchase DUNKIN', 0.22, 0.60, 0.2506),
            $this->line('r2c', 1, 'Debit', 0.62, 0.70, 0.2508),
            $this->line('r2a', 1, '-$18.61', 0.74, 0.80, 0.2504),
            $this->line('r2b', 1, '$2,431.67', 0.84, 0.92, 0.2507),
            $this->line('t1', 1, 'Opening Balance $2,390.28', 0.12, 0.44, 0.19),
            $this->line('t2', 1, 'Closing Balance $2,431.67', 0.12, 0.44, 0.30),
        ];

        /** @var array<string, mixed> $result */
        $result = (function (array $seedLines): array {
            return $this->runPipeline($seedLines, 'pdf_text', false);
        })->call($parser, $lines);

        $this->assertIsArray($result);
        $this->assertCount(2, $result['transactions']);
        $this->assertSame(['income', 'spending'], array_column($result['transactions'], 'type'));
        $this->assertSame([60.0, 18.61], array_column($result['transactions'], 'amount'));
    }

    public function test_pipeline_uses_text_fallback_when_structured_candidates_fail(): void
    {
        $parser = new PdfStatementParser();

        $lines = [
            $this->line('h1', 1, 'STATEMENT PERIOD Jan 1 - Jan 31, 2026', 0.10, 0.92, 0.08),
            $this->line('h2', 1, 'DATE DESCRIPTION CATEGORY AMOUNT BALANCE', 0.10, 0.92, 0.12),
            $this->line('r1d', 1, 'Jan 13', 0.12, 0.18, 0.22),
            $this->line('r1x', 1, 'Deposit from Goldman Sachs TRANSFER', 0.22, 0.60, 0.238),
            $this->line('r1c', 1, 'Credit', 0.62, 0.70, 0.252),
            $this->line('r1a', 1, '+$60.00', 0.74, 0.80, 0.266),
            $this->line('r1b', 1, '$2,450.28', 0.84, 0.92, 0.279),
            $this->line('r2d', 1, 'Jan 17', 0.12, 0.18, 0.31),
            $this->line('r2x', 1, 'Debit Card Purchase DUNKIN', 0.22, 0.60, 0.327),
            $this->line('r2c', 1, 'Debit', 0.62, 0.70, 0.341),
            $this->line('r2a', 1, '-$18.61', 0.74, 0.80, 0.354),
            $this->line('r2b', 1, '$2,431.67', 0.84, 0.92, 0.366),
            $this->line('t1', 1, 'Opening Balance $2,390.28', 0.12, 0.44, 0.19),
            $this->line('t2', 1, 'Closing Balance $2,431.67', 0.12, 0.44, 0.40),
        ];

        /** @var array<string, mixed> $result */
        $result = (function (array $seedLines): array {
            return $this->runPipeline($seedLines, 'pdf_text', true);
        })->call($parser, $lines);

        $this->assertNotEmpty($result['transactions']);
        $this->assertTrue((bool) ($result['stats']['text_fallback_used'] ?? false));
    }

    public function test_parse_text_handles_blank_lines_without_undefined_offset_errors(): void
    {
        $parser = new PdfStatementParser();

        $text = "\nJan 1 Coffee Shop -$4.50\n\nJan 2 Payroll $1200.00\n";

        $rows = $parser->parseText($text);

        $this->assertIsArray($rows);
    }

    public function test_parse_text_handles_debit_credit_statement_rows_without_importing_balance_rows(): void
    {
        $parser = new PdfStatementParser();

        $text = <<<TXT
STATEMENT PERIOD
Jan 1 - Jan 31, 2026
DATE  DESCRIPTION  CATEGORY  AMOUNT  BALANCE
Jan 1  Opening Balance  \$2,390.28
Jan 13  Deposit from Goldman Sachs Ba TRANSFER Ringeisen,Jonat  Credit  +\$60.00  \$2,450.28
Jan 17  Debit Card Purchase - DUNKIN 342680 Q35 CANTERBURY CT  Debit  -\$18.61  \$2,431.67
Jan 28  Debit Card Purchase - VISIBLE 866 331 3527 CO  Debit  -\$20.00  \$2,411.67
Jan 31  Monthly Interest Paid  Credit  +\$0.21  \$2,411.88
Jan 31  Closing Balance  \$2,411.88
TXT;

        $rows = $parser->parseText($text);

        $this->assertCount(4, $rows);
        $this->assertSame([60.0, 18.61, 20.0, 0.21], array_column($rows, 'amount'));
        $this->assertSame(['income', 'spending', 'spending', 'income'], array_column($rows, 'type'));
    }

    public function test_extract_amounts_accepts_decimal_comma_ocr_pattern(): void
    {
        $amounts = \App\Services\Statements\StatementParser::extractAmounts('Debit -$18,61 Balance $2,431,67');

        $this->assertCount(2, $amounts);
        $this->assertSame(-18.61, \App\Services\Statements\StatementParser::parseAmount($amounts[0]));
    }

    public function test_parse_text_prefers_debit_credit_rows_and_ignores_summary_like_opening_row(): void
    {
        $parser = new PdfStatementParser();

        $text = <<<TXT
STATEMENT PERIOD
Jan 1 - Jan 31, 2026
ACCOUNT SUMMARY
MONEY..0148 2,390.28 2,411.88
01/13/2026 Deposit from Goldman Sachs Ba TRANSFER Ringeisen,Jonat Credit +60.00 2,450.28
01/17/2026 Debit Card Purchase - DUNKIN 342680 Q35 CANTERBURY CT Debit -18.61 2,431.67
01/28/2026 Debit Card Purchase - VISIBLE 866 331 3527 CO Debit -20.00 2,411.67
01/31/2026 Monthly Interest Paid Credit +0.21 2,411.88
TXT;

        $rows = $parser->parseText($text);

        $this->assertCount(4, $rows);
        $this->assertSame([60.0, 18.61, 20.0, 0.21], array_column($rows, 'amount'));
        $this->assertSame(['income', 'spending', 'spending', 'income'], array_column($rows, 'type'));
    }

    public function test_parse_text_keeps_fallback_rows_when_specialized_match_is_partial(): void
    {
        $parser = new PdfStatementParser();

        $text = <<<TXT
STATEMENT PERIOD Jan 1 - Jan 31, 2026
DATE DESCRIPTION CATEGORY AMOUNT BALANCE
Jan 13 Deposit from Goldman Sachs Ba TRANSFER Ringeisen,Jonat Cr +\$60.00 \$2,450.28
Jan 17 Debit Card Purchase - DUNKIN 342680 Q35 CANTERBURY CT Debit -\$18.61 \$2,431.67
Jan 28 Debit Card Purchase - VISIBLE 866 331 3527 CO Debit -\$20.00 \$2,411.67
Jan 31 Monthly Interest Paid Cr +\$0.21 \$2,411.88
TXT;

        $rows = $parser->parseText($text);

        $this->assertCount(4, $rows);
        $this->assertSame([60.0, 18.61, 20.0, 0.21], array_column($rows, 'amount'));
        $this->assertSame(['income', 'spending', 'spending', 'income'], array_column($rows, 'type'));
    }

    public function test_parse_text_stitches_deeply_wrapped_ocr_rows(): void
    {
        $parser = new PdfStatementParser();

        $text = <<<TXT
STATEMENT PERIOD Jan 1 - Jan 31, 2026
DATE DESCRIPTION CATEGORY AMOUNT BALANCE
Jan 13
Deposit from Goldman Sachs Ba TRANSFER
Ringeisen,Jonat
Credit
+\$60.00
\$2,450.28
Jan 17
Debit Card Purchase - DUNKIN 342680
Q35 CANTERBURY CT
Debit
-\$18.61
\$2,431.67
Jan 28 Debit Card Purchase - VISIBLE 866 331 3527 CO Debit -\$20.00 \$2,411.67
Jan 31 Monthly Interest Paid Credit +\$0.21 \$2,411.88
TXT;

        $rows = $parser->parseText($text);

        $this->assertCount(4, $rows);
        $this->assertSame([60.0, 18.61, 20.0, 0.21], array_column($rows, 'amount'));
        $this->assertSame(['income', 'spending', 'spending', 'income'], array_column($rows, 'type'));
    }

    /**
     * @return array<string, mixed>
     */
    private function line(string $id, int $page, string $text, float $xMin, float $xMax, float $yMin): array
    {
        $tokens = preg_split('/\s+/', trim($text)) ?: [];
        $tokenCount = max(count($tokens), 1);
        $width = max($xMax - $xMin, 0.01);
        $step = $width / $tokenCount;
        $words = [];

        foreach ($tokens as $index => $token) {
            $start = $xMin + ($index * $step);
            $end = min($xMax, $start + ($step * 0.9));
            $words[] = [
                'text' => $token,
                'x_min' => $start,
                'x_max' => $end,
                'y_min' => $yMin,
                'y_max' => $yMin + 0.008,
            ];
        }

        return [
            'id' => $id,
            'page' => $page,
            'text' => $text,
            'x_min' => $xMin,
            'x_max' => $xMax,
            'y_min' => $yMin,
            'y_max' => $yMin + 0.008,
            'words' => $words,
        ];
    }
}
