<?php

declare(strict_types=1);

namespace App\Domain\Support;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Семантика чисел JavaScript, которую повторяют формулы домена.
 *
 * @internal
 */
final class Js
{
    /** Math.round: .5 округляется к +∞ (−2.5 → −2), в отличие от PHP round(). */
    public static function round(float|int $x): int
    {
        $x = (float) $x;
        $floor = floor($x);

        return (int) ($x - $floor >= 0.5 ? $floor + 1 : $floor);
    }

    /** Number#toString — кратчайшее представление (0.1 + 0.2 → «0.30000000000000004»). */
    public static function floatToString(float $x): string
    {
        if (is_nan($x) || is_infinite($x)) {
            throw new InvalidArgumentException('Non-finite number');
        }
        if ($x == floor($x) && abs($x) < 1e21) {
            return sprintf('%.0f', $x === -0.0 ? 0.0 : $x);
        }
        $s = var_export($x, true);
        // «1.0E-7» → «1e-7»
        if (stripos($s, 'e') !== false) {
            [$mantissa, $exp] = explode('E', strtoupper($s));
            $mantissa = str_ends_with($mantissa, '.0') ? substr($mantissa, 0, -2) : $mantissa;
            $s = $mantissa.'e'.($exp[0] === '-' ? '' : '+').ltrim($exp, '+');
        }

        return $s;
    }

    /** Число в шаблонной строке JS (`${n}`). */
    public static function str(int|float $x): string
    {
        return is_int($x) ? (string) $x : self::floatToString($x);
    }

    /**
     * Number#toFixed(dp): точное двоичное значение, при равенстве — большее по модулю.
     * (1.005).toFixed(2) === '1.00', (0.125).toFixed(2) === '0.13', (−0.001).toFixed(2) === '-0.00'.
     */
    public static function toFixed(float $x, int $dp): string
    {
        if (is_nan($x)) {
            return 'NaN';
        }
        if (abs($x) >= 1e21) {
            return self::floatToString($x);
        }
        $neg = $x < 0;
        $str = self::exactFloat(abs($x))->toScale($dp, RoundingMode::HalfUp)->toString();

        return $neg ? '-'.$str : $str;
    }

    /** Точное десятичное значение double (без округления до кратчайшего вида). */
    public static function exactFloat(float $x): BigDecimal
    {
        /** @var int $bits */
        $bits = unpack('J', pack('E', $x))[1];
        $negative = $bits < 0;
        $exp = ($bits >> 52) & 0x7FF;
        $mantissa = $bits & 0xFFFFFFFFFFFFF;
        if ($exp === 0x7FF) {
            throw new InvalidArgumentException('Non-finite number');
        }
        if ($exp === 0) {
            $exp = 1;
        } else {
            $mantissa |= 1 << 52;
        }
        $shift = $exp - 1075;
        $value = BigDecimal::of($mantissa);
        $value = $shift >= 0
            ? $value->multipliedBy(BigInteger::of(2)->power($shift))
            : $value->multipliedBy(BigDecimal::of('0.5')->power(-$shift));
        $value = $value->strippedOfTrailingZeros();

        return $negative ? $value->negated() : $value;
    }
}
