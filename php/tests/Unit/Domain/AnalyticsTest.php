<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Analytics;
use Carbon\CarbonImmutable;

final class AnalyticsTest extends DomainTestCase
{
    private const BASE = [
        'daysSinceContact' => 5,
        'ageDays' => 100,
        'overduePayments' => 0,
        'overdueProjects' => 0,
        'activeProjects' => 0,
        'openDeals' => 0,
        'paidDeals' => 1,
        'recentLostDeals' => 0,
    ];

    private static function d(string $s): CarbonImmutable
    {
        // new Date('2026-10-01') в JS — полночь UTC.
        return new CarbonImmutable($s, 'UTC');
    }

    public function test_granularity_by_period_length(): void
    {
        self::assertSame('day', Analytics::granularityFor(self::d('2026-10-01'), self::d('2026-11-01')));
        self::assertSame('week', Analytics::granularityFor(self::d('2026-07-01'), self::d('2026-10-01')));
        self::assertSame('month', Analytics::granularityFor(self::d('2026-01-01'), self::d('2027-01-01')));
        self::assertSame('day', Analytics::granularityFor(self::d('2026-01-01'), self::d('2026-02-15')));
        self::assertSame('week', Analytics::granularityFor(self::d('2026-01-01'), self::d('2026-02-15T00:00:00.001Z')));
    }

    public function test_buckets_by_tashkent_date_week_from_monday(): void
    {
        // 2026-10-07 20:00 UTC = 2026-10-08 01:00 Ташкент (четверг)
        $at = self::d('2026-10-07T20:00:00Z');
        self::assertSame('2026-10-08', Analytics::bucketKey($at, 'day'));
        self::assertSame('2026-10-05', Analytics::bucketKey($at, 'week'));
        self::assertSame('2026-10', Analytics::bucketKey($at, 'month'));
    }

    public function test_all_buckets_without_gaps(): void
    {
        $from = self::d('2026-09-30T19:00:00Z'); // 1 октября 00:00 Ташкент
        $to = self::d('2026-10-31T19:00:00Z');
        $days = Analytics::bucketKeys($from, $to, 'day');
        self::assertCount(31, $days);
        self::assertSame('2026-10-01', $days[0]);
        self::assertSame('2026-10-31', $days[count($days) - 1]);
        self::assertSame(
            ['2026-09-28', '2026-10-05', '2026-10-12', '2026-10-19', '2026-10-26'],
            Analytics::bucketKeys($from, $to, 'week'),
        );
        self::assertCount(12, Analytics::bucketKeys(self::d('2025-12-31T19:00:00Z'), self::d('2026-12-31T19:00:00Z'), 'month'));
        self::assertSame([], Analytics::bucketKeys($to, $from, 'day'));
    }

    public function test_active_client_is_healthy(): void
    {
        $r = Analytics::clientHealth(['activeProjects' => 1, 'paidDeals' => 2] + self::BASE);
        self::assertSame(100, $r['score']);
        self::assertSame('HEALTHY', $r['level']);
    }

    public function test_silence_and_overdue_reduce_score_with_reasons(): void
    {
        $r = Analytics::clientHealth(['daysSinceContact' => 65, 'overduePayments' => 1] + self::BASE);
        self::assertSame(35, $r['score']);
        self::assertSame('RISK', $r['level']);
        self::assertSame(['Нет контакта 65 дн.', 'Просрочено оплат: 1'], $r['reasons']);
        self::assertSame('HEALTHY', Analytics::clientHealth(['daysSinceContact' => 35] + self::BASE)['level']);
        self::assertSame(70, Analytics::clientHealth(['daysSinceContact' => 35, 'recentLostDeals' => 1] + self::BASE)['score']);
        self::assertSame(50, Analytics::clientHealth(['overduePayments' => 3] + self::BASE)['score']);
    }

    public function test_lost_client_and_age_fallback(): void
    {
        self::assertSame('LOST', Analytics::clientHealth(['daysSinceContact' => 200] + self::BASE)['level']);
        self::assertSame('RISK', Analytics::clientHealth(['daysSinceContact' => 200, 'openDeals' => 1] + self::BASE)['level']);
        self::assertSame('HEALTHY', Analytics::clientHealth(['daysSinceContact' => null, 'ageDays' => 10] + self::BASE)['level']);
        self::assertSame(
            ['score' => 0, 'level' => 'LOST', 'reasons' => ['Нет контакта 200 дн., ничего в работе']],
            Analytics::clientHealth(['daysSinceContact' => 200] + self::BASE),
        );
    }

    public function test_all_reasons_in_order(): void
    {
        $r = Analytics::clientHealth([
            'daysSinceContact' => 95, 'ageDays' => 400, 'overduePayments' => 0, 'overdueProjects' => 1,
            'activeProjects' => 1, 'openDeals' => 0, 'paidDeals' => 3, 'recentLostDeals' => 1,
        ]);
        self::assertSame(30, $r['score']);
        self::assertSame('RISK', $r['level']);
        self::assertSame(
            ['Нет контакта 95 дн.', 'Просрочен проект', 'Проиграна сделка за 90 дней', 'Повторные покупки: 3', 'Проектов в работе: 1'],
            $r['reasons'],
        );
    }
}
