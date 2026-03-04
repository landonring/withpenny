<?php

namespace Tests\Unit;

use App\Services\Ingestion\OfxStatementParser;
use App\Services\Statements\CsvStatementParser;
use PHPUnit\Framework\TestCase;

class StructuredStatementParsersTest extends TestCase
{
    public function test_csv_parser_auto_detects_semicolon_delimiter(): void
    {
        $csv = implode("\n", [
            "Date;Description;Amount",
            "2026-02-01;Payroll Deposit;1200,00",
            "2026-02-02;Grocery Store;-48,12",
        ]);

        $path = tempnam(sys_get_temp_dir(), 'stmt-csv-');
        file_put_contents($path, $csv);

        $parser = new CsvStatementParser();
        $rows = $parser->parse($path);

        @unlink($path);

        $this->assertCount(2, $rows);
        $this->assertSame('2026-02-01', $rows[0]['date']);
        $this->assertSame('income', $rows[0]['type']);
        $this->assertSame(1200.0, $rows[0]['amount']);
        $this->assertSame('spending', $rows[1]['type']);
        $this->assertSame(48.12, $rows[1]['amount']);
    }

    public function test_ofx_parser_handles_sgml_style_transaction_blocks_without_closing_stmttrn_tags(): void
    {
        $ofx = <<<OFX
<OFX>
<BANKTRANLIST>
<STMTTRN>
<TRNTYPE>DEBIT
<DTPOSTED>20260201120000[-5:EST]
<TRNAMT>-48.12
<NAME>Grocery Store
<MEMO>Order 123
<STMTTRN>
<TRNTYPE>CREDIT
<DTPOSTED>20260202120000[-5:EST]
<TRNAMT>1200.00
<NAME>Payroll Deposit
<MEMO>Weekly Pay
</BANKTRANLIST>
</OFX>
OFX;

        $parser = new OfxStatementParser();
        $rows = $parser->parse($ofx);

        $this->assertCount(2, $rows);
        $this->assertSame('2026-02-01', $rows[0]['date']);
        $this->assertSame('spending', $rows[0]['type']);
        $this->assertSame(48.12, $rows[0]['amount']);
        $this->assertSame('2026-02-02', $rows[1]['date']);
        $this->assertSame('income', $rows[1]['type']);
        $this->assertSame(1200.0, $rows[1]['amount']);
    }
}
