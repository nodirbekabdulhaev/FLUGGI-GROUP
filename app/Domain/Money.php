<?php

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Support\Dec;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Деньги: конвертация, сумма, процент. Суммы — BigDecimal (семантика decimal.js).
 */
final class Money
{
    public const UZS = 'UZS';

    public const USD = 'USD';

    /** Конвертация в UZS по зафиксированному курсу. Округление до 2 знаков (банковское). */
    public static function toUzs(BigDecimal|string|int|float $amount, string $currency, BigDecimal|string|int|float $rateToUzs): BigDecimal
    {
        $value = Dec::of($amount);
        if ($currency === self::UZS) {
            return Dec::dp($value, 2, RoundingMode::HalfEven);
        }

        return Dec::dp(Dec::mul($value, $rateToUzs), 2, RoundingMode::HalfEven);
    }

    /** @param  list<BigDecimal|string|int|float>  $values */
    public static function sum(array $values): BigDecimal
    {
        $acc = BigDecimal::zero();
        foreach ($values as $v) {
            $acc = Dec::add($acc, $v);
        }

        return $acc;
    }

    /** Процент a от b; при b = 0 возвращает 0. */
    public static function percent(BigDecimal|string|int|float $part, BigDecimal|string|int|float $whole, int $dp = 2): float
    {
        $w = Dec::of($whole);
        if ($w->isZero()) {
            return 0.0;
        }

        return (float) Dec::dp(Dec::mul(Dec::div($part, $w), 100), $dp)->toString();
    }
}
