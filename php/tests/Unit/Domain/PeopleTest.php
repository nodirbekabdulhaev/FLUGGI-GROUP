<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\People;
use Carbon\CarbonImmutable;

final class PeopleTest extends DomainTestCase
{
    public function test_late_from_schedule_start_grace_ignored(): void
    {
        self::assertSame(0, People::lateMinutes(People::atTashkent('2026-10-07', '09:08'), '2026-10-07', '09:00', 10));
        self::assertSame(25, People::lateMinutes(People::atTashkent('2026-10-07', '09:25'), '2026-10-07', '09:00', 10));
        self::assertSame(0, People::lateMinutes(People::atTashkent('2026-10-07', '08:40'), '2026-10-07', '09:00', 0));
    }

    public function test_tashkent_time_weekday_and_work_minutes(): void
    {
        self::assertSame('09:15', People::tashkentTime(new CarbonImmutable('2026-10-07T04:15:00Z')));
        self::assertSame(3, People::isoWeekday('2026-10-07')); // среда
        self::assertSame(7, People::isoWeekday('2026-10-11')); // воскресенье
        self::assertSame(570, People::workMinutes(People::atTashkent('2026-10-07', '09:00'), People::atTashkent('2026-10-07', '18:30')));
        self::assertSame(0, People::workMinutes(People::atTashkent('2026-10-07', '09:00'), null));
        self::assertSame(0, People::workMinutes(People::atTashkent('2026-10-07', '18:00'), People::atTashkent('2026-10-07', '09:00')));
    }

    public function test_minutes_of(): void
    {
        self::assertSame(570, People::minutesOf('09:30'));
        self::assertSame(540, People::minutesOf('9'));
        self::assertSame(1085, People::minutesOf('18:05'));
    }

    public function test_completion_and_average(): void
    {
        self::assertSame('70.00', People::completionPct(700, 1000));
        self::assertNull(People::completionPct(5, null));
        self::assertNull(People::completionPct(5, 0));
        self::assertSame('100.00', People::averagePct(['70.00', null, '130.00']));
        self::assertNull(People::averagePct([null]));
    }

    public function test_final_salary(): void
    {
        self::assertSame('6950000.00', People::finalSalary([
            'baseSalary' => '5000000',
            'kpiBonus' => '1000000',
            'commission' => '900000',
            'otherBonus' => '200000',
            'penalty' => '150000',
        ]));
        self::assertSame('-0.00', People::finalSalary([
            'baseSalary' => '0', 'kpiBonus' => 0, 'commission' => 0, 'otherBonus' => 0, 'penalty' => '0.004',
        ]));
    }

    public function test_month_range_in_tashkent(): void
    {
        $r = People::monthRange('2026-10');
        self::assertSame('2026-09-30T19:00:00.000Z', $r['from']->format('Y-m-d\TH:i:s.v\Z'));
        self::assertSame('2026-10-31T19:00:00.000Z', $r['to']->format('Y-m-d\TH:i:s.v\Z'));
        $r = People::monthRange('2026-12');
        self::assertSame('2026-11-30T19:00:00.000Z', $r['from']->format('Y-m-d\TH:i:s.v\Z'));
        self::assertSame('2026-12-31T19:00:00.000Z', $r['to']->format('Y-m-d\TH:i:s.v\Z'));
        self::assertSame('UTC', $r['from']->getTimezone()->getName());
    }

    public function test_kpi_bonus_capped_at_120(): void
    {
        self::assertSame('1500000.00', People::kpiBonusFor('2000000', '75'));
        self::assertSame('2400000.00', People::kpiBonusFor('2000000', '150'));
        self::assertSame('0.00', People::kpiBonusFor('2000000', null));
        self::assertSame('0.00', People::kpiBonusFor(null, '90'));
        self::assertSame('333330.00', People::kpiBonusFor('1000000', '33.333'));
        self::assertSame('500001.00', People::kpiBonusFor('1000001', '50'));
        self::assertSame('2.00', People::kpiBonusFor('3', '50'));
        self::assertSame('0.00', People::kpiBonusFor(0, '50'));
        self::assertSame('0.00', People::kpiBonusFor('1000000', '-5'));
    }
}
