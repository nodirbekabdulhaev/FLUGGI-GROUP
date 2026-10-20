<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Регулярные дела: ближайший срок и подстановка периода в название.
 * Правило: frequency 'MONTHLY'|'QUARTERLY'|'YEARLY'; dayOfMonth 1–28;
 * month — YEARLY: 1–12; QUARTERLY: 1–3 (месяц внутри квартала).
 *
 * @phpstan-type RecurrenceRule array{frequency: string, dayOfMonth: int, month?: int|null}
 */
final class Recurrence
{
    public const MONTHLY = 'MONTHLY';

    public const QUARTERLY = 'QUARTERLY';

    public const YEARLY = 'YEARLY';

    private const MONTH_NAMES = [
        'январь',
        'февраль',
        'март',
        'апрель',
        'май',
        'июнь',
        'июль',
        'август',
        'сентябрь',
        'октябрь',
        'ноябрь',
        'декабрь',
    ];

    private const QUARTERS = ['I', 'II', 'III', 'IV'];

    /**
     * Ближайший срок (YYYY-MM-DD) не раньше даты from.
     *
     * @param  RecurrenceRule  $rule
     */
    public static function nextDue(array $rule, string $from): string
    {
        $day = min(28, max(1, $rule['dayOfMonth']));
        $y = (int) explode('-', $from)[0];
        $months = self::months($rule);
        foreach ([$y, $y + 1] as $year) {
            foreach ($months as $m) {
                $d = $year.'-'.self::pad($m).'-'.self::pad($day);
                if (strcmp($d, $from) >= 0) {
                    return $d;
                }
            }
        }

        return ($y + 2).'-'.self::pad($months[0]).'-'.self::pad($day);
    }

    /**
     * Подстановка периода в название: «Налог с оборота за {прошлый_месяц}» → «… за сентябрь 2026».
     * {месяц} {прошлый_месяц} {квартал} {прошлый_квартал} {год} {прошлый_год} — относительно срока.
     */
    public static function fillPeriod(string $template, string $due): string
    {
        [$y, $m] = array_map('intval', array_slice(explode('-', $due), 0, 2));
        $prevMonth = Tasks::addDays($y.'-'.self::pad($m).'-01', -1);
        [$py, $pm] = array_map('intval', array_slice(explode('-', $prevMonth), 0, 2));
        $q = intdiv($m - 1, 3);
        $pq = $q === 0 ? 3 : $q - 1;
        $pqy = $q === 0 ? $y - 1 : $y;

        // Порядок замен — как в TS (цепочка replaceAll).
        $out = str_replace('{прошлый_месяц}', self::MONTH_NAMES[$pm - 1].' '.$py, $template);
        $out = str_replace('{месяц}', self::MONTH_NAMES[$m - 1].' '.$y, $out);
        $out = str_replace('{прошлый_квартал}', self::QUARTERS[$pq].' квартал '.$pqy, $out);
        $out = str_replace('{квартал}', self::QUARTERS[$q].' квартал '.$y, $out);
        $out = str_replace('{прошлый_год}', (string) ($y - 1), $out);

        return str_replace('{год}', (string) $y, $out);
    }

    /**
     * Месяцы, в которые наступает срок.
     *
     * @param  RecurrenceRule  $rule
     * @return list<int>
     */
    private static function months(array $rule): array
    {
        if ($rule['frequency'] === self::MONTHLY) {
            return [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12];
        }
        if ($rule['frequency'] === self::QUARTERLY) {
            $m = $rule['month'] ?? 1;

            return [$m, $m + 3, $m + 6, $m + 9];
        }

        return [$rule['month'] ?? 1];
    }

    private static function pad(int $n): string
    {
        return str_pad((string) $n, 2, '0', STR_PAD_LEFT);
    }
}
