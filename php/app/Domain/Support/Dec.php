<?php

declare(strict_types=1);

namespace App\Domain\Support;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Арифметика «как в decimal.js» поверх Brick\Math\BigDecimal.
 * decimal.js по умолчанию: precision = 20 значащих цифр, rounding = ROUND_HALF_UP —
 * каждое plus/minus/times/div округляется до 20 значащих цифр; toDecimalPlaces/toFixed — HALF_UP.
 *
 * @internal
 */
final class Dec
{
    /** Decimal.precision по умолчанию. */
    public const PRECISION = 20;

    /** new Decimal(v); null → 0 (как `v ?? 0` в TS). */
    public static function of(BigDecimal|string|int|float|null $value): BigDecimal
    {
        if ($value === null) {
            return BigDecimal::zero();
        }
        if ($value instanceof BigDecimal) {
            return $value;
        }
        if (is_float($value)) {
            return BigDecimal::of(Js::floatToString($value));
        }
        if (is_string($value)) {
            $value = trim($value);
        }

        return BigDecimal::of($value);
    }

    /** Знак входа с учётом «-0» (в decimal.js new Decimal('-0').isNegative() === true). */
    public static function isNegative(BigDecimal|string|int|float|null $value): bool
    {
        if (is_string($value)) {
            return str_starts_with(trim($value), '-');
        }
        if (is_float($value)) {
            return $value < 0 || ($value == 0.0 && str_starts_with(var_export($value, true), '-'));
        }

        return self::of($value)->isNegative();
    }

    public static function add(BigDecimal|string|int|float|null $a, BigDecimal|string|int|float|null $b): BigDecimal
    {
        return self::round(self::of($a)->plus(self::of($b)));
    }

    public static function sub(BigDecimal|string|int|float|null $a, BigDecimal|string|int|float|null $b): BigDecimal
    {
        return self::round(self::of($a)->minus(self::of($b)));
    }

    public static function mul(BigDecimal|string|int|float|null $a, BigDecimal|string|int|float|null $b): BigDecimal
    {
        return self::round(self::of($a)->multipliedBy(self::of($b)));
    }

    /** Деление с округлением до 20 значащих цифр (HALF_UP), как Decimal#div. */
    public static function div(BigDecimal|string|int|float|null $a, BigDecimal|string|int|float|null $b): BigDecimal
    {
        $x = self::of($a);
        $y = self::of($b);
        if ($y->isZero()) {
            throw new InvalidArgumentException('Division by zero');
        }
        if ($x->isZero()) {
            return BigDecimal::zero();
        }
        // Порядок старшей цифры частного: e или e − 1.
        $e = self::exponent($x) - self::exponent($y);
        $ax = $x->abs();
        $by = $y->abs();
        $pow = BigDecimal::one()->withPointMovedRight($e);
        $lead = $ax->isLessThan($by->multipliedBy($pow)) ? $e - 1 : $e;

        return self::divToScale($x, $y, self::PRECISION - 1 - $lead);
    }

    /** toDecimalPlaces(dp[, mode]) — по умолчанию HALF_UP. */
    public static function dp(BigDecimal $x, int $dp, RoundingMode $mode = RoundingMode::HalfUp): BigDecimal
    {
        return $x->toScale($dp, $mode);
    }

    /**
     * toFixed(dp) — HALF_UP. Как в decimal.js: отрицательное ненулевое число,
     * округлённое до нуля, печатается со знаком («-0.00»).
     */
    public static function toFixed(BigDecimal $x, int $dp = 2): string
    {
        $rounded = $x->toScale($dp, RoundingMode::HalfUp);
        $str = $rounded->toString();

        return $x->isNegative() && $rounded->isZero() ? '-'.$str : $str;
    }

    /** Округление до 20 значащих цифр (результат любой операции decimal.js). */
    public static function round(BigDecimal $x): BigDecimal
    {
        $stripped = $x->strippedOfTrailingZeros();
        if ($stripped->isZero()) {
            return $x;
        }
        $digits = strlen($stripped->getUnscaledValue()->abs()->toString());
        if ($digits <= self::PRECISION) {
            return $x;
        }
        $scale = $stripped->getScale() - ($digits - self::PRECISION);

        return self::divToScale($stripped, BigDecimal::one(), $scale);
    }

    /** Порядок старшей значащей цифры (123 → 2, 0.0012 → −3). */
    private static function exponent(BigDecimal $x): int
    {
        $x = $x->strippedOfTrailingZeros();
        $digits = strlen($x->getUnscaledValue()->abs()->toString());

        return $digits - $x->getScale() - 1;
    }

    /** a / b с округлением HALF_UP до заданного (возможно отрицательного) числа знаков. */
    private static function divToScale(BigDecimal $a, BigDecimal $b, int $scale): BigDecimal
    {
        if ($scale >= 0) {
            return $a->dividedBy($b, $scale, RoundingMode::HalfUp)->strippedOfTrailingZeros();
        }
        $factor = BigDecimal::of(BigInteger::ten()->power(-$scale));

        return $a->dividedBy($b->multipliedBy($factor), 0, RoundingMode::HalfUp)
            ->multipliedBy($factor)
            ->strippedOfTrailingZeros();
    }
}
