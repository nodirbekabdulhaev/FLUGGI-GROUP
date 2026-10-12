<?php

namespace Tests\Feature\People;

use App\Domain\People;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Commission;
use App\Models\CommissionRule;
use App\Models\Contract;
use App\Models\DealStage;
use App\Models\Employee;
use App\Models\ExchangeRate;
use App\Models\Expense;
use App\Models\KpiTarget;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Meeting;
use App\Models\Payment;
use App\Models\PayrollEntry;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Task;
use App\Models\WorkSchedule;
use App\Services\Finance\Period;
use App\Services\People\DashboardService;
use App\Services\People\KpiService;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Projects\ProjectFixtures;
use Tests\TestCase;

/** KPI и цели, дашборды, посещаемость и графики, зарплата (people.int.test.ts). */
class KpiPayrollTest extends TestCase
{
    use ProjectFixtures;

    private Project $p;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPeople();
        ExchangeRate::create(['currency' => 'USD', 'date' => Format::today()->subDays(3)->toDateString(), 'rate_to_uzs' => '12650']);
    }

    private function month(): string
    {
        return Period::currentMonth();
    }

    /** Продажа менеджера на 9 000 000: 2 лида (1 стал сделкой), встреча, КП, договор, оплата, комиссии. */
    private function sale(): void
    {
        $this->p = $this->project(['price' => '9000000', 'price_uzs' => '9000000']);
        $stage = DealStage::where('code', 'NEW')->value('id');
        foreach ([$this->p->deal_id, null] as $deal) {
            Lead::create(['title' => 'Lead', 'source_id' => LeadSource::first()->id, 'owner_id' => $this->manager->id, 'team_id' => $this->team1->id,
                'stage_id' => $stage, 'status' => $deal ? 'CONVERTED' : 'OPEN', 'deal_id' => $deal, 'created_by_id' => $this->manager->id]);
        }
        Meeting::create(['manager_id' => $this->manager->id, 'rop_id' => $this->rop->id, 'team_id' => $this->team1->id, 'starts_at' => now()->subHour(), 'type' => 'OFFLINE', 'status' => 'DONE', 'created_by_id' => $this->manager->id]);
        Proposal::create(['deal_id' => $this->p->deal_id, 'client_id' => $this->p->client_id, 'manager_id' => $this->manager->id, 'title' => 'SMM', 'status' => 'ACCEPTED', 'sent_at' => now()]);
        Contract::create(['deal_id' => $this->p->deal_id, 'client_id' => $this->p->client_id, 'contract_date' => Format::today()->toDateString(), 'amount' => '9000000', 'amount_uzs' => '9000000',
            'status' => 'SIGNED', 'signed_at' => now(), 'created_by_id' => $this->manager->id]);
        $pay = Payment::create(['deal_id' => $this->p->deal_id, 'client_id' => $this->p->client_id, 'project_id' => $this->p->id, 'amount' => '9000000', 'amount_uzs' => '9000000',
            'type' => 'FULL', 'method' => 'BANK', 'status' => 'PAID', 'paid_at' => now(), 'created_by_id' => $this->manager->id]);
        foreach ([[$this->manager->id, 'MANAGER'], [$this->rop->id, 'ROP']] as [$uid, $role]) {
            Commission::create(['user_id' => $uid, 'payment_id' => $pay->id, 'deal_id' => $pay->deal_id, 'rule_id' => CommissionRule::first()->id, 'role' => $role,
                'period' => $this->month(), 'base_amount_uzs' => '9000000', 'rate' => '10', 'amount_uzs' => '900000', 'status' => 'ACCRUED', 'calc_snapshot' => []]);
        }
    }

    public function test_kpi_metrics_targets_and_scopes(): void
    {
        $this->sale();
        // Исполнитель: задача выполнена после возврата с проверки
        $this->actingAsUser($this->rop)->post("/projects/{$this->p->id}/members", ['user_id' => $this->executor->id]);
        $this->post('/tasks', ['project_id' => $this->p->id, 'title' => 'Логотип', 'assignee_id' => $this->executor->id]);
        $t = Task::where('title', 'Логотип')->first();
        $this->actingAsUser($this->executor)->postJson("/tasks/{$t->id}/move", ['status' => 'IN_PROGRESS']);
        $this->postJson("/tasks/{$t->id}/move", ['status' => 'REVIEW']);
        $this->actingAsUser($this->rop)->postJson("/tasks/{$t->id}/move", ['status' => 'IN_PROGRESS']);
        $this->actingAsUser($this->executor)->postJson("/tasks/{$t->id}/move", ['status' => 'DONE'])->assertOk();

        // Цели: РОП ставит менеджеру $1 000 и 2 лида; себе — нельзя; менеджер — не может; чужому — 404
        $set = fn ($u, $targets) => $this->put('/kpi/targets', ['user_id' => $u->id, 'period' => $this->month(), 'targets' => $targets]);
        $this->actingAsUser($this->rop);
        $set($this->manager, [['metric' => 'REVENUE', 'value' => '1000', 'currency' => 'USD'], ['metric' => 'LEADS', 'value' => '2']])->assertSessionHasNoErrors();
        $set($this->rop, [])->assertForbidden();
        $set($this->executor, [])->assertNotFound();
        $this->actingAsUser($this->manager);
        $set($this->manager, [])->assertForbidden();
        $this->actingAsUser($this->ceo);
        $set($this->executor, [['metric' => 'TASKS', 'value' => '4']])->assertSessionHasNoErrors();
        $this->assertTrue(AuditLog::where('action', 'kpi.target')->exists());

        $this->as($this->rop);
        $range = People::monthRange($this->month());
        $rows = collect(KpiService::rows($this->month(), $range['from'], $range['to']));
        $m = $rows->first(fn ($r) => $r['user']->id === $this->manager->id);
        $this->assertSame(['leads' => 2, 'processedLeads' => 1, 'meetings' => 1, 'proposals' => 1, 'contracts' => 1, 'payments' => 1, 'orders' => 1,
            'revenueUzs' => '9000000.00', 'avgCheckUzs' => '9000000.00', 'conversionPct' => '50.00'], $m['manager']);
        $rev = collect($m['targets'])->firstWhere('metric', 'REVENUE');
        // 9 000 000 / 12 650 = 711.46 USD из 1 000 → 71.15%; лиды 2 из 2 → 100%
        $this->assertSame(['711.46', '71.15', 'USD'], [$rev['fact'], $rev['pct'], $rev['currency']]);
        $this->assertSame('85.58', $m['kpiPct']);
        $r = $rows->first(fn ($r) => $r['user']->id === $this->rop->id)['rop'];
        $this->assertSame(['9000000.00', 1, 1, '12650000.00', '71.15'], [$r['teamRevenueUzs'], $r['orders'], $r['managers'], $r['planUzs'], $r['planPct']]);
        $this->assertFalse($rows->contains(fn ($r) => $r['user']->id === $this->executor->id));

        $this->as($this->ceo);
        $ex = KpiService::rows($this->month(), $range['from'], $range['to'], ['group' => 'EXECUTOR'])[0];
        $this->assertSame([1, 1, 1, '100.00'], [$ex['executor']['tasks'], $ex['executor']['done'], $ex['executor']['reworks'], $ex['executor']['completionPct']]);
        $this->assertSame(['TASKS', '25.00'], [$ex['targets'][0]['metric'], $ex['targets'][0]['pct']]);

        // Менеджер видит только себя
        $this->as($this->manager);
        $this->assertSame([$this->manager->id], collect(KpiService::rows($this->month(), $range['from'], $range['to']))->map(fn ($r) => $r['user']->id)->all());
        foreach ([$this->ceo, $this->rop, $this->manager, $this->executor] as $u) {
            foreach (['ru', 'uz'] as $locale) {
                $u->update(['locale' => $locale]);
                $this->actingAsUser($u)->get('/kpi')->assertOk();
            }
        }
        $this->actingAsUser($this->rop)->get("/kpi?group=MANAGER&targets={$this->manager->id}")->assertOk()->assertSee(t('kpi.targetsHint'));
        $this->actingAsUser($this->otherManager)->get("/kpi?targets={$this->manager->id}")->assertNotFound();
    }

    public function test_dashboards_by_role(): void
    {
        $this->sale();
        $this->as($this->ceo);
        $c = DashboardService::get(Period::resolve('month'))['ceo'];
        $this->assertSame(['9000000.00', '9000000.00', '0.00', 2, 1, 1], [$c['revenueUzs'], $c['paidUzs'], $c['expectedUzs'], $c['newLeads'], $c['contracts'], $c['projectsInProgress']]);
        $this->as($this->rop);
        $d = DashboardService::get(Period::resolve('month'));
        $this->assertArrayNotHasKey('ceo', $d);
        $this->assertSame([2, 1, 1, 1, 1], [$d['team']['leads'], $d['team']['meetings'], $d['team']['proposals'], $d['team']['contracts'], $d['team']['payments']]);
        $this->assertSame('9000000.00', $d['team']['managers'][0]['manager']['revenueUzs']);
        $this->as($this->manager);
        $m = DashboardService::get(Period::resolve('month'));
        $this->assertSame(1, $m['own']['kpi']['manager']['orders']);
        $this->assertSame('900000.00', number_format((float) $m['own']['commissionUzs'], 2, '.', ''));
        $this->as($this->executor);
        $this->assertSame(['projects' => 0, 'today' => 0, 'overdue' => 0, 'inProgress' => 0, 'done' => 0], DashboardService::get(Period::resolve('month'))['executor']);

        foreach ([$this->ceo, $this->rop, $this->manager, $this->executor, $this->user('HR_ADMIN'), $this->user('PROJECT_MANAGER')] as $u) {
            foreach (['ru', 'uz'] as $locale) {
                $u->update(['locale' => $locale]);
                foreach (['/dashboard', '/dashboard?period=today', '/dashboard?period=custom&from=2026-01-01&to=2026-12-31', '/dashboard?period=year'] as $url) {
                    $this->actingAsUser($u)->get($url)->assertOk();
                }
            }
        }
        $this->ceo->update(['locale' => 'ru']);
        $this->actingAsUser($this->ceo)->get('/dashboard')->assertSee(t('dash.ceoTitle'))->assertSee(money('9000000.00'));
        $this->manager->update(['locale' => 'ru']);
        $this->actingAsUser($this->manager)->get('/dashboard')->assertSee(t('dash.ownTitle'))->assertSee(t('attendance.checkIn'));
        $this->executor->update(['locale' => 'ru']);
        $this->actingAsUser($this->executor)->get('/dashboard')->assertSee(t('dash.executorTitle'))->assertDontSee(t('attendance.checkIn'));
    }

    public function test_attendance_only_managers_and_rop_with_schedules(): void
    {
        $hr = $this->user('HR_ADMIN');
        $m = $this->actingAsUser($this->manager);
        $m->post('/attendance/check-in')->assertSessionHasNoErrors();
        $rec = Attendance::where('user_id', $this->manager->id)->first();
        $this->assertContains($rec->status, ['PRESENT', 'LATE']);
        $m->post('/attendance/check-in')->assertSessionHas('error');
        $m->post('/attendance/check-out')->assertSessionHasNoErrors();
        $this->assertNotNull($rec->fresh()->check_out);
        $m->post('/attendance/check-out')->assertSessionHas('error');

        // HR вносит прошлый день: приход 09:25 (среда) — опоздание 25 минут, 8.5 часа работы
        $h = $this->actingAsUser($hr);
        $h->put('/attendance', ['user_id' => $this->manager->id, 'date' => '2026-10-07', 'status' => 'PRESENT', 'check_in' => '09:25', 'check_out' => '17:55', 'comment' => 'Забыл отметиться'])->assertSessionHasNoErrors();
        $fixed = Attendance::where('user_id', $this->manager->id)->whereDate('date', '2026-10-07')->first();
        $this->assertSame(['LATE', 25, 510], [$fixed->status, $fixed->late_minutes, $fixed->work_minutes]);
        $h->put('/attendance', ['user_id' => $this->manager->id, 'date' => '2026-10-06', 'status' => 'SICK']);
        $this->actingAsUser($this->manager)->put('/attendance', ['user_id' => $this->manager->id, 'date' => '2026-10-05', 'status' => 'PRESENT'])->assertForbidden();
        $this->as($hr);
        $sum = \App\Services\People\PeopleService::summary('2026-10-01', '2026-10-07', $this->manager->id)[0];
        $this->assertSame([1, 25, 1, '8.5'], [$sum['late'], $sum['lateMinutes'], $sum['sick'], $sum['workHours']]);
        // Исполнитель, проект-менеджер и CEO не отмечаются; раздел исполнителю недоступен
        $this->actingAsUser($this->executor)->get('/attendance')->assertForbidden();
        $this->post('/attendance/check-in')->assertForbidden();
        $this->actingAsUser($this->ceo)->post('/attendance/check-in')->assertForbidden();
        $this->actingAsUser($this->user('PROJECT_MANAGER'))->post('/attendance/check-in')->assertForbidden();
        $this->actingAsUser($hr)->put('/attendance', ['user_id' => $this->executor->id, 'date' => '2026-10-06', 'status' => 'SICK'])->assertSessionHasErrors('user_id');
        $this->as($hr);
        $this->assertNotContains($this->executor->id, collect(\App\Services\People\PeopleService::summary('2026-10-01', '2026-10-07'))->map(fn ($r) => $r['user']->id)->all());
        foreach (['ru', 'uz'] as $locale) {
            $hr->update(['locale' => $locale]);
            $this->actingAsUser($hr)->get('/attendance?from=2026-10-01&to=2026-10-12')->assertOk();
            $this->get('/attendance?tab=records&from=2026-10-01&to=2026-10-12&edit='.$fixed->id)->assertOk()->assertSee('Забыл отметиться');
        }
        $this->actingAsUser($this->manager)->get('/attendance')->assertOk()->assertSee(t('attendance.checkOut') === '' ? '' : t('attendance.today'));

        // Графики: у роли один активный график; индивидуальный важнее графика роли
        $c = $this->actingAsUser($this->ceo);
        $c->post('/settings/schedules', ['name' => 'Ещё менеджеры', 'role_code' => 'MANAGER', 'start_time' => '10:00', 'end_time' => '19:00', 'work_days' => [1, 2, 3, 4, 5], 'grace_minutes' => 0, 'is_active' => '1'])->assertSessionHas('error');
        $c->post('/settings/schedules', ['name' => 'Обучение', 'start_time' => '11:00', 'end_time' => '15:00', 'work_days' => [1, 2, 3, 4, 5, 6, 7], 'grace_minutes' => 0, 'is_active' => '1', 'user_ids' => [$this->manager->id]])->assertSessionHasNoErrors();
        $this->as($this->manager);
        $this->assertSame('Обучение', \App\Services\People\PeopleService::today()['schedule']->name);
        $c = $this->actingAsUser($this->ceo);
        $c->post('/settings/schedules', ['name' => 'Плохой', 'start_time' => '18:00', 'end_time' => '09:00', 'work_days' => [1], 'grace_minutes' => 0])->assertSessionHasErrors('end_time');
        $this->actingAsUser($this->manager)->post('/settings/schedules', ['name' => 'x', 'start_time' => '09:00', 'end_time' => '18:00', 'work_days' => [1], 'grace_minutes' => 0])->assertForbidden();
        $this->assertSame(1, WorkSchedule::where('name', 'Обучение')->count());
    }

    public function test_payroll_fixed_salary_piece_rate_kpi_bonus_and_immutability(): void
    {
        $this->sale();
        Commission::query()->update(['status' => 'APPROVED']);
        Expense::create(['scope' => 'PROJECT', 'project_id' => $this->p->id, 'category' => 'EXECUTOR', 'amount' => '800000', 'exchange_rate' => 1, 'amount_uzs' => '800000',
            'expense_date' => Format::today()->toDateString(), 'payee_user_id' => $this->executor->id, 'created_by_id' => $this->ceo->id]);
        Employee::where('user_id', $this->executor->id)->update(['base_salary' => 3000000]);
        KpiTarget::create(['user_id' => $this->manager->id, 'period' => $this->month(), 'metric' => 'REVENUE', 'target_value' => '18000000', 'set_by_id' => $this->ceo->id]);

        $c = $this->actingAsUser($this->ceo);
        $c->post('/finance/payroll/calculate', ['period' => $this->month()])->assertRedirect();
        $ex = PayrollEntry::where('user_id', $this->executor->id)->first();
        // Оклад — только у менеджеров и РОП; исполнителю — сдельно
        $this->assertSame(['0.00', '800000.00', '800000.00'], [(string) $ex->base_salary, (string) $ex->piece_rate, (string) $ex->final_salary]);
        $c->patch("/finance/payroll/{$ex->id}", ['base_salary' => '1000000'])->assertSessionHasErrors('base_salary');
        $this->assertFalse(PayrollEntry::where('user_id', $this->ceo->id)->exists());
        $mine = PayrollEntry::where('user_id', $this->manager->id)->first();
        $this->assertSame(['900000.00', '0.00', '50.00', '0.00'], [(string) $mine->commission, (string) $mine->base_salary, (string) $mine->kpi_pct, (string) $mine->kpi_bonus]);

        // KPI-бонус при 100% — из карточки, начисляется по выполнению (50% → 1 000 000)
        $c->patch("/finance/payroll/{$mine->id}", ['base_salary' => '5 000 000', 'save_base_salary' => '1', 'kpi_bonus_target' => '2 000 000', 'kpi_bonus' => '0', 'other_bonus' => '200000', 'penalty' => '150000'])->assertSessionHasNoErrors();
        $mine->refresh();
        $this->assertSame(['1000000.00', '6950000.00'], [(string) $mine->kpi_bonus, (string) $mine->final_salary]);
        $this->assertSame('5000000.00', (string) Employee::where('user_id', $this->manager->id)->value('base_salary'));
        // Пересчёт сохраняет ручные поля
        $c->post('/finance/payroll/calculate', ['period' => $this->month()]);
        $this->assertSame('6950000.00', (string) $mine->fresh()->final_salary);
        // KPI-бонус ограничен 120%
        $this->assertSame('2400000.00', People::kpiBonusFor('2000000', '150'));

        // «Мой KPI» на дашборде менеджера
        $this->as($this->manager);
        $my = DashboardService::get(Period::resolve('month'))['myKpi'];
        $this->assertSame(['50.00', '2000000.00', '1000000.00', '5000000.00'], [$my['pct'], $my['bonusTarget'], $my['bonusUzs'], $my['baseSalary']]);
        $this->assertSame('6900000.00', $my['expectedUzs']);
        $this->as($this->ceo);
        $this->assertArrayNotHasKey('myKpi', DashboardService::get(Period::resolve('month')));

        // Свою видит каждый; изменять может только CEO
        $this->actingAsUser($this->manager)->get('/finance/payroll')->assertOk()->assertSee('Manager One');
        $this->as($this->manager);
        $this->assertSame([$this->manager->id], \App\Services\People\PeopleService::payroll($this->month())->pluck('user_id')->all());
        $this->as($this->rop);
        $this->assertSame([$this->rop->id], \App\Services\People\PeopleService::payroll($this->month())->pluck('user_id')->all());
        $this->actingAsUser($this->rop)->patch("/finance/payroll/{$mine->id}", ['penalty' => '0'])->assertForbidden();
        $this->actingAsUser($this->rop)->get('/finance/payroll')->assertOk();
        $c = $this->actingAsUser($this->ceo);
        foreach (['ru', 'uz'] as $locale) {
            $this->ceo->update(['locale' => $locale]);
            $this->actingAsUser($this->ceo)->get('/finance/payroll')->assertOk()->assertSee('Executor One');
            $this->get("/finance/payroll?edit={$mine->id}")->assertOk();
        }
        // Выплатить можно только утверждённую; утверждённую — не изменить и не удалить
        $c->post('/finance/payroll/pay', ['ids' => [$mine->id]])->assertSessionHas('error');
        $c->post('/finance/payroll/approve', ['ids' => [$mine->id]])->assertSessionHasNoErrors();
        $this->assertSame('APPROVED', $mine->fresh()->status);
        $c->patch("/finance/payroll/{$mine->id}", ['penalty' => '0'])->assertSessionHas('error');
        $c->post('/finance/payroll/calculate', ['period' => $this->month()]);
        $this->assertSame('6950000.00', (string) $mine->fresh()->final_salary);
        $c->post('/finance/payroll/pay', ['ids' => [$mine->id]])->assertSessionHasNoErrors();
        $this->assertSame('PAID', $mine->fresh()->status);
        $this->assertSame(['payroll.approve', 'payroll.pay'], AuditLog::where('entity_id', $mine->id)->whereIn('action', ['payroll.approve', 'payroll.pay'])->orderBy('created_at')->pluck('action')->all());
    }
}
