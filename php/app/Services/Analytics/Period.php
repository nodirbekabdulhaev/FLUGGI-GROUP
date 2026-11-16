<?php

namespace App\Services\Analytics;

use Carbon\CarbonImmutable;

/**
 * Период отчёта по Ташкенту: today | week | month | quarter | year | custom (from, to — включительно).
 * Возвращает [from, to) в UTC. Свой период без дат — текущий месяц.
 */
final class Period
{
    public const PRESETS = ['today', 'week', 'month', 'quarter', 'year', 'custom'];

    private const TZ = 'Asia/Tashkent';

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public static function resolve(?string $preset, ?string $from = null, ?string $to = null, ?\DateTimeInterface $now = null): array
    {
        $preset = in_array($preset, self::PRESETS, true) ? $preset : 'month';
        if ($preset === 'custom') {
            $r = self::custom($from, $to);
            if ($r) {
                return $r;
            }
            $preset = 'month';
        }
        $local = CarbonImmutable::instance($now ?? now())->setTimezone(self::TZ)->startOfDay();
        [$a, $b] = match ($preset) {
            'today' => [$local, $local->addDay()],
            'week' => [$local->subDays($local->dayOfWeekIso - 1), $local->subDays($local->dayOfWeekIso - 1)->addDays(7)],
            'month' => [$local->startOfMonth(), $local->startOfMonth()->addMonthNoOverflow()],
            'quarter' => [$local->firstOfQuarter(), $local->firstOfQuarter()->addMonthsNoOverflow(3)],
            'year' => [$local->startOfYear(), $local->startOfYear()->addYear()],
        };

        return [$a->utc(), $b->utc()];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable}|null */
    public static function custom(?string $from, ?string $to): ?array
    {
        $re = '/^\d{4}-\d{2}-\d{2}$/';
        if (! $from || ! $to || ! preg_match($re, $from) || ! preg_match($re, $to)) {
            return null;
        }
        $a = CarbonImmutable::createFromFormat('!Y-m-d', $from, self::TZ);
        $b = CarbonImmutable::createFromFormat('!Y-m-d', $to, self::TZ)?->addDay();
        if (! $a || ! $b || $a->gte($b)) {
            return null;
        }

        return [$a->utc(), $b->utc()];
    }

    public static function ts(\DateTimeInterface $d): string
    {
        return CarbonImmutable::instance($d)->utc()->format('Y-m-d H:i:s.v');
    }
}
