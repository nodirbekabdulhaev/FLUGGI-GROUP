<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Finance;

final class FinanceTest extends DomainTestCase
{
    public function test_spec_example(): void
    {
        self::assertSame(['grossProfit' => '4300000.00', 'marginPct' => '47.78'], Finance::projectFinance('9000000', '4700000'));
    }

    public function test_no_revenue_and_loss(): void
    {
        self::assertNull(Finance::marginPct(100, 0));
        self::assertSame(['grossProfit' => '-500000.00', 'marginPct' => '-50.00'], Finance::projectFinance('1000000', '1500000'));
    }

    public function test_company_profit(): void
    {
        self::assertSame(
            ['grossProfit' => '6000000.00', 'operatingProfit' => '3100000.00', 'marginPct' => '66.67'],
            Finance::companyFinance([
                'collected' => '10000000',
                'refunds' => '1000000',
                'projectExpenses' => '3000000',
                'companyExpenses' => '2000000',
                'commissions' => '900000',
            ]),
        );
    }

    public function test_overhead_share(): void
    {
        self::assertSame('2500000.00', Finance::overheadShare(10_000_000, 4));
        self::assertSame('2000000.00', Finance::overheadShare(10_000_000, 4, 5));
        self::assertSame('10000000.00', Finance::overheadShare(10_000_000, 0));
        self::assertSame('0.00', Finance::overheadShare(0, 3));
    }

    public function test_rounding_like_decimal_js(): void
    {
        self::assertSame('3333333.33', Finance::overheadShare(10_000_000, 3));
        self::assertSame('0.33', Finance::overheadShare(1, 3, 0));
        self::assertSame('0.01', Finance::overheadShare('0.005', 1));
        self::assertSame('16.67', Finance::overheadShare('100', 6));
        // toFixed в decimal.js сохраняет знак у «отрицательного нуля».
        self::assertSame('-0.00', Finance::overheadShare('-0.001', 1));
        self::assertSame(['grossProfit' => '-0.00', 'marginPct' => '-100.00'], Finance::projectFinance('0.001', '0.002'));
        self::assertSame('33.33', Finance::marginPct('1', '3'));
        self::assertSame('0.00', Finance::marginPct('-0.00001', '1'));
        self::assertSame(
            ['grossProfit' => '1.00', 'operatingProfit' => '1.50', 'marginPct' => '50.00'],
            Finance::companyFinance([
                'collected' => '3', 'refunds' => '1', 'projectExpenses' => '1',
                'companyExpenses' => 0, 'commissions' => '0', 'otherIncome' => '0.5',
            ]),
        );
    }
}
