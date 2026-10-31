<?php

namespace App\Services\Finance;

use Carbon\CarbonImmutable;

/**
 * Период для дашбордов и финансов: today|week|month|quarter|year|custom (как глобальный фильтр CEO).
 * Границы — полночь по Ташкенту; to — не включительно. Свой период без дат — текущий месяц.
 */
final class Period
{
    public const PRESETS = ['today', 'week', 'month', 'quarter', 'year', 'custom'];

    public const TZ = 'Asia/Tashkent';

    /**
     * @return array{preset:string, from:CarbonImmutable, to:CarbonImmutable, fromDate:string, toDate:string, customFrom:?string, customTo:?string}
     */
    public static function resolve(?string $preset, ?string $from = null, ?string $to = null, ?CarbonImmutable $now = null): array
    {
        $preset = in_array($preset, self::PRESETS, true) ? $preset : 'month';
        $local = ($now ?? CarbonImmutable::now())->setTimezone(self::TZ);
        $mid = fn (int $y, int $m, int $d) => CarbonImmutable::create($y, $m, 1, 0, 0, 0, self::TZ)->addDays($d - 1);
        [$y, $m, $d] = [(int) $local->format('Y'), (int) $local->format('n'), (int) $local->format('j')];
        $range = null;
        if ($preset === 'custom') {
            $ok = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && CarbonImmutable::createFromFormat('!Y-m-d', $v)?->format('Y-m-d') === $v;
            if ($ok($from) && $ok($to)) {
                $a = CarbonImmutable::createFromFormat('!Y-m-d', $from, self::TZ);
                $b = CarbonImmutable::createFromFormat('!Y-m-d', $to, self::TZ)->addDay();
                if ($a < $b) {
                    $range = [$a, $b];
                }
            }
        }
        $range ??= match ($preset) {
            'today' => [$mid($y, $m, $d), $mid($y, $m, $d)->addDay()],
            'week' => [$mid($y, $m, $d)->subDays((int) $local->format('N') - 1), $mid($y, $m, $d)->subDays((int) $local->format('N') - 1)->addDays(7)],
            'quarter' => [$mid($y, intdiv($m - 1, 3) * 3 + 1, 1), $mid($y, intdiv($m - 1, 3) * 3 + 1, 1)->addMonthsNoOverflow(3)],
            'year' => [$mid($y, 1, 1), $mid($y + 1, 1, 1)],
            default => [$mid($y, $m, 1), $mid($y, $m, 1)->addMonthNoOverflow()],
        };

        return [
            'preset' => $preset,
            'from' => $range[0]->utc(),
            'to' => $range[1]->utc(),
            // Календарные даты (для DATE-колонок): [fromDate, toDate)
            'fromDate' => $range[0]->format('Y-m-d'),
            'toDate' => $range[1]->format('Y-m-d'),
            'customFrom' => $preset === 'custom' ? $from : null,
            'customTo' => $preset === 'custom' ? $to : null,
        ];
    }

    public static function fromRequest(): array
    {
        $r = request();

        return self::resolve($r->query('period'), $r->query('from'), $r->query('to'));
    }

    /** Месяц YYYY-MM начала периода — для целей KPI. */
    public static function monthOf(CarbonImmutable $from): string
    {
        return $from->setTimezone(self::TZ)->format('Y-m');
    }

    public static function currentMonth(): string
    {
        return CarbonImmutable::now(self::TZ)->format('Y-m');
    }

    /** Проверенный месяц из запроса или текущий. */
    public static function month(?string $v): string
    {
        return is_string($v) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $v) ? $v : self::currentMonth();
    }

    /** Календарные даты месяца YYYY-MM: [from, to). */
    public static function monthDates(string $period): array
    {
        $a = CarbonImmutable::createFromFormat('!Y-m', $period, self::TZ);

        return [$a->format('Y-m-d'), $a->addMonthNoOverflow()->format('Y-m-d')];
    }
}
