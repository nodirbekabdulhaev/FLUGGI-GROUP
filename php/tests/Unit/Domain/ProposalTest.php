<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Proposal;

final class ProposalTest extends DomainTestCase
{
    public function test_lines_discount_and_total(): void
    {
        $t = Proposal::totals([
            ['quantity' => 1, 'unitPrice' => '9000000'],
            ['quantity' => 2, 'unitPrice' => '1000000', 'discountPct' => 10],
        ]);
        self::assertDec('11000000', $t['subtotal']);
        self::assertDec('200000', $t['discountAmount']);
        self::assertDec('10800000', $t['total']);
    }

    public function test_rounds_each_line_half_up(): void
    {
        $t = Proposal::totals([
            ['quantity' => 3, 'unitPrice' => '33.335', 'discountPct' => '33.333'],
            ['quantity' => '0.5', 'unitPrice' => '0.01'],
        ]);
        self::assertDec('100.01', $t['lines'][0]['gross']);
        self::assertDec('33.34', $t['lines'][0]['discount']);
        self::assertDec('66.67', $t['lines'][0]['total']);
        self::assertDec('0.01', $t['lines'][1]['gross']);
        self::assertDec('0', $t['lines'][1]['discount']);
        self::assertDec('100.02', $t['subtotal']);
        self::assertDec('33.34', $t['discountAmount']);
        self::assertDec('66.68', $t['total']);

        $t = Proposal::totals([['quantity' => 1, 'unitPrice' => '0.015', 'discountPct' => 50]]);
        self::assertDec('0.02', $t['lines'][0]['gross']);
        self::assertDec('0.01', $t['lines'][0]['discount']);
        self::assertDec('0.01', $t['total']);

        $t = Proposal::totals([['quantity' => 7, 'unitPrice' => 0.1, 'discountPct' => 12.5]]);
        self::assertDec('0.7', $t['subtotal']);
        self::assertDec('0.09', $t['discountAmount']);
        self::assertDec('0.61', $t['total']);
    }

    public function test_discount_is_clamped_to_0_100(): void
    {
        $t = Proposal::totals([
            ['quantity' => 1, 'unitPrice' => '1000', 'discountPct' => 150],
            ['quantity' => 2, 'unitPrice' => '1000', 'discountPct' => -5],
        ]);
        self::assertDec('0', $t['lines'][0]['total']);
        self::assertDec('2000', $t['lines'][1]['total']);
        self::assertDec('3000', $t['subtotal']);
        self::assertDec('1000', $t['discountAmount']);
        self::assertDec('2000', $t['total']);
    }

    public function test_empty_proposal(): void
    {
        $t = Proposal::totals([]);
        self::assertSame([], $t['lines']);
        self::assertDec('0', $t['subtotal']);
        self::assertDec('0', $t['discountAmount']);
        self::assertDec('0', $t['total']);
    }
}
