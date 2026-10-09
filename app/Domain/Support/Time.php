<?php

declare(strict_types=1);

namespace App\Domain\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Моменты времени с точностью JS Date (миллисекунды, UTC).
 *
 * @internal
 */
final class Time
{
    /** Часовой пояс компании: UTC+5 без перехода на летнее время. */
    public const TZ = 'Asia/Tashkent';

    public const DAY_MS = 86_400_000;

    /** Date#getTime(): миллисекунды эпохи (микросекунды отбрасываются). */
    public static function ms(DateTimeInterface $at): int
    {
        return $at->getTimestamp() * 1000 + intdiv((int) $at->format('u'), 1000);
    }

    /** new Date(ms) — момент в UTC. */
    public static function fromMs(int|float $ms): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestampMsUTC($ms);
    }

    /** Date.parse(`${date}T00:00:00Z`) для YYYY-MM-DD. */
    public static function parseUtcDate(string $date): int
    {
        $d = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC');
        if ($d === null || $d->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException("Invalid date: {$date}");
        }

        return self::ms($d);
    }

    /** YYYY-MM-DD момента в поясе UTC. */
    public static function utcDate(int|float $ms): string
    {
        return self::fromMs($ms)->format('Y-m-d');
    }
}
