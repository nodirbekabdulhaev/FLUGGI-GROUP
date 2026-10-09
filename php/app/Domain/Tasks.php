<?php

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Support\Js;
use App\Domain\Support\Time;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Сроки проектов и задач (ТЗ §22–24). Чистые функции — без БД и часов:
 * текущее время передаётся аргументом, чтобы расчёт был проверяемым.
 */
final class Tasks
{
    /** Календарная дата YYYY-MM-DD в часовом поясе компании. */
    public static function companyDate(DateTimeInterface $at): string
    {
        return Time::fromMs(Time::ms($at))->setTimezone(Time::TZ)->format('Y-m-d');
    }

    /** Начало календарного дня компании (полночь по Ташкенту) в UTC. */
    public static function companyDayStart(string $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date, Time::TZ)->utc();
    }

    /**
     * Дней просрочки задачи: дедлайн прошёл, а задача не завершена.
     * Любая просрочка считается минимум одним днём («Просрочено 1 день»).
     */
    public static function taskOverdueDays(?DateTimeInterface $deadline, bool $isOpen, DateTimeInterface $now): int
    {
        if ($deadline === null || ! $isOpen) {
            return 0;
        }
        $late = Time::ms($now) - Time::ms($deadline);

        return $late > 0 ? (int) ceil($late / Time::DAY_MS) : 0;
    }

    /**
     * Дней просрочки проекта: дедлайн — дата, проект просрочен со следующего дня.
     */
    public static function projectOverdueDays(?string $deadline, bool $isActive, DateTimeInterface $now): int
    {
        if ($deadline === null || $deadline === '' || ! $isActive) {
            return 0;
        }
        $today = self::companyDate($now);
        if (strcmp($today, $deadline) <= 0) {
            return 0;
        }

        return Js::round((Time::parseUtcDate($today) - Time::parseUtcDate($deadline)) / Time::DAY_MS);
    }

    /** Дата + N дней (YYYY-MM-DD). */
    public static function addDays(string $date, int|float $days): string
    {
        return Time::utcDate(Time::parseUtcDate($date) + $days * Time::DAY_MS);
    }

    /**
     * Сроки задачи из шаблона: старт = старт проекта + смещение,
     * дедлайн — конец рабочего дня (18:00 по Ташкенту) через durationDays − 1 дней.
     *
     * @return array{startDate: string, deadline: CarbonImmutable}
     */
    public static function templateTaskDates(string $projectStart, int $startOffsetDays, int $durationDays): array
    {
        $startDate = self::addDays($projectStart, $startOffsetDays);
        $endDate = self::addDays($startDate, max(1, $durationDays) - 1);

        return [
            'startDate' => $startDate,
            'deadline' => Time::fromMs(Time::ms(self::companyDayStart($endDate)) + 18 * 3_600_000),
        ];
    }

    /**
     * Позиция карточки в колонке Kanban между соседями (дробный sort_order —
     * перемещение меняет одну строку, а не всю колонку).
     */
    public static function sortBetween(int|float|null $prev, int|float|null $next): float
    {
        if ($prev === null && $next === null) {
            return 1000.0;
        }
        if ($prev === null) {
            return (float) ($next - 1000);
        }
        if ($next === null) {
            return (float) ($prev + 1000);
        }

        return ($prev + $next) / 2;
    }

    /** Задача вернулась с проверки в работу — это переделка (ТЗ §30: качество исполнителя). */
    public static function isRework(string $from, string $to): bool
    {
        return $from === 'REVIEW' && ($to === 'IN_PROGRESS' || $to === 'TODO');
    }
}
