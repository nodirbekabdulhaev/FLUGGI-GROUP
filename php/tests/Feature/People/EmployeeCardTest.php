<?php

namespace Tests\Feature\People;

use App\Models\AccAccount;
use App\Models\AccPerson;
use App\Models\AccTransaction;
use App\Models\AuditLog;
use App\Models\Commission;
use App\Models\CommissionRule;
use App\Models\EmployeeAdvance;
use App\Models\Meeting;
use App\Models\Notification;
use App\Models\OutboxEvent;
use App\Models\Payment;
use App\Models\PayrollEntry;
use App\Models\Todo;
use App\Services\Accounting\TaxPayrollService;
use App\Services\Finance\Period;
use App\Services\People\AdvanceService;
use App\Services\People\EmployeeScore;
use App\Services\People\PeopleService;
use App\Support\Outbox;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Tests\Feature\Projects\ProjectFixtures;
use Tests\TestCase;

/** Карточка сотрудника: доступ, эффективность, зарплата и комиссии по месяцам; авансы. */
class EmployeeCardTest extends TestCase
{
    use ProjectFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPeople();
    }

    /** Прошлый месяц (по Ташкенту) и 15-е число в нём — данные целиком в прошлом. */
    private function lastMonth(): array
    {
        $p = CarbonImmutable::createFromFormat('!Y-m', Period::currentMonth())->subMonthNoOverflow();

        return [$p->format('Y-m'), CarbonImmutable::create((int) $p->format('Y'), (int) $p->format('n'), 15, 10, 0, 0, 'UTC')];
    }

    public function test_card_access_by_scope(): void
    {
        // Свою карточку открывает каждый, даже без employee.read
        $this->actingAsUser($this->executor)->get('/team/employees/'.$this->executor->id)->assertOk()->assertSee('Executor One')->assertSee(t('employees.card.tabs.salary'));
        $this->actingAsUser($this->executor)->get('/team/employees/'.$this->manager->id)->assertForbidden();
        // РОП — свой отдел (TEAM), чужой отдел — 404
        $this->actingAsUser($this->rop)->get('/team/employees/'.$this->manager->id)->assertOk()->assertSee('Manager One');
        $this->actingAsUser($this->rop)->get('/team/employees/'.$this->otherManager->id)->assertNotFound();
        // CEO — все, с кнопкой правки и авансом
        $this->actingAsUser($this->ceo)->get('/team/employees/'.$this->otherManager->id)->assertOk()
            ->assertSee(t('payroll.advance.button'))->assertSee('edit=1', false);
        $this->get('/team/employees/'.$this->otherManager->id.'?edit=1')->assertOk()->assertSee('/team/employees/'.$this->otherManager->id.'" ', false);

        // Зарплата чужого: у РОП payroll.read только своя — блока нет
        PayrollEntry::create(['user_id' => $this->manager->id, 'period' => Period::currentMonth(), 'base_salary' => '4000000', 'final_salary' => '4123456']);
        $this->actingAsUser($this->rop)->get('/team/employees/'.$this->manager->id)->assertOk()->assertDontSee(t('employees.salary.title'))->assertDontSee('4 123 456');
        $this->actingAsUser($this->manager)->get('/team/employees/'.$this->manager->id)->assertOk()->assertSee(t('employees.salary.title'))
            ->assertSee('/finance/payroll?period='.Period::currentMonth(), false);
        $this->actingAsUser($this->ceo)->get('/team/employees/'.$this->manager->id)->assertSee(t('employees.salary.title'));

        // Список: имя ведёт на карточку, у видимых по kpi.read — оценка
        $this->actingAsUser($this->ceo)->get('/team/employees')->assertOk()->assertSee('/team/employees/'.$this->manager->id, false);
        $this->actingAsUser($this->rop)->get('/team/managers')->assertOk()->assertSee('/team/employees/'.$this->manager->id, false);
    }

    public function test_manager_score_from_real_data_with_breakdown(): void
    {
        [$period, $mid] = $this->lastMonth();
        // Встречи: 2 проведены, 1 не закрыта (запланирована), отменённая не считается
        foreach (['DONE', 'DONE', 'SCHEDULED', 'CANCELLED'] as $i => $st) {
            Meeting::create(['manager_id' => $this->manager->id, 'team_id' => $this->team1->id, 'starts_at' => $mid->addHours($i), 'type' => 'OFFLINE', 'status' => $st, 'created_by_id' => $this->manager->id]);
        }
        // Дела: одно в срок, одно просрочено
        Todo::create(['title' => 'A', 'owner_id' => $this->manager->id, 'creator_id' => $this->manager->id, 'due_at' => $mid, 'status' => 'DONE', 'completed_at' => $mid->subHour()]);
        Todo::create(['title' => 'B', 'owner_id' => $this->manager->id, 'creator_id' => $this->manager->id, 'due_at' => $mid, 'status' => 'OPEN']);

        $this->as($this->ceo);
        $r = EmployeeScore::forUser($this->manager->fresh(), $period);
        $c = collect($r['components'])->keyBy('key');
        $this->assertNull($c['kpi']['score']);                 // целей нет — исключено
        $this->assertNull($c['conversion']['score']);          // лидов нет
        $this->assertEqualsWithDelta(66.67, $c['meetings']['score'], 0.01);
        $this->assertSame(50.0, $c['todos']['score']);
        // Посещаемость по графику роли: отметок прихода нет — 0 %; итог = (15×66,67 + 20×0 + 10×50) / 45 = 33
        $this->assertSame(0.0, $c['attendance']['score']);
        $this->assertSame(33, $r['score']);
        $this->assertSame('D', $r['grade']);
        $this->assertSame(33.3, $c['meetings']['share']);

        // Нет данных вовсе — «недостаточно данных»
        $hr = $this->user('HR_ADMIN');
        $this->assertNull(EmployeeScore::forUser($hr, $period)['score']);
        $this->actingAsUser($hr)->get('/team/employees/'.$hr->id.'?period='.$period)->assertOk()->assertSee(t('employees.score.noData'));

        // Карточка: оценка, разбор и ссылки на списки
        $this->actingAsUser($this->rop)->get('/team/employees/'.$this->manager->id.'?period='.$period)->assertOk()
            ->assertSee(t('employees.score.c.meetings'))->assertSee(t('employees.score.excluded'))
            ->assertSee('/sales/meetings?view=all&amp;manager_id='.$this->manager->id, false);
        $this->assertCount(6, EmployeeScore::trend($this->manager, $period));
    }

    public function test_commissions_by_month_link_deal_and_project(): void
    {
        $p = $this->project();
        $pay = Payment::create(['deal_id' => $p->deal_id, 'client_id' => $p->client_id, 'project_id' => $p->id, 'amount' => '6000000', 'amount_uzs' => '6000000',
            'type' => 'FULL', 'method' => 'BANK', 'status' => 'PAID', 'paid_at' => now(), 'created_by_id' => $this->manager->id]);
        Commission::create(['user_id' => $this->manager->id, 'payment_id' => $pay->id, 'deal_id' => $p->deal_id, 'rule_id' => CommissionRule::first()->id, 'role' => 'MANAGER',
            'period' => Period::currentMonth(), 'base_amount_uzs' => '6000000', 'rate' => '10', 'amount_uzs' => '600000', 'status' => 'APPROVED', 'calc_snapshot' => []]);

        $this->actingAsUser($this->manager)->get('/team/employees/'.$this->manager->id)->assertOk()
            ->assertSee('/sales/deals/'.$p->deal_id, false)->assertSee('/projects/'.$p->id, false)->assertSee('SMM для Aziz')
            ->assertSee(t('employees.commissions.status.APPROVED'));
        // РОП отдела видит комиссии менеджера (TEAM), чужой менеджер — нет доступа к карточке
        $this->actingAsUser($this->rop)->get('/team/employees/'.$this->manager->id)->assertSee('/projects/'.$p->id, false);
        $this->actingAsUser($this->otherManager)->get('/team/employees/'.$this->manager->id)->assertForbidden();
    }

    public function test_advance_is_deducted_from_payroll_and_frozen_after_approval(): void
    {
        $period = Period::currentMonth();
        $this->manager->employee->update(['base_salary' => '5000000']);
        $this->actingAsUser($this->ceo)->post('/finance/payroll/calculate', ['period' => $period])->assertRedirect();
        $entry = PayrollEntry::where('user_id', $this->manager->id)->where('period', $period)->first();
        $final = (string) $entry->final_salary;

        // Менеджер аванс выдать не может
        $this->actingAsUser($this->manager)->post('/finance/payroll/advances', ['user_id' => $this->manager->id, 'amount' => '1000000', 'paid_at' => $period.'-05'])->assertForbidden();

        $this->actingAsUser($this->ceo)->post('/finance/payroll/advances', ['user_id' => $this->manager->id, 'amount' => '1 500 000', 'paid_at' => $period.'-05', 'comment' => 'На лечение'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $a = EmployeeAdvance::first();
        $this->assertSame([$period, '1500000.00', null], [$a->period, (string) $a->amount, $a->accounting_transaction_id]);
        $entry->refresh();
        $this->assertSame('1500000.00', (string) $entry->advances);
        $this->assertSame($final, (string) $entry->final_salary);       // итого не меняется, меняется «к выплате»
        $this->assertSame((string) BigDecimal::of($final)->minus('1500000')->toScale(2), AdvanceService::payable($entry));
        $this->assertTrue(AuditLog::where('action', 'payroll.advance_create')->where('entity_id', $a->id)->exists());
        $this->assertTrue(OutboxEvent::where('type', 'payroll.advance_created')->exists());

        // Пересчёт месяца сохраняет аванс; на странице зарплаты — колонка «Аванс» и «К выплате»
        PeopleService::calculate($period);
        $this->assertSame('1500000.00', (string) $entry->fresh()->advances);
        $this->get('/finance/payroll?period='.$period)->assertOk()->assertSee(t('payroll.payable'))->assertSee(t('payroll.advance.column').' − '.money('1500000'), false);
        $this->get('/team/employees/'.$this->manager->id)->assertSee('На лечение')->assertSee(money('1500000'));

        // Уведомление сотруднику
        Outbox::processBatch();
        $n = Notification::where('user_id', $this->manager->id)->where('type', 'payroll.advance')->first();
        $this->assertNotNull($n);
        $this->assertStringContainsString('1 500 000', str_replace("\u{00A0}", ' ', $n->title));

        // Свою карточку видит сотрудник — с авансом, без кнопки отмены
        $this->actingAsUser($this->manager)->get('/team/employees/'.$this->manager->id)->assertSee('На лечение')->assertDontSee(t('payroll.advance.cancelConfirm'));

        // Отмена с причиной: авансы месяца пересчитаны
        $this->actingAsUser($this->ceo)->post('/finance/payroll/advances/'.$a->id.'/cancel', ['reason' => 'Ошибка суммы'])->assertSessionHasNoErrors();
        $this->assertNotNull($a->fresh()->cancelled_at);
        $this->assertSame('0.00', (string) $entry->fresh()->advances);
        $this->assertTrue(OutboxEvent::where('type', 'payroll.advance_cancelled')->exists());
        $this->post('/finance/payroll/advances/'.$a->id.'/cancel', ['reason' => 'Ещё раз'])->assertSessionHasErrors();

        // После утверждения месяц заморожен: новый аванс в него — ошибка
        $this->post('/finance/payroll/advances', ['user_id' => $this->manager->id, 'amount' => '500000', 'paid_at' => $period.'-06']);
        $second = EmployeeAdvance::whereNull('cancelled_at')->first();
        PeopleService::transition([$entry->id], 'APPROVED');
        $this->post('/finance/payroll/advances', ['user_id' => $this->manager->id, 'amount' => '100000', 'paid_at' => $period.'-07'])->assertSessionHasErrors('period');
        $this->post('/finance/payroll/advances/'.$second->id.'/cancel', ['reason' => 'Поздно'])->assertSessionHasErrors();
        $this->assertNull($second->fresh()->cancelled_at);
        $this->assertSame(2, EmployeeAdvance::count());
        // CEO аванс не выдаётся
        $this->post('/finance/payroll/advances', ['user_id' => $this->ceo->id, 'amount' => '100000', 'paid_at' => $period.'-07'])->assertSessionHasErrors('user_id');
    }

    public function test_advance_recorded_in_accounting_counts_as_paid_salary(): void
    {
        $period = Period::currentMonth();
        $bank = AccAccount::create(['name' => 'Bank', 'kind' => 'BANK', 'currency' => 'UZS', 'opening_balance' => '0', 'crm_methods' => [], 'is_active' => true]);
        $this->actingAsUser($this->ceo);
        // Нет человека в бухгалтерии — понятная ошибка, аванс не создан
        $this->post('/finance/payroll/advances', ['user_id' => $this->manager->id, 'amount' => '700000', 'paid_at' => $period.'-03', 'accounting' => '1', 'account_id' => $bank->id])
            ->assertSessionHasErrors('accounting');
        $this->assertSame(0, EmployeeAdvance::count());

        $person = AccPerson::create(['full_name' => 'Manager One', 'monthly_salary' => '5000000', 'user_id' => $this->manager->id, 'hired_at' => '2026-01-01']);
        $this->post('/finance/payroll/advances', ['user_id' => $this->manager->id, 'amount' => '700000', 'paid_at' => $period.'-03', 'accounting' => '1', 'account_id' => $bank->id])
            ->assertSessionHasNoErrors();
        $a = EmployeeAdvance::first();
        $tx = AccTransaction::find($a->accounting_transaction_id);
        $this->assertSame(['OUT', 'SALARY', $person->id, $period, '700000.00'], [$tx->direction, $tx->category, $tx->person_id, $tx->period, (string) $tx->amount]);
        $this->assertSame('700000.00', TaxPayrollService::paid($period)[$person->id]);   // «выплачено» в ведомости бухгалтерии

        // Отмена аванса удаляет операцию (мягко, с причиной)
        $this->post('/finance/payroll/advances/'.$a->id.'/cancel', ['reason' => 'Не выдавали'])->assertSessionHasNoErrors();
        $this->assertSoftDeleted('acc_transactions', ['id' => $tx->id]);
        $this->assertSame([], TaxPayrollService::paid($period));
    }
}
