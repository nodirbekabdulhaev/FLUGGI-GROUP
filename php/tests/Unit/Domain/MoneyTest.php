<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Money;

final class MoneyTest extends DomainTestCase
{
    public function test_converts_usd_by_rate_and_keeps_uzs(): void
    {
        self::assertDec('12650500', Money::toUzs('1000', 'USD', '12650.5'));
        self::assertDec('9000000', Money::toUzs('9000000', 'UZS', '12650'));
    }

    public function test_percent_is_zero_safe(): void
    {
        self::assertSame(47.78, Money::percent(4300000, 9000000));
        self::assertSame(0.0, Money::percent(1, 0));
    }

    public function test_to_uzs_rounds_half_even(): void
    {
        self::assertDec('1', Money::toUzs('1.005', 'UZS', 1));
        self::assertDec('1.02', Money::toUzs('1.015', 'UZS', 1));
        self::assertDec('1581.25', Money::toUzs('0.125', 'USD', '12650.02'));
        self::assertSame('1.00', Money::toUzs('1.005', 'UZS', 1)->toString());
    }

    public function test_percent_dp_and_sum(): void
    {
        self::assertSame(33.33, Money::percent(1, 3));
        self::assertSame(-33.0, Money::percent('-1', 3, 0));
        self::assertSame(66.6667, Money::percent(2, 3, 4));
        self::assertDec('0.3000001', Money::sum(['0.1', 0.2, 1e-7]));
        self::assertDec('0', Money::sum([]));
    }
}
