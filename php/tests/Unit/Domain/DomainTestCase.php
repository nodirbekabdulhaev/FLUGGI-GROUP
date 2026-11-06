<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;

abstract class DomainTestCase extends TestCase
{
    /** Сравнение как Decimal#toString(): без хвостовых нулей. */
    protected static function assertDec(string $expected, BigDecimal $actual): void
    {
        self::assertSame($expected, $actual->strippedOfTrailingZeros()->toString());
    }
}
