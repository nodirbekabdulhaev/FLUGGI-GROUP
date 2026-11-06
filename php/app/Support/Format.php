<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/** Форматирование для интерфейса: суммы, даты по Ташкенту. */
final class Format
{
    public static function tz(): string
    {
        return config('app.company_timezone', 'Asia/Tashkent');
    }

    /** 9 000 000 UZS */
    public static function money(BigDecimal|string|int|float|null $amount, string $currency = 'UZS'): string
    {
        if ($amount === null || $amount === '') {
            return '—';
        }

        return self::number($amount).' '.$currency;
    }

    /** 9 000 000 / 1 200,5 — пробелы между разрядами, до 2 знаков после запятой. */
    public static function number(BigDecimal|string|int|float $amount, int $maxDecimals = 2): string
    {
        $value = BigDecimal::of((string) $amount)->toScale($maxDecimals, RoundingMode::HalfUp);
        [$int, $frac] = array_pad(explode('.', (string) $value), 2, '');
        $sign = str_starts_with($int, '-') ? '-' : '';
        $int = ltrim($int, '-');
        $int = implode("\u{00A0}", array_map('strrev', array_reverse(str_split(strrev($int), 3))));
        $frac = rtrim($frac, '0');

        return $sign.$int.($frac !== '' ? ','.$frac : '');
    }

    /** Компактно для карточек: 187,4 млн / 1,2 млрд. */
    public static function moneyShort(BigDecimal|string|int|float|null $amount, string $currency = 'UZS'): string
    {
        if ($amount === null || $amount === '') {
            return '—';
        }
        $n = (float) (string) $amount;
        $abs = abs($n);
        $f = fn (float $v) => str_replace('.', ',', rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.'));

        return match (true) {
            $abs >= 1e9 => $f($n / 1e9).' '.Lang::get('common.units.bln').' '.$currency,
            $abs >= 1e6 => $f($n / 1e6).' '.Lang::get('common.units.mln').' '.$currency,
            $abs >= 1e3 && $currency === 'UZS' => $f($n / 1e3).' '.Lang::get('common.units.thousand').' '.$currency,
            default => self::money((string) $amount, $currency),
        };
    }

    public static function local(DateTimeInterface|string|null $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value, 'UTC')->setTimezone(self::tz());
    }

    /** 09.10.2026 14:30 по Ташкенту */
    public static function dateTime(DateTimeInterface|string|null $value): string
    {
        return self::local($value)?->format('d.m.Y H:i') ?? '—';
    }

    /** Дата без времени (DATE-колонки хранят календарный день — без сдвига часового пояса). */
    public static function date(DateTimeInterface|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        if (is_string($value) && strlen($value) === 10) {
            return CarbonImmutable::parse($value)->format('d.m.Y');
        }
        if ($value instanceof DateTimeInterface && $value->format('H:i:s') === '00:00:00') {
            return $value->format('d.m.Y');
        }

        return self::local($value)->format('d.m.Y');
    }

    /** Значение для <input type="datetime-local"> по Ташкенту. */
    public static function toLocalInput(DateTimeInterface|string|null $value): string
    {
        return self::local($value)?->format('Y-m-d\TH:i') ?? '';
    }

    /** <input type="datetime-local"> (Ташкент) → UTC. */
    public static function fromLocalInput(?string $value): ?CarbonImmutable
    {
        return $value ? CarbonImmutable::parse($value, self::tz())->utc() : null;
    }

    /** «Сегодня» по Ташкенту. */
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::tz())->startOfDay();
    }

    /** L-00001 */
    public static function code(string $prefix, int|string|null $n): string
    {
        return $n === null ? '—' : $prefix.'-'.str_pad((string) $n, 5, '0', STR_PAD_LEFT);
    }
}
