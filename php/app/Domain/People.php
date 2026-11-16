<?php

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Support\Dec;
use App\Domain\Support\Time;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Посещаемость, KPI и зарплата (ТЗ §31, §32, §35). Время — по Ташкенту (UTC+5).
 */
final class People
{
    /** Предел выполнения KPI для бонуса: перевыполнение оплачивается до 120%. */
    public const KPI_BONUS_CAP_PCT = 120;

    /** «09:30» → минуты от полуночи. */
    public static function minutesOf(string $hhmm): int
    {
        $parts = explode(':', $hhmm);

        return (int) ($parts[0] ?? 0) * 60 + (int) ($parts[1] ?? 0);
    }

    /** Момент → «HH:MM» по Ташкенту. */
    public static function tashkentTime(DateTimeInterface $at): string
    {
        return Time::fromMs(Time::ms($at))->setTimezone(Time::TZ)->format('H:i');
    }

    /** День недели ISO (1 — понедельник … 7 — воскресенье) для даты YYYY-MM-DD. */
    public static function isoWeekday(string $date): int
    {
        return (int) Time::fromMs(Time::parseUtcDate($date))->format('N');
    }

    /** Дата YYYY-MM-DD + «HH:MM» по Ташкенту → момент UTC. */
    public static function atTashkent(string $date, string $hhmm): CarbonImmutable
    {
        $d = CarbonImmutable::createFromFormat('!Y-m-d H:i', "{$date} {$hhmm}", Time::TZ);
        if ($d === null) {
            throw new InvalidArgumentException("Invalid date/time: {$date} {$hhmm}");
        }

        return $d->utc();
    }

    /**
     * Опоздание: приход позже начала графика больше чем на «льготные» минуты.
     * Опоздание считается от начала графика (а не от конца льготного окна).
     */
    public static function lateMinutes(DateTimeInterface $checkIn, string $date, string $start, int|float $grace): int
    {
        $late = (int) floor((Time::ms($checkIn) - Time::ms(self::atTashkent($date, $start))) / 60_000);

        return $late > $grace ? $late : 0;
    }

    /** Отработано минут между приходом и уходом. */
    public static function workMinutes(?DateTimeInterface $checkIn, ?DateTimeInterface $checkOut): int
    {
        if ($checkIn === null || $checkOut === null || Time::ms($checkOut) <= Time::ms($checkIn)) {
            return 0;
        }

        return (int) floor((Time::ms($checkOut) - Time::ms($checkIn)) / 60_000);
    }

    /** Выполнение цели, % (2 знака); null — цели нет или она нулевая. */
    public static function completionPct(BigDecimal|string|int|float $fact, BigDecimal|string|int|float|null $target): ?string
    {
        if ($target === null) {
            return null;
        }
        $t = Dec::of($target);
        if ($t->isLessThanOrEqualTo(0)) {
            return null;
        }

        return Dec::toFixed(Dec::dp(Dec::mul(Dec::div($fact, $t), 100), 2), 2);
    }

    /**
     * Среднее по списку процентов (null-ы пропускаются).
     *
     * @param  list<string|null>  $values
     */
    public static function averagePct(array $values): ?string
    {
        $v = array_values(array_filter($values, fn ($x) => $x !== null));
        if (count($v) === 0) {
            return null;
        }
        $sum = BigDecimal::zero();
        foreach ($v as $x) {
            $sum = Dec::add($sum, $x);
        }

        return Dec::toFixed(Dec::dp(Dec::div($sum, count($v)), 2), 2);
    }

    /**
     * Итоговая зарплата (ТЗ §32): оклад + сдельно + KPI-бонус + комиссия + прочие бонусы − штраф.
     * pieceRate — сдельная оплата (исполнители).
     *
     * @param  array{baseSalary: BigDecimal|string|int|float, pieceRate?: BigDecimal|string|int|float|null, kpiBonus: BigDecimal|string|int|float, commission: BigDecimal|string|int|float, otherBonus: BigDecimal|string|int|float, penalty: BigDecimal|string|int|float}  $x
     */
    public static function finalSalary(array $x): string
    {
        $total = Dec::add($x['baseSalary'], $x['pieceRate'] ?? 0);
        $total = Dec::add($total, $x['kpiBonus']);
        $total = Dec::add($total, $x['commission']);
        $total = Dec::add($total, $x['otherBonus']);

        return Dec::toFixed(Dec::sub($total, $x['penalty']), 2);
    }

    /**
     * KPI-бонус за месяц: «бонус при 100%» × выполнение KPI (не больше 120%).
     * Нет целей (pct = null) или не задан бонус — 0.
     */
    public static function kpiBonusFor(BigDecimal|string|int|float|null $target, BigDecimal|string|int|float|null $pct): string
    {
        if ($target === null || $pct === null || self::number($target) <= 0) {
            return '0.00';
        }
        $p = min(max(self::number($pct), 0.0), (float) self::KPI_BONUS_CAP_PCT);

        return Dec::toFixed(Dec::dp(Dec::div(Dec::mul($target, $p), 100), 0), 2);
    }

    /**
     * Границы месяца YYYY-MM по Ташкенту.
     *
     * @return array{from: CarbonImmutable, to: CarbonImmutable}
     */
    public static function monthRange(string $period): array
    {
        [$y, $m] = array_map('intval', explode('-', $period)) + [0, 0];
        $from = CarbonImmutable::create($y, $m, 1, 0, 0, 0, Time::TZ);

        return ['from' => $from->utc(), 'to' => $from->addMonthNoOverflow()->utc()];
    }

    /** Number(v) для строки/числа. */
    private static function number(BigDecimal|string|int|float $v): float
    {
        return $v instanceof BigDecimal ? $v->toFloat() : (float) $v;
    }
}
