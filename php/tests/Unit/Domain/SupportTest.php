<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Support\Dec;
use App\Domain\Support\Js;

final class SupportTest extends DomainTestCase
{
    public function test_division_rounds_to_20_significant_digits(): void
    {
        self::assertDec('0.33333333333333333333', Dec::div(1, 3));
        self::assertDec('17636684144620811272', Dec::div('123456789012345678901', 7));
        self::assertDec('6666.6666666666666667', Dec::div('2', '0.0003'));
        self::assertDec('-0.66666666666666666667', Dec::div(-2, 3));
        self::assertDec('1234567890123456789', Dec::add('1234567890123456788', 1));
        self::assertDec('123456789012345678900000', Dec::add('123456789012345678901234', 1));
    }

    public function test_js_round_and_number_formatting(): void
    {
        self::assertSame(3, Js::round(2.5));
        self::assertSame(-2, Js::round(-2.5));
        self::assertSame(-3, Js::round(-2.51));
        self::assertSame(0, Js::round(0.49999999999999994));
        self::assertSame('0.30000000000000004', Js::floatToString(0.1 + 0.2));
        self::assertSame('1e+25', Js::floatToString(1e25));
        self::assertSame('65', Js::floatToString(65.0));
        self::assertSame('1.00', Js::toFixed(1.005, 2));
        self::assertSame('0.13', Js::toFixed(0.125, 2));
        self::assertSame('-0.13', Js::toFixed(-0.125, 2));
        self::assertSame('3', Js::toFixed(2.5, 0));
        self::assertSame('-0.00', Js::toFixed(-0.001, 2));
    }
}
