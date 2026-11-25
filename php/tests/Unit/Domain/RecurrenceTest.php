<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Recurrence;

final class RecurrenceTest extends DomainTestCase
{
    public function test_monthly_this_month_or_next(): void
    {
        $r = ['frequency' => 'MONTHLY', 'dayOfMonth' => 4];
        self::assertSame('2026-10-04', Recurrence::nextDue($r, '2026-10-03'));
        self::assertSame('2026-10-04', Recurrence::nextDue($r, '2026-10-04'));
        self::assertSame('2026-11-04', Recurrence::nextDue($r, '2026-10-05'));
        self::assertSame('2027-01-04', Recurrence::nextDue($r, '2026-12-20'));
    }

    public function test_quarterly_and_yearly(): void
    {
        $q = ['frequency' => 'QUARTERLY', 'dayOfMonth' => 15, 'month' => 1];
        self::assertSame('2026-10-15', Recurrence::nextDue($q, '2026-10-07'));
        self::assertSame('2027-01-15', Recurrence::nextDue($q, '2026-10-16'));
        self::assertSame('2026-05-15', Recurrence::nextDue(['month' => 2] + $q, '2026-03-01'));
        $y = ['frequency' => 'YEARLY', 'dayOfMonth' => 1, 'month' => 2];
        self::assertSame('2027-02-01', Recurrence::nextDue($y, '2026-10-07'));
        self::assertSame('2026-12-01', Recurrence::nextDue(['frequency' => 'YEARLY', 'dayOfMonth' => 1, 'month' => 12], '2026-10-07'));
        self::assertSame('2026-02-28', Recurrence::nextDue(['frequency' => 'MONTHLY', 'dayOfMonth' => 31], '2026-02-01'));
        self::assertSame('2027-03-01', Recurrence::nextDue(['frequency' => 'QUARTERLY', 'dayOfMonth' => 0, 'month' => 3], '2026-12-02'));
        self::assertSame('2026-01-10', Recurrence::nextDue(['frequency' => 'YEARLY', 'dayOfMonth' => 10], '2026-01-10'));
    }

    public function test_period_in_title_relative_to_due(): void
    {
        self::assertSame('Налог с оборота за сентябрь 2026', Recurrence::fillPeriod('Налог с оборота за {прошлый_месяц}', '2026-10-04'));
        self::assertSame('за декабрь 2026', Recurrence::fillPeriod('за {прошлый_месяц}', '2027-01-04'));
        self::assertSame('Отчёт за III квартал 2026', Recurrence::fillPeriod('Отчёт за {прошлый_квартал}', '2026-10-04'));
        self::assertSame('Отчёт за IV квартал 2026', Recurrence::fillPeriod('Отчёт за {прошлый_квартал}', '2027-01-04'));
        self::assertSame('Стат за 2026; баланс 2027', Recurrence::fillPeriod('Стат за {прошлый_год}; баланс {год}', '2027-02-01'));
        self::assertSame('март 2026 / I квартал 2026 / 2026', Recurrence::fillPeriod('{месяц} / {квартал} / {год}', '2026-03-15'));
    }
}
