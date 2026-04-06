<?php

namespace Tests\Unit;

use App\Services\Statements\StatementTextNormalizer;
use Tests\TestCase;

class StatementTextNormalizerTest extends TestCase
{
    public function test_normalizer_removes_repeating_headers_and_merges_broken_rows(): void
    {
        $normalizer = new StatementTextNormalizer();

        $result = $normalizer->normalize(implode("\n", [
            'Statement header',
            '03/12 STARBUCKS',
            '6.45',
            'Page 1 of 2',
            "\f",
            'Statement header',
            '03/13 AMAZON 24.99',
            'Page 2 of 2',
        ]));

        $this->assertSame([
            '03/12 STARBUCKS 6.45',
            '03/13 AMAZON 24.99',
        ], $result['lines']);
    }
}
