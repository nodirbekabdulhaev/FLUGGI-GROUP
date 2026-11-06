<?php

namespace Tests\Feature\People;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\JobRun;
use App\Models\Notification;
use App\Models\OutboxEvent;
use App\Models\PayrollEntry;
use App\Models\User;
use App\Services\Automation\Scheduler;
use App\Services\People\AttendanceCheckout;
use App\Services\People\PeopleService;
use App\Support\Outbox;
use App\Support\Settings;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Забытая отметка ухода: напоминание после конца графика (один раз), авто-уход по концу графика
 * с утверждением CEO, учёт в окладе только после утверждения, настройки.
 * График менеджера (из справочника): пн–пт 09:00–18:00 по Ташкенту = 04:00–13:00 UTC.
 */
class AttendanceCheckoutTest extends TestCase
{
    private User $ceo;

    private User $ceo2;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ceo = $this->user('CEO');
        $this->ceo2 = $this->user('CEO');
        $this->manager = $this->user('MANAGER', attrs: ['full_name' => 'Азиз Каримов']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $utc): CarbonImmutable
    {
        $now = CarbonImmutable::parse($utc, 'UTC');
        CarbonImmutable::setTestNow($now);
        Carbon::setTestNow($now);

        return $now;
    }

    /** Вторник 13.10.2026: приход в 09:00 по Ташкенту. */
    private function checkIn(string $utc = '2026-10-13 04:00:00'): Attendance
    {
        $this->at($utc);
        $this->actingAsUser($this->manager)->post('/attendance/check-in')->assertSessionHasNoErrors();

        return Attendance::where('user_id', $this->manager->id)->latest('date')->firstOrFail();
    }

    private function drain(): void
    {
        while (Outbox::processBatch() > 0);
    }

    private function autoClosed(string $date, int $minutes = 540, ?User $user = null): Attendance
    {
        $in = CarbonImmutable::parse($date.' 04:00:00', 'UTC');

        return Attendance::create([
            'user_id' => ($user ?? $this->manager)->id, 'date' => $date, 'status' => 'PRESENT', 'check_in' => $in, 'check_out' => $in->addMinutes($minutes),
            'work_minutes' => $minutes, 'auto_checkout' => true, 'approval_status' => 'PENDING',
        ]);
    }

    public function test_reminder_once_after_schedule_end_and_grace(): void
    {
        $row = $this->checkIn();
        // 18:10 — ещё в пределах 15 минут
        $this->assertSame(['reminded' => 0, 'closed' => 0], AttendanceCheckout::run($this->at('2026-10-13 13:10:00')));
        // 18:16 — напоминание
        $this->assertSame(['reminded' => 1, 'closed' => 0], AttendanceCheckout::run($this->at('2026-10-13 13:16:00')));
        $n = Notification::where('user_id', $this->manager->id)->where('type', 'attendance.no_checkout')->get();
        $this->assertCount(1, $n);
        $this->assertSame('Вы не отметили уход', $n[0]->title);
        $this->assertStringContainsString('18:00', $n[0]->body);
        $this->assertSame('/attendance', $n[0]->link);
        // Повторные запуски cron — без дублей; задача планировщика — один раз за слот
        $this->assertSame(['reminded' => 0, 'closed' => 0], AttendanceCheckout::run($this->at('2026-10-13 13:40:00')));
        $this->assertTrue(Scheduler::execute('attendance-checkout', 'test-slot', now()));
        $this->assertFalse(Scheduler::execute('attendance-checkout', 'test-slot', now()));
        $this->assertSame(1, JobRun::where('job', 'attendance-checkout')->count());
        $this->assertSame(1, Notification::where('type', 'attendance.no_checkout')->count());
        $this->assertNull($row->fresh()->check_out);

        // Отметил уход сам — авто-ухода нет
        $this->actingAsUser($this->manager)->post('/attendance/check-out')->assertSessionHasNoErrors();
        $this->assertSame(['reminded' => 0, 'closed' => 0], AttendanceCheckout::run($this->at('2026-10-13 16:00:00')));
        $this->assertFalse($row->fresh()->auto_checkout);
    }

    public function test_auto_checkout_sets_schedule_end_flags_and_notifies_employee_and_every_ceo_once(): void
    {
        $row = $this->checkIn();
        // 19:59 — ещё ждём (авто-уход через 2 часа после конца графика)
        $this->assertSame(['reminded' => 1, 'closed' => 0], AttendanceCheckout::run($this->at('2026-10-13 14:59:00')));
        $this->assertSame(['reminded' => 0, 'closed' => 1], AttendanceCheckout::run($this->at('2026-10-13 15:00:00')));
        $row->refresh();
        $this->assertSame('2026-10-13 13:00:00', $row->check_out->format('Y-m-d H:i:s'), 'уход — конец графика 18:00, а не время закрытия');
        $this->assertTrue($row->auto_checkout);
        $this->assertSame('PENDING', $row->approval_status);
        $this->assertSame(540, $row->work_minutes);
        $audit = AuditLog::where('action', 'attendance.auto_checkout')->where('entity_id', $row->id)->firstOrFail();
        $this->assertSame('18:00', $audit->changes['checkOut']['new']);
        $this->assertSame(1, OutboxEvent::where('type', 'attendance.auto_checkout')->count());

        $this->drain();
        $own = Notification::where('user_id', $this->manager->id)->where('type', 'attendance.auto_checkout')->get();
        $this->assertCount(1, $own);
        $this->assertSame('Вы не отметили уход 13.10.2026. Учтено до 18:00', $own[0]->title);
        foreach ([$this->ceo, $this->ceo2] as $ceo) {
            $n = Notification::where('user_id', $ceo->id)->where('type', 'attendance.auto_checkout')->get();
            $this->assertCount(1, $n);
            $this->assertSame('Азиз Каримов не отметил уход 13.10.2026. Учтено до 18:00. Утвердите день', $n[0]->title);
            $this->assertSame('/attendance?tab=approval', $n[0]->link);
        }

        // Повторный запуск cron ничего не меняет и не уведомляет
        $this->assertSame(['reminded' => 0, 'closed' => 0], AttendanceCheckout::run($this->at('2026-10-13 18:00:00')));
        $this->assertSame(['reminded' => 0, 'closed' => 0], AttendanceCheckout::run($this->at('2026-10-14 06:00:00')));
        $this->drain();
        $this->assertSame(3, Notification::where('type', 'attendance.auto_checkout')->count());
        $this->assertSame(1, OutboxEvent::where('type', 'attendance.auto_checkout')->count());

        // Сотрудник видит пометку в своих записях
        $this->actingAsUser($this->manager)->get('/attendance?tab=records&from=2026-10-01&to=2026-10-31')->assertOk()
            ->assertSee(t('attendance.checkout.state.PENDING'));
    }

    public function test_day_off_is_closed_at_end_of_day_with_schedule_end(): void
    {
        // Суббота 17.10.2026 — выходной по графику: закрываем только в 23:59 по Ташкенту
        $row = $this->checkIn('2026-10-17 06:00:00');
        $this->assertSame(0, AttendanceCheckout::run($this->at('2026-10-17 18:58:00'))['closed']);
        $this->assertSame(1, AttendanceCheckout::run($this->at('2026-10-17 18:59:00'))['closed']);
        $this->assertSame('2026-10-17 13:00:00', $row->fresh()->check_out->format('Y-m-d H:i:s'));
    }

    public function test_approve_and_reject_rights_and_decisions(): void
    {
        $a = $this->autoClosed('2026-10-12');
        $b = $this->autoClosed('2026-10-13');
        $rop = $this->user('ROP');
        $hr = $this->user('HR_ADMIN');

        // Сотрудник, руководитель отдела — нет прав; список на утверждение им не показывается
        $this->actingAsUser($this->manager)->post("/attendance/{$a->id}/approve")->assertForbidden();
        $this->actingAsUser($this->manager)->post("/attendance/{$a->id}/reject")->assertForbidden();
        $this->actingAsUser($rop)->post("/attendance/{$a->id}/approve")->assertForbidden();
        $this->actingAsUser($this->manager)->get('/attendance?tab=approval')->assertOk()->assertDontSee(t('attendance.checkout.tab'));
        $this->assertSame('PENDING', $a->fresh()->approval_status);

        $this->actingAsUser($this->ceo)->get('/attendance?tab=approval')->assertOk()
            ->assertSee(t('attendance.checkout.tab').' (2)')->assertSee('Азиз Каримов')->assertSee(t('attendance.checkout.approve'));
        $this->get('/attendance')->assertOk()->assertSee(t('attendance.checkout.tab').' (2)');

        // Утвердить с исправленным временем ухода 17:30
        $this->actingAsUser($this->ceo)->post("/attendance/{$a->id}/approve", ['check_out' => '17:30'])->assertSessionHasNoErrors()->assertRedirect('/attendance?tab=approval');
        $a->refresh();
        $this->assertSame('APPROVED', $a->approval_status);
        $this->assertSame($this->ceo->id, $a->approved_by_id);
        $this->assertSame('2026-10-12 12:30:00', $a->check_out->format('Y-m-d H:i:s'));
        $this->assertSame(510, $a->work_minutes);
        $audit = AuditLog::where('action', 'attendance.checkout_approve')->where('entity_id', $a->id)->firstOrFail();
        $this->assertEquals(['old' => '18:00', 'new' => '17:30'], $audit->changes['checkOut']);
        // Повторно — уже решено; уход раньше прихода — ошибка
        $this->postJson("/attendance/{$a->id}/approve")->assertStatus(422)->assertJsonPath('message', t('attendance.checkout.errors.decided'));
        $this->postJson("/attendance/{$b->id}/approve", ['check_out' => '08:00'])->assertStatus(422)->assertJsonValidationErrors('check_out');
        $this->postJson("/attendance/{$b->id}/approve", ['check_out' => '25:00'])->assertStatus(422);

        // HR (attendance.manage на всю компанию) отклоняет с причиной — день считается прогулом
        $this->actingAsUser($hr)->post("/attendance/{$b->id}/reject", ['reason' => 'Ушёл в 12:00'])->assertSessionHasNoErrors();
        $b->refresh();
        $this->assertSame('REJECTED', $b->approval_status);
        $this->assertSame('ABSENT', $b->status);
        $this->assertSame(0, $b->work_minutes);
        $this->assertSame('Ушёл в 12:00', $b->reject_reason);
        $this->assertTrue(AuditLog::where('action', 'attendance.checkout_reject')->where('entity_id', $b->id)->where('actor_id', $hr->id)->exists());

        $this->drain();
        $decided = Notification::where('user_id', $this->manager->id)->where('type', 'attendance.checkout_decided')->orderBy('title')->get();
        $this->assertSame(['День 12.10.2026 утверждён: учтено до 17:30', 'День 13.10.2026 отклонён — засчитан как прогул'], $decided->pluck('title')->all());
        $this->assertSame('Ушёл в 12:00', $decided[1]->body);
        $this->actingAsUser($this->ceo)->get('/attendance?tab=approval')->assertOk()->assertSee(t('attendance.checkout.emptyTitle'));

        // Обычная запись (не авто-уход) через эти адреса не утверждается
        $plain = Attendance::create(['user_id' => $this->manager->id, 'date' => '2026-10-15', 'status' => 'PRESENT', 'work_minutes' => 540]);
        $this->post("/attendance/{$plain->id}/approve")->assertNotFound();
    }

    public function test_payroll_pct_counts_pending_day_only_after_approval(): void
    {
        // Принят 28.09.2026: в сентябре 3 рабочих дня по графику
        $this->manager->employee->update(['hired_at' => '2026-09-28', 'base_salary' => 3000000]);
        foreach (['2026-09-28', '2026-09-29'] as $d) {
            Attendance::create(['user_id' => $this->manager->id, 'date' => $d, 'status' => 'PRESENT', 'work_minutes' => 540]);
        }
        $pending = $this->autoClosed('2026-09-30');
        $user = fn () => $this->manager->fresh(['employee', 'role']);

        $this->assertSame('66.67', PeopleService::attendancePct($user(), '2026-09'), 'неутверждённый день — 0');
        $this->actingAsUser($this->ceo)->post('/finance/payroll/calculate', ['period' => '2026-09'])->assertRedirect();
        $this->assertSame('2000100.00', (string) PayrollEntry::where('user_id', $this->manager->id)->where('period', '2026-09')->value('base_salary'));
        $this->get('/finance/payroll?period=2026-09')->assertOk()
            ->assertSee(t('payroll.pendingDays', ['count' => 1]))->assertSee('/attendance?tab=approval', false);
        $this->assertSame([$this->manager->id => 1], AttendanceCheckout::pendingByUser('2026-09'));
        $this->assertSame([], AttendanceCheckout::pendingByUser('2026-10'));

        $this->post("/attendance/{$pending->id}/approve")->assertSessionHasNoErrors();
        $this->assertSame('100.00', PeopleService::attendancePct($user(), '2026-09'));
        $this->post('/finance/payroll/calculate', ['period' => '2026-09']);
        $this->assertSame('3000000.00', (string) PayrollEntry::where('user_id', $this->manager->id)->where('period', '2026-09')->value('base_salary'));
        $this->get('/finance/payroll?period=2026-09')->assertOk()->assertDontSee(t('payroll.pendingDays', ['count' => 1]));

        // Отклонённый день — прогул
        Attendance::where('user_id', $this->manager->id)->whereDate('date', '2026-09-29')->delete();
        $rejected = $this->autoClosed('2026-09-29');
        $this->post("/attendance/{$rejected->id}/reject")->assertSessionHasNoErrors();
        $this->assertSame('66.67', PeopleService::attendancePct($user(), '2026-09'));
    }

    public function test_settings_validation_rights_and_switch_off(): void
    {
        $this->assertSame(['checkoutControl' => true, 'reminderGraceMinutes' => 15, 'autoCheckoutDelayMinutes' => 120], AttendanceCheckout::settings());
        $this->actingAsUser($this->ceo)->get('/attendance')->assertOk()->assertSee(t('attendance.checkout.settingsTitle'));
        $this->actingAsUser($this->manager)->get('/attendance')->assertOk()->assertDontSee(t('attendance.checkout.settingsTitle'));
        $this->actingAsUser($this->manager)->put('/attendance/settings', ['checkoutControl' => 1, 'reminderGraceMinutes' => 5, 'autoCheckoutDelayMinutes' => 10])->assertForbidden();

        $this->actingAsUser($this->ceo)->put('/attendance/settings', ['checkoutControl' => 1, 'reminderGraceMinutes' => 30, 'autoCheckoutDelayMinutes' => 10])
            ->assertSessionHasErrors('autoCheckoutDelayMinutes');
        $this->put('/attendance/settings', ['checkoutControl' => 1, 'reminderGraceMinutes' => -1, 'autoCheckoutDelayMinutes' => 1000])
            ->assertSessionHasErrors(['reminderGraceMinutes', 'autoCheckoutDelayMinutes']);
        $this->put('/attendance/settings', ['checkoutControl' => 1, 'reminderGraceMinutes' => 'x', 'autoCheckoutDelayMinutes' => 60])->assertSessionHasErrors('reminderGraceMinutes');

        $hr = $this->user('HR_ADMIN');
        $this->actingAsUser($hr)->put('/attendance/settings', ['checkoutControl' => 1, 'reminderGraceMinutes' => 5, 'autoCheckoutDelayMinutes' => 30])->assertSessionHasNoErrors();
        Settings::forget();
        $this->assertSame(['checkoutControl' => true, 'reminderGraceMinutes' => 5, 'autoCheckoutDelayMinutes' => 30], AttendanceCheckout::settings());
        $audit = AuditLog::where('action', 'settings.attendance')->firstOrFail();
        $this->assertEquals(['old' => 15, 'new' => 5], $audit->changes['reminderGraceMinutes']);

        // Новые сроки действуют: 18:30 — авто-уход
        $row = $this->checkIn();
        $this->assertSame(['reminded' => 0, 'closed' => 1], AttendanceCheckout::run($this->at('2026-10-13 13:30:00')));
        $this->assertTrue($row->fresh()->auto_checkout);

        // Выключено — задача ничего не делает
        $this->actingAsUser($this->ceo)->put('/attendance/settings', ['checkoutControl' => 0, 'reminderGraceMinutes' => 5, 'autoCheckoutDelayMinutes' => 30])->assertSessionHasNoErrors();
        Settings::forget();
        $this->assertSame(['skipped' => true], AttendanceCheckout::run());
    }
}
