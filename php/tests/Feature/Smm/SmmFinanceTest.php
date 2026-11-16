<?php

namespace Tests\Feature\Smm;

use App\Models\AccAccount;
use App\Models\AccTransaction;
use App\Models\AuditLog;
use App\Models\Commission;
use App\Models\CommissionRule;
use App\Models\Direction;
use App\Models\Employee;
use App\Models\LeadSource;
use App\Models\Payment;
use App\Models\PayrollEntry;
use App\Models\Role;
use App\Models\Service;
use App\Models\SmmExpense;
use App\Models\SmmIdea;
use App\Models\SmmIdeaHistory;
use App\Models\SmmPublication;
use App\Models\SmmPublicationTarget;
use App\Models\SmmVideoAccrual;
use App\Models\User;
use App\Services\Smm\SmmFinance;
use App\Services\Smm\SmmTargets;
use App\Services\Smm\SmmVideoPay;
use App\Support\Format;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Sales\SalesTestCase;

/**
 * SMM «Финансы»: ставки за ролик (роль и личная), начисления видеографу/монтажёру при публикации (вручную и
 * автопубликацией), сторно при снятии публикации и смене исполнителя, «За ролики SMM» в зарплате,
 * страница «Финансы» (оклад, ролики, бонус, прочие расходы, цена ролика, выручка «Видео SMM», ROI), права, Бухгалтерия.
 */
class SmmFinanceTest extends SalesTestCase
{
    private User $pm;

    private User $smm;

    private User $videographer;

    private User $mobilographer;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pm = $this->user('PROJECT_MANAGER');
        DB::table('user_directions')->insert(['user_id' => $this->pm->id, 'direction_id' => Direction::where('code', 'MEDIA')->value('id')]);
        $this->smm = $this->user('EXECUTOR', attrs: ['full_name' => 'SMM Азиз'], employee: ['specialties' => ['SMM', 'VIDEOGRAPHER']]);
        $this->videographer = $this->user('EXECUTOR', attrs: ['full_name' => 'Видеограф Бек'], employee: ['specialties' => ['VIDEOGRAPHER', 'MOBILOGRAPHER']]);
        $this->mobilographer = $this->user('EXECUTOR', attrs: ['full_name' => 'Мобилограф Дилшод'], employee: ['specialties' => ['MOBILOGRAPHER']]);
        $this->editor = $this->user('EXECUTOR', attrs: ['full_name' => 'Монтажёр Света'], employee: ['specialties' => ['EDITOR']]);
    }

    private function rates(string $video = '50000', string $mobile = '40000', string $edit = '30000', bool $payAssignee = false): void
    {
        Settings::save('smm', ['rateVideographer' => $video, 'rateMobilographer' => $mobile, 'rateEditor' => $edit, 'payAssignee' => $payAssignee]);
    }

    private function idea(string $status = 'SCHEDULED', array $attrs = []): SmmIdea
    {
        static $n = 0;
        $n++;

        return SmmIdea::create($attrs + [
            'title' => 'Ролик '.$n, 'status' => $status, 'author_id' => $this->smm->id, 'assignee_id' => $this->smm->id,
            'videographer_id' => $this->videographer->id, 'planned_publish_date' => '2026-09-10', 'sort_order' => $n,
            'pm_approved_by_id' => $this->pm->id, 'ceo_approved_by_id' => $this->ceo->id,
        ])->refresh();
    }

    private function publish(SmmIdea $idea, string $at = '2026-09-10T12:00', ?User $as = null): void
    {
        $this->actingAsUser($as ?? $this->smm)->post("/smm/{$idea->id}/move", ['status' => 'PUBLISHED', 'published_at' => $at])->assertSessionHasNoErrors();
    }

    /** @return list<array{string,string,string,string}> user, role, amount, period — действующие начисления */
    private function accruals(SmmIdea $idea): array
    {
        return SmmVideoAccrual::where('idea_id', $idea->id)->where('status', 'ACCRUED')->orderBy('role')->get()
            ->map(fn ($a) => [$a->user_id, $a->role, (string) $a->amount_uzs, $a->period])->all();
    }

    public function test_rates_settings_personal_override_and_access(): void
    {
        $this->assertSame(['VIDEOGRAPHER' => '0.00', 'MOBILOGRAPHER' => '0.00', 'EDITOR' => '0.00'], SmmVideoPay::settings()['rates']);
        $this->assertFalse(SmmVideoPay::settings()['payAssignee']);

        $this->actingAsUser($this->ceo)->put('/smm/finance/rates', ['rate_videographer' => '50 000', 'rate_mobilographer' => '40 000,50', 'rate_editor' => '', 'pay_assignee' => '1', 'month' => '2026-09'])
            ->assertSessionHasNoErrors()->assertRedirect('/smm/finance?month=2026-09');
        Settings::forget();
        $this->assertSame(['VIDEOGRAPHER' => '50000.00', 'MOBILOGRAPHER' => '40000.50', 'EDITOR' => '0.00'], SmmVideoPay::settings()['rates']);
        $this->assertTrue(SmmVideoPay::settings()['payAssignee']);
        $log = AuditLog::where('action', 'smm.video_rates_update')->latest('created_at')->firstOrFail();
        $this->assertSame('50000.00', $log->changes['rateVideographer']['new']);
        $this->put('/smm/finance/rates', ['rate_videographer' => '-5'])->assertSessionHasErrors('rate_videographer');

        // Личная ставка — в окне «Ставки» карточки сотрудника
        $this->get('/team/employees?rates='.$this->videographer->id)->assertOk()->assertSee(t('smm.finance.personalRate'));
        $this->put("/team/employees/{$this->videographer->id}/smm-rate", ['smm_video_rate' => '70 000'])->assertSessionHasNoErrors();
        $this->assertSame('70000.00', (string) Employee::where('user_id', $this->videographer->id)->value('smm_video_rate'));
        $this->assertTrue(AuditLog::where('action', 'employee.smm_video_rate_update')->where('entity_id', $this->videographer->id)->exists());
        $this->assertSame('70000', (string) SmmVideoPay::rateFor($this->videographer->fresh(), 'VIDEOGRAPHER')->stripTrailingZeros());
        $this->assertSame('40000.5', (string) SmmVideoPay::rateFor($this->mobilographer->fresh(), 'MOBILOGRAPHER')->stripTrailingZeros());
        $this->get('/smm/finance?month=2026-09')->assertOk()->assertSee(t('smm.finance.personalList'))->assertSee('Видеограф Бек');
        $this->put("/team/employees/{$this->videographer->id}/smm-rate", ['smm_video_rate' => ''])->assertSessionHasNoErrors();
        $this->assertNull(Employee::where('user_id', $this->videographer->id)->value('smm_video_rate'));

        // Права: страница, ставки, расходы, личная ставка — только CEO (smm.finance / payroll.manage ALL)
        foreach ([$this->pm, $this->smm, $this->rop, $this->manager] as $u) {
            $this->actingAsUser($u)->get('/smm/finance')->assertForbidden();
            $this->put('/smm/finance/rates', ['rate_videographer' => '1'])->assertForbidden();
            $this->post('/smm/finance/expenses', ['date' => '2026-09-01', 'category' => 'Студия', 'amount' => '1'])->assertForbidden();
            $this->put("/team/employees/{$this->videographer->id}/smm-rate", ['smm_video_rate' => '1'])->assertForbidden();
        }
        $this->actingAsUser($this->smm)->get('/smm')->assertOk()->assertDontSee('/smm/finance');
        $this->actingAsUser($this->ceo)->get('/smm')->assertOk()->assertSee('/smm/finance');

        // Право smm.finance, выданное другой роли, открывает страницу
        $acc = $this->user('ACCOUNTANT');
        $this->actingAsUser($acc)->get('/smm/finance')->assertForbidden();
        DB::table('role_permissions')->insert(['role_id' => Role::where('code', 'ACCOUNTANT')->value('id'),
            'permission_id' => DB::table('permissions')->where('code', 'smm.finance')->value('id'), 'scope' => 'ALL']);
        $this->actingAsUser($acc)->get('/smm/finance')->assertOk();
        $this->put("/team/employees/{$this->videographer->id}/smm-rate", ['smm_video_rate' => '1'])->assertForbidden();
    }

    public function test_manual_publish_accrues_videographer_with_personal_rate_and_history(): void
    {
        $this->rates();
        Employee::where('user_id', $this->videographer->id)->update(['smm_video_rate' => 55000]);
        $idea = $this->idea();
        $this->publish($idea, '2026-09-30T23:30'); // 30.09 23:30 по Ташкенту — сентябрь
        $this->assertSame([[$this->videographer->id, 'VIDEOGRAPHER', '55000.00', '2026-09']], $this->accruals($idea));

        $h = SmmIdeaHistory::where('idea_id', $idea->id)->where('action', 'pay_accrued')->firstOrFail();
        $this->assertSame('Видеограф Бек · '.t('smm.finance.roles.VIDEOGRAPHER'), $h->changes['payee']['new']);
        $this->assertTrue(AuditLog::where('action', 'smm.video_accrued')->exists());
        $this->assertTrue(DB::table('outbox_events')->where('type', 'smm.video_accrued')->exists());

        // Карточка: «Начислено: Видеограф Видеограф Бек — 55 000» — CEO и самому видеографу; SMM-специалисту — нет
        $line = t('smm.finance.accruedLine', ['role' => t('smm.finance.roles.VIDEOGRAPHER'), 'name' => 'Видеограф Бек', 'amount' => money('55000')]);
        $this->actingAsUser($this->ceo)->get("/smm/{$idea->id}")->assertOk()->assertSee($line)->assertSee(t('smm.history.pay_accrued'));
        $this->actingAsUser($this->videographer)->get("/smm/{$idea->id}")->assertOk()->assertSee($line);
        $this->actingAsUser($this->smm)->get("/smm/{$idea->id}")->assertOk()->assertDontSee($line);

        // Смена ставки задним числом начисление не меняет
        $this->rates('90000');
        $this->actingAsUser($this->smm)->patch("/smm/{$idea->id}", ['title' => 'Новое название'])->assertSessionHasNoErrors();
        $this->assertSame([[$this->videographer->id, 'VIDEOGRAPHER', '55000.00', '2026-09']], $this->accruals($idea));
    }

    public function test_no_accrual_when_rate_zero_and_assignee_toggle(): void
    {
        $this->rates('0', '0', '0');
        $idea = $this->idea();
        $this->publish($idea);
        $this->assertSame([], $this->accruals($idea));

        // Ответственный сам снимает: по умолчанию не платим, с настройкой — платим
        $this->rates('50000');
        $own = $this->idea('SCHEDULED', ['videographer_id' => $this->smm->id]);
        $this->publish($own);
        $this->assertSame([], $this->accruals($own));

        $this->rates('50000', payAssignee: true);
        $own2 = $this->idea('SCHEDULED', ['videographer_id' => $this->smm->id]);
        $this->publish($own2);
        $this->assertSame([[$this->smm->id, 'VIDEOGRAPHER', '50000.00', '2026-09']], $this->accruals($own2));
    }

    public function test_reverse_on_unpublish_and_on_videographer_change_editor_and_mobilographer(): void
    {
        $this->rates();
        $idea = $this->idea();
        // Монтажёр — только со специальностью «Монтажёр»
        $this->actingAsUser($this->smm)->patch("/smm/{$idea->id}", ['editor_id' => $this->mobilographer->id])->assertSessionHasErrors('editor_id');
        $this->patch("/smm/{$idea->id}", ['editor_id' => $this->editor->id])->assertSessionHasNoErrors();
        $this->get("/smm/{$idea->id}")->assertOk()->assertSee('Монтажёр Света');
        $this->publish($idea);
        $this->assertEqualsCanonicalizing([
            [$this->editor->id, 'EDITOR', '30000.00', '2026-09'], [$this->videographer->id, 'VIDEOGRAPHER', '50000.00', '2026-09'],
        ], $this->accruals($idea));
        // Монтажёр видит свою идею
        $this->actingAsUser($this->editor)->get("/smm/{$idea->id}")->assertOk();

        // Видеограф сменился после публикации: сторно прежнему, мобилографу — ставка «Мобилограф»
        $this->actingAsUser($this->pm)->patch("/smm/{$idea->id}", ['videographer_id' => $this->mobilographer->id])->assertSessionHasNoErrors();
        $old = SmmVideoAccrual::where('idea_id', $idea->id)->where('user_id', $this->videographer->id)->firstOrFail();
        $this->assertSame('REVERSED', $old->status);
        $this->assertSame('videographer_changed', $old->reverse_reason);
        $this->assertNull($old->active);
        $this->assertEqualsCanonicalizing([
            [$this->editor->id, 'EDITOR', '30000.00', '2026-09'], [$this->mobilographer->id, 'MOBILOGRAPHER', '40000.00', '2026-09'],
        ], $this->accruals($idea));
        $this->assertTrue(AuditLog::where('action', 'smm.video_reversed')->where('entity_id', $old->id)->exists());

        // Дата публикации перенесена в другой месяц — начисление переезжает
        $this->actingAsUser($this->ceo)->patch("/smm/{$idea->id}", ['published_at' => '2026-10-02T10:00'])->assertSessionHasNoErrors();
        $this->assertSame(['2026-10', '2026-10'], array_column($this->accruals($idea), 3));
        $this->assertSame(1, SmmVideoAccrual::where('idea_id', $idea->id)->where('reverse_reason', 'period_changed')->where('role', 'EDITOR')->count());

        // CEO вернул из «Опубликовано» — всё сторнируется; снова опубликовали — начислено заново
        $this->actingAsUser($this->ceo)->post("/smm/{$idea->id}/move", ['status' => 'SCHEDULED'])->assertSessionHasNoErrors();
        $this->assertSame([], $this->accruals($idea));
        $this->assertSame(2, SmmVideoAccrual::where('idea_id', $idea->id)->where('reverse_reason', 'unpublished')->count());
        $this->publish($idea, '2026-09-12T10:00', $this->ceo);
        $this->assertCount(2, $this->accruals($idea));
        $this->assertSame(7, SmmVideoAccrual::where('idea_id', $idea->id)->count());
        $this->get("/smm/{$idea->id}")->assertOk()->assertSee(t('smm.history.pay_reversed'))->assertSee(t('smm.finance.reverse.unpublished'));

        // Удаление опубликованной идеи — сторно
        $this->delete("/smm/{$idea->id}")->assertRedirect('/smm');
        $this->assertSame([], $this->accruals($idea));
    }

    public function test_auto_publish_accrues_once(): void
    {
        $this->rates();
        $idea = $this->idea();
        $p = SmmPublication::create(['idea_id' => $idea->id, 'status' => 'PUBLISHING', 'kind' => 'REELS']);
        $t1 = SmmPublicationTarget::create(['publication_id' => $p->id, 'kind' => 'INSTAGRAM', 'target_key' => 'instagram', 'status' => 'PUBLISHING']);
        $t2 = SmmPublicationTarget::create(['publication_id' => $p->id, 'kind' => 'INSTAGRAM', 'target_key' => 'instagram2', 'status' => 'PUBLISHING']);
        SmmTargets::published($t1->fresh(['publication', 'channel']), '1789', 'https://instagram.com/reel/abc');
        $this->assertSame('PUBLISHED', $idea->fresh()->status);
        $this->assertSame([[$this->videographer->id, 'VIDEOGRAPHER', '50000.00', SmmVideoPay::periodOf($idea->fresh()->published_at)]], $this->accruals($idea));
        // Второе место — повторного начисления нет
        SmmTargets::published($t2->fresh(['publication', 'channel']), '1790', null);
        $this->assertSame(1, SmmVideoAccrual::where('idea_id', $idea->id)->count());
    }

    public function test_payroll_includes_smm_video_pay(): void
    {
        $this->rates();
        $this->publish($this->idea(), '2026-09-05T10:00');
        $this->publish($this->idea(), '2026-09-06T10:00');
        Employee::where('user_id', $this->videographer->id)->update(['smm_video_rate' => 70000]);
        $this->publish($this->idea(), '2026-09-07T10:00');
        $this->publish($this->idea('SCHEDULED', ['videographer_id' => $this->mobilographer->id, 'editor_id' => $this->editor->id]), '2026-09-08T10:00');

        $this->actingAsUser($this->ceo)->post('/finance/payroll/calculate', ['period' => '2026-09'])->assertRedirect();
        $e = PayrollEntry::where('user_id', $this->videographer->id)->where('period', '2026-09')->firstOrFail();
        $this->assertSame('170000.00', (string) $e->smm_video_pay);
        $this->assertSame(3, $e->smm_video_count);
        $this->assertSame('170000.00', (string) $e->final_salary);
        $this->assertSame('40000.00', (string) PayrollEntry::where('user_id', $this->mobilographer->id)->where('period', '2026-09')->value('final_salary'));
        $this->assertSame('30000.00', (string) PayrollEntry::where('user_id', $this->editor->id)->where('period', '2026-09')->value('smm_video_pay'));
        $this->get('/finance/payroll?period=2026-09')->assertOk()->assertSee(t('payroll.smmVideo'))
            ->assertSee('1 × '.money('70000').' + 2 × '.money('50000'));

        // Ручная правка бонуса — «За ролики» остаётся в итоге
        $this->patch("/finance/payroll/{$e->id}", ['other_bonus' => '10 000'])->assertSessionHasNoErrors();
        $this->assertSame('180000.00', (string) $e->fresh()->final_salary);
        $this->get("/finance/payroll?period=2026-09&edit={$e->id}")->assertOk()->assertSee(t('payroll.smmVideoHint'));
    }

    public function test_finance_page_numbers(): void
    {
        $this->rates();
        $period = Format::today()->format('Y-m');
        $at = Format::today()->setTime(10, 0)->format('Y-m-d\TH:i');
        Employee::where('user_id', $this->smm->id)->update(['base_salary' => 2000000, 'video_target' => 4]);
        $v1 = $this->idea();
        $this->publish($v1, $at);
        $this->publish($this->idea(), $at);
        $this->publish($this->idea('SCHEDULED', ['editor_id' => $this->editor->id]), $at);
        $this->publish($this->idea('SCHEDULED', ['videographer_id' => $this->smm->id]), $at); // сам снимал — без ставки

        // Бонус SMM с продаж (утверждённая комиссия) и выручка: оплата по сделке из ролика минус возврат
        $deal = $this->deal('9000000');
        $deal->update(['smm_idea_id' => $v1->id, 'status' => 'WON', 'won_at' => now()]);
        $pay = fn (string $amount, string $type, ?string $of = null) => Payment::create([
            'deal_id' => $deal->id, 'client_id' => $deal->client_id, 'amount' => $amount, 'amount_uzs' => $amount, 'type' => $type, 'method' => 'CASH',
            'status' => 'PAID', 'paid_at' => now(), 'created_by_id' => $this->rop->id, 'refund_of_id' => $of,
        ]);
        $p = $pay('9000000', 'FULL');
        $pay('1000000', 'REFUND', $p->id);
        Commission::create(['user_id' => $this->smm->id, 'payment_id' => $p->id, 'deal_id' => $deal->id, 'rule_id' => CommissionRule::where('applies_to', 'SMM')->value('id'),
            'role' => 'SMM', 'period' => $period, 'base_amount_uzs' => '9000000', 'rate' => '5', 'amount_uzs' => '450000', 'status' => 'APPROVED', 'calc_snapshot' => []]);
        // Лид «Видео SMM» за месяц
        $source = LeadSource::where('code', 'SMM_VIDEO')->firstOrFail();
        $this->actingAsUser($this->manager)->post('/sales/leads', ['contact_name' => 'Hamza', 'phone' => '+998901234567', 'source_id' => $source->id,
            'service_id' => Service::where('code', 'SMM')->value('id'), 'currency' => 'UZS', 'priority' => 'MEDIUM', 'smm_idea_id' => $v1->id])->assertSessionHasNoErrors();

        // Прочие расходы
        $this->actingAsUser($this->ceo)->post('/smm/finance/expenses', ['date' => Format::today()->toDateString(), 'category' => 'Студия', 'amount' => '300 000', 'comment' => 'Аренда'])
            ->assertSessionHasNoErrors();
        $this->post('/smm/finance/expenses', ['date' => Format::today()->toDateString(), 'category' => '', 'amount' => '1'])->assertSessionHasErrors('category');
        $this->post('/smm/finance/expenses', ['date' => Format::today()->toDateString(), 'category' => 'Реклама', 'amount' => '0'])->assertSessionHasErrors('amount');
        $x = SmmExpense::firstOrFail();
        $this->assertSame('300000.00', (string) $x->amount_uzs);
        $this->assertNull($x->acc_transaction_id);
        $this->assertTrue(AuditLog::where('action', 'smm.expense_create')->where('entity_id', $x->id)->exists());

        // Оклад: месяц не рассчитан — по плану: 2 000 000 × min(4 / 4, 1) (SMM-специалист — ответственный 4 роликов)
        $data = SmmFinance::month($period);
        $rows = $data['people']->keyBy(fn ($r) => $r['user']->id);
        $this->assertSame('2000000.00', $rows[$this->smm->id]['fixed']);
        $this->assertSame('plan', $rows[$this->smm->id]['fixedSource']);
        $this->assertSame('450000.00', $rows[$this->smm->id]['bonus']);
        $this->assertSame('0.00', $rows[$this->smm->id]['videoPay']);
        $this->assertSame('2450000.00', $rows[$this->smm->id]['total']);
        $this->assertSame('150000.00', $rows[$this->videographer->id]['videoPay']);
        $this->assertSame(3, $rows[$this->videographer->id]['videoCount']);
        $this->assertSame('30000.00', $rows[$this->editor->id]['videoPay']);
        $this->assertFalse($rows->has($this->mobilographer->id));
        $t = $data['totals'];
        $this->assertSame('2630000.00', $t['people']);
        $this->assertSame('300000.00', $t['other']);
        $this->assertSame('2930000.00', $t['cost']);
        $this->assertSame(4, $t['videos']);
        $this->assertSame('732500.00', $t['costPerVideo']);
        $this->assertSame(1, $t['leads']);
        $this->assertSame(1, $t['won']);
        $this->assertSame('8000000.00', $t['revenue']);
        $this->assertSame('173.0', $t['roi']);
        $this->assertSame('0.00', $t['paid']);

        $this->get('/smm/finance?month='.$period)->assertOk()->assertSee(money('2930000'))->assertSee(money('732500'))
            ->assertSee('Студия')->assertSee('173.0%')->assertSee('3 × '.money('50000'));

        // Месяц рассчитан, утверждён и выплачен — оклад из строки зарплаты, «Выплачено» = итого
        $this->post('/finance/payroll/calculate', ['period' => $period]);
        $ids = PayrollEntry::where('period', $period)->whereIn('user_id', [$this->smm->id, $this->videographer->id])->pluck('id')->all();
        $this->post('/finance/payroll/approve', ['ids' => $ids])->assertSessionHasNoErrors();
        $this->post('/finance/payroll/pay', ['ids' => $ids])->assertSessionHasNoErrors();
        $rows = SmmFinance::month($period)['people']->keyBy(fn ($r) => $r['user']->id);
        $this->assertSame('payroll', $rows[$this->smm->id]['fixedSource']);
        $this->assertSame('2450000.00', $rows[$this->smm->id]['paid']);
        $this->assertSame('150000.00', $rows[$this->videographer->id]['paid']);
        $this->assertSame('0.00', $rows[$this->videographer->id]['remaining']);
        $this->assertSame('30000.00', $rows[$this->editor->id]['remaining']);

        $this->delete("/smm/finance/expenses/{$x->id}")->assertRedirect();
        $this->assertSoftDeleted('smm_expenses', ['id' => $x->id]);
        $this->assertTrue(AuditLog::where('action', 'smm.expense_delete')->where('entity_id', $x->id)->exists());
    }

    public function test_expense_recorded_in_accounting(): void
    {
        $bank = AccAccount::create(['name' => 'Bank SMM', 'kind' => 'BANK', 'currency' => 'UZS', 'opening_balance' => '0', 'crm_methods' => []]);
        $usd = AccAccount::create(['name' => 'USD', 'kind' => 'BANK', 'currency' => 'USD', 'opening_balance' => '0', 'crm_methods' => []]);
        $this->actingAsUser($this->ceo);
        $base = ['date' => '2026-09-15', 'category' => 'Реквизит', 'amount' => '120 000', 'comment' => 'Свет', 'to_accounting' => '1'];
        $this->post('/smm/finance/expenses', $base)->assertSessionHasErrors('account_id');
        $this->post('/smm/finance/expenses', $base + ['account_id' => $usd->id])->assertSessionHasErrors('account_id');
        $this->assertSame(0, SmmExpense::count());
        $this->get('/smm/finance?month=2026-09')->assertOk()->assertSee('Bank SMM')->assertDontSee('>USD<', false);

        $this->post('/smm/finance/expenses', $base + ['account_id' => $bank->id, 'article' => 'OTHER'])->assertSessionHasNoErrors()->assertRedirect('/smm/finance?month=2026-09');
        $x = SmmExpense::firstOrFail();
        $tx = AccTransaction::findOrFail($x->acc_transaction_id);
        $this->assertSame('OUT', $tx->direction);
        $this->assertSame('OTHER', $tx->category);
        $this->assertSame('120000.00', (string) $tx->amount);
        $this->assertSame('2026-09-15', $tx->date->toDateString());
        $this->assertSame('SMM: Реквизит — Свет', $tx->description);
        $this->assertTrue(AuditLog::where('action', 'accounting.operation.create')->where('entity_id', $tx->id)->exists());
        $this->get('/smm/finance?month=2026-09')->assertOk()->assertSee(t('smm.finance.inAccounting', ['account' => 'Bank SMM']));

        // Удаление расхода — операция в Бухгалтерии удаляется с причиной
        $this->delete("/smm/finance/expenses/{$x->id}")->assertSessionHasNoErrors();
        $this->assertSoftDeleted('acc_transactions', ['id' => $tx->id]);
        $this->assertSame(t('smm.finance.expenseDeletedReason'), AccTransaction::withTrashed()->find($tx->id)->delete_reason);
    }
}
