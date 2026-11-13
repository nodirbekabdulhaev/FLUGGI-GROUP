<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Tasks;
use Carbon\CarbonImmutable;

final class TasksTest extends DomainTestCase
{
    private static function now(): CarbonImmutable
    {
        return new CarbonImmutable('2026-10-07T10:00:00Z');
    }

    public function test_open_task_past_deadline_is_overdue_at_least_one_day(): void
    {
        self::assertSame(1, Tasks::taskOverdueDays(new CarbonImmutable('2026-10-07T09:00:00Z'), true, self::now()));
        self::assertSame(3, Tasks::taskOverdueDays(new CarbonImmutable('2026-10-04T10:00:00Z'), true, self::now()));
        self::assertSame(8, Tasks::taskOverdueDays(new CarbonImmutable('2026-09-30T09:00:00Z'), true, self::now()));
        self::assertSame(1, Tasks::taskOverdueDays(new CarbonImmutable('2026-10-07T09:59:59.999Z'), true, self::now()));
    }

    public function test_done_task_or_no_deadline_not_overdue(): void
    {
        self::assertSame(0, Tasks::taskOverdueDays(new CarbonImmutable('2026-10-01T00:00:00Z'), false, self::now()));
        self::assertSame(0, Tasks::taskOverdueDays(null, true, self::now()));
        self::assertSame(0, Tasks::taskOverdueDays(new CarbonImmutable('2026-10-08T00:00:00Z'), true, self::now()));
        self::assertSame(0, Tasks::taskOverdueDays(self::now(), true, self::now()));
    }

    public function test_project_overdue_from_next_day_in_tashkent(): void
    {
        self::assertSame(0, Tasks::projectOverdueDays('2026-10-07', true, self::now()));
        self::assertSame(1, Tasks::projectOverdueDays('2026-10-06', true, self::now()));
        // 20:00 UTC 7 октября — уже 8 октября в Ташкенте
        self::assertSame(1, Tasks::projectOverdueDays('2026-10-07', true, new CarbonImmutable('2026-10-07T20:00:00Z')));
        self::assertSame(0, Tasks::projectOverdueDays('2026-10-01', false, self::now()));
        self::assertSame(30, Tasks::projectOverdueDays('2026-09-07', true, self::now()));
        self::assertSame(0, Tasks::projectOverdueDays(null, true, self::now()));
    }

    public function test_company_date_is_utc_plus_5(): void
    {
        self::assertSame('2026-10-07', Tasks::companyDate(new CarbonImmutable('2026-10-07T18:59:00Z')));
        self::assertSame('2026-10-08', Tasks::companyDate(new CarbonImmutable('2026-10-07T19:00:00Z')));
        self::assertSame('2027-01-02', Tasks::addDays('2026-12-30', 3));
        self::assertSame('2026-02-28', Tasks::addDays('2026-03-01', -1));
        self::assertSame('2026-10-06T19:00:00Z', Tasks::companyDayStart('2026-10-07')->format('Y-m-d\TH:i:s\Z'));
    }

    public function test_template_task_dates(): void
    {
        $d = Tasks::templateTaskDates('2026-10-07', 3, 2);
        self::assertSame('2026-10-10', $d['startDate']);
        self::assertSame('2026-10-11T13:00:00.000Z', $d['deadline']->format('Y-m-d\TH:i:s.v\Z')); // 18:00 Ташкент
        $d = Tasks::templateTaskDates('2026-10-07', 0, 0);
        self::assertSame('2026-10-07', $d['startDate']);
        self::assertSame('2026-10-07T13:00:00.000Z', $d['deadline']->format('Y-m-d\TH:i:s.v\Z'));
    }

    public function test_sort_order_and_rework(): void
    {
        self::assertSame(1000.0, Tasks::sortBetween(null, null));
        self::assertSame(1500.0, Tasks::sortBetween(1000, 2000));
        self::assertSame(0.0, Tasks::sortBetween(null, 1000));
        self::assertSame(4000.0, Tasks::sortBetween(3000, null));
        self::assertSame(1000.5, Tasks::sortBetween(1000, 1001));
        self::assertTrue(Tasks::isRework('REVIEW', 'IN_PROGRESS'));
        self::assertTrue(Tasks::isRework('REVIEW', 'TODO'));
        self::assertFalse(Tasks::isRework('IN_PROGRESS', 'REVIEW'));
    }
}
