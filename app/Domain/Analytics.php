<?php

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Support\Js;
use App\Domain\Support\Time;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Аналитика: временные ряды и здоровье клиента.
 *
 * @phpstan-type ClientHealthInput array{daysSinceContact: int|float|null, ageDays: int|float, overduePayments: int, overdueProjects: int, activeProjects: int, openDeals: int, paidDeals: int, recentLostDeals: int}
 * @phpstan-type ClientHealthResult array{score: int|float, level: string, reasons: list<string>}
 */
final class Analytics
{
    public const DAY = 'day';

    public const WEEK = 'week';

    public const MONTH = 'month';

    // ─────────────────────────── Временные ряды ───────────────────────────

    /** Шаг графика по длине периода: до 45 дней — дни, до ~6 месяцев — недели, дальше — месяцы. */
    public static function granularityFor(DateTimeInterface $from, DateTimeInterface $to): string
    {
        $days = (Time::ms($to) - Time::ms($from)) / Time::DAY_MS;
        if ($days <= 45) {
            return self::DAY;
        }
        if ($days <= 190) {
            return self::WEEK;
        }

        return self::MONTH;
    }

    /** Ключ корзины (дата по Ташкенту): день — YYYY-MM-DD, неделя — понедельник, месяц — YYYY-MM. */
    public static function bucketKey(DateTimeInterface $at, string $g): string
    {
        $date = Tasks::companyDate($at);
        if ($g === self::DAY) {
            return $date;
        }
        if ($g === self::MONTH) {
            return substr($date, 0, 7);
        }

        return Tasks::addDays($date, 1 - People::isoWeekday($date));
    }

    /**
     * Все корзины периода [from, to) — чтобы на графике не было «дыр».
     *
     * @return list<string>
     */
    public static function bucketKeys(DateTimeInterface $from, DateTimeInterface $to, string $g): array
    {
        $keys = [];
        $last = Tasks::companyDate(Time::fromMs(Time::ms($to) - 1));
        $date = Tasks::companyDate($from);
        while (strcmp($date, $last) <= 0) {
            $k = self::bucketKey(CarbonImmutable::parse("{$date}T12:00:00+05:00"), $g);
            if (($keys[count($keys) - 1] ?? null) !== $k) {
                $keys[] = $k;
            }
            $date = Tasks::addDays($date, 1);
        }

        return $keys;
    }

    // ─────────────────────────── Здоровье клиента (ТЗ §43) ───────────────────────────

    /**
     * Оценка «здоровья» клиента 0–100 (ТЗ §43). Правила прозрачные — причины видны в карточке:
     *  − нет контакта 30 / 60 / 90 дней: −20 / −40 / −65
     *  − просроченная оплата: −25 за каждую (до −50); просроченный проект: −15
     *  − проигранная сделка за 90 дней: −10
     *  + повторные покупки (2+ оплаченные сделки): +10; проект в работе: +10
     * ≥70 — «Здоров», 40–69 — «Внимание», <40 — «Риск».
     * «Потерян» — нет контакта 180+ дней и ничего в работе.
     * daysSinceContact — дней с последнего контакта (активность, встреча, оплата); null — контактов не было.
     *
     * @param  ClientHealthInput  $c
     * @return ClientHealthResult
     */
    public static function clientHealth(array $c): array
    {
        $reasons = [];
        $silent = $c['daysSinceContact'] ?? $c['ageDays'];
        $busy = $c['activeProjects'] + $c['openDeals'] > 0;
        if ($silent >= 180 && ! $busy) {
            return ['score' => 0, 'level' => 'LOST', 'reasons' => ['Нет контакта '.Js::str($silent).' дн., ничего в работе']];
        }

        $score = 100;
        if ($silent >= 90) {
            $score -= 65;
            $reasons[] = 'Нет контакта '.Js::str($silent).' дн.';
        } elseif ($silent >= 60) {
            $score -= 40;
            $reasons[] = 'Нет контакта '.Js::str($silent).' дн.';
        } elseif ($silent >= 30) {
            $score -= 20;
            $reasons[] = 'Нет контакта '.Js::str($silent).' дн.';
        }
        if ($c['overduePayments'] > 0) {
            $score -= min(50, 25 * $c['overduePayments']);
            $reasons[] = 'Просрочено оплат: '.Js::str($c['overduePayments']);
        }
        if ($c['overdueProjects'] > 0) {
            $score -= 15;
            $reasons[] = 'Просрочен проект';
        }
        if ($c['recentLostDeals'] > 0) {
            $score -= 10;
            $reasons[] = 'Проиграна сделка за 90 дней';
        }
        if ($c['paidDeals'] >= 2) {
            $score += 10;
            $reasons[] = 'Повторные покупки: '.Js::str($c['paidDeals']);
        }
        if ($c['activeProjects'] > 0) {
            $score += 10;
            $reasons[] = 'Проектов в работе: '.Js::str($c['activeProjects']);
        }
        $score = max(0, min(100, $score));
        $level = $score >= 70 ? 'HEALTHY' : ($score >= 40 ? 'ATTENTION' : 'RISK');

        return ['score' => $score, 'level' => $level, 'reasons' => $reasons];
    }
}
