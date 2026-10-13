<?php

namespace Tests\Feature\People;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\User;
use App\Services\People\PeopleService;
use Tests\TestCase;

/** Оклад менеджеров и РОП = оклад при 100 % × посещаемость по графику (полдня = 50 % дня). */
class AttendanceSalaryTest extends TestCase
{
    private User $ceo;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ceo = $this->user('CEO');
        // Принят в понедельник 28.09.2026: по графику (пн–пт, 09:00–18:00) в сентябре 3 рабочих дня
        $this->manager = $this->user('MANAGER', employee: ['hired_at' => '2026-09-28']);
    }

    private function mark(string $date, int $minutes, string $status = 'PRESENT'): void
    {
        Attendance::create(['user_id' => $this->manager->id, 'date' => $date, 'status' => $status, 'work_minutes' => $minutes]);
    }

    public function test_ceo_sets_salary_at_full_attendance(): void
    {
        $this->actingAsUser($this->ceo)->get('/finance/payroll?period=2026-09')->assertOk()
            ->assertSee(t('payroll.salariesTitle'))->assertSee($this->manager->full_name);
        $this->put('/finance/payroll/salaries', ['period' => '2026-09', 'salaries' => [$this->manager->id => '4 000 000']])
            ->assertRedirect('/finance/payroll?period=2026-09');
        $this->assertSame('4000000.00', (string) Employee::where('user_id', $this->manager->id)->value('base_salary'));
        $this->assertTrue(AuditLog::where('action', 'employee.salary_update')->where('entity_id', $this->manager->id)->exists());

        // РОП и менеджер оклады не меняют
        $rop = $this->user('ROP');
        $this->actingAsUser($rop)->put('/finance/payroll/salaries', ['salaries' => [$this->manager->id => '1']])->assertForbidden();
    }

    public function test_half_day_counts_as_half_and_missed_day_as_zero(): void
    {
        Employee::where('user_id', $this->manager->id)->update(['base_salary' => 4000000]);
        $this->mark('2026-09-28', 540);          // полный день
        $this->mark('2026-09-29', 270);          // полдня
        // 30.09 — не пришёл
        $this->assertSame('50.00', PeopleService::attendancePct($this->manager->fresh(['employee', 'role']), '2026-09'));

        $this->actingAsUser($this->ceo)->post('/finance/payroll/calculate', ['period' => '2026-09'])->assertRedirect();
        $e = PayrollEntry::where('user_id', $this->manager->id)->where('period', '2026-09')->firstOrFail();
        $this->assertSame('4000000.00', (string) $e->base_full);
        $this->assertSame('50.00', (string) $e->attendance_pct);
        $this->assertSame('2000000.00', (string) $e->base_salary);
        $this->get('/finance/payroll?period=2026-09')->assertOk()->assertSee('50% посещ.', false);
    }

    public function test_overtime_is_capped_and_day_off_is_not_counted(): void
    {
        Employee::where('user_id', $this->manager->id)->update(['base_salary' => 3000000]);
        $this->mark('2026-09-28', 700);              // переработка — засчитывается норма 540
        $this->mark('2026-09-29', 0, 'DAY_OFF');     // выходной — не входит в норму
        $this->mark('2026-09-30', 540, 'LATE');
        $this->assertSame('100.00', PeopleService::attendancePct($this->manager->fresh(['employee', 'role']), '2026-09'));
    }

    public function test_executor_has_no_fixed_salary(): void
    {
        $executor = $this->user('EXECUTOR', employee: ['base_salary' => 5000000]);
        $this->actingAsUser($this->ceo)->post('/finance/payroll/calculate', ['period' => '2026-09']);
        $e = PayrollEntry::where('user_id', $executor->id)->where('period', '2026-09')->firstOrFail();
        $this->assertSame('0.00', (string) $e->base_salary);
        $this->assertNull($e->attendance_pct);
    }
}
