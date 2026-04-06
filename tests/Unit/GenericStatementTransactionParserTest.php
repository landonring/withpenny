<?php

namespace Tests\Unit;

use App\Services\Statements\GenericStatementTransactionParser;
use Tests\TestCase;

class GenericStatementTransactionParserTest extends TestCase
{
    public function test_parser_infers_years_across_statement_boundary_and_ignores_duplicates(): void
    {
        $parser = new GenericStatementTransactionParser();

        $transactions = $parser->parse([
            '12/31 YEAR END BONUS 500.00',
            '12/31 YEAR END BONUS 500.00',
            '01/02 COFFEE SHOP 4.25',
            'Ending balance 1200.00',
        ], [
            'start' => '2025-12-01',
            'end' => '2026-01-31',
        ]);

        $this->assertCount(2, $transactions);
        $this->assertSame('2025-12-31', $transactions[0]['date']);
        $this->assertSame('2026-01-02', $transactions[1]['date']);
    }
}
