<?php

namespace Tests\Feature\Finance;

use App\Models\AuditLog;
use App\Models\Commission;
use App\Models\CommissionRule;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\Notification;
use App\Models\OutboxEvent;
use App\Models\PartnerPayout;
use App\Models\Payment;
use App\Models\PayrollEntry;
use App\Models\Project;
use App\Models\User;
use App\Services\Finance\FinanceService;
use App\Services\Finance\OverheadService;
use App\Services\Finance\PartnerService;
use App\Services\Finance\Period;
use App\Support\Format;
use App\Support\Outbox;
use Carbon\CarbonImmutable;
use Tests\Feature\Projects\ProjectFixtures;
use Tests\TestCase;

/**
 * Чистая прибыль проекта (минус комиссии, накладные и доля фиксированных окладов) и доход партнёра
 * (второго CEO): доля по проектам, заявки на выплату, утверждение первым CEO, права и аудит.
 */
class PartnerShareTest extends TestCase
{
    use ProjectFixtures;

    private User $partner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPeople();
        $this->partner = $this->user('CEO', attrs: ['full_name' => 'Partner Second']);
    }

    private function money(string $v): string
    {
        return money($v);
    }

    private function expense(Project $p, string $amount): void
    {
        Expense::create(['scope' => 'PROJECT', 'project_id' => $p->id, 'category' => 'OTHER', 'amount' => $amount, 'currency' => 'UZS', 'amount_uzs' => $amount, 'exchange_rate' => '1',
            'expense_date' => Format::today()->toDateString(), 'created_by_id' => $this->ceo->id]);
    }

    private function commission(Project $p, string $amount): void
    {
        $pay = Payment::create(['deal_id' => $p->deal_id, 'client_id' => $p->client_id, 'project_id' => $p->id, 'amount' => '1000000', 'amount_uzs' => '1000000',
            'type' => 'PARTIAL', 'method' => 'BANK', 'status' => 'PAID', 'paid_at' => now(), 'created_by_id' => $this->manager->id]);
        Commission::create(['user_id' => $this->manager->id, 'payment_id' => $pay->id, 'deal_id' => $p->deal_id, 'rule_id' => CommissionRule::first()->id,
            'role' => 'MANAGER', 'period' => Period::currentMonth(), 'base_amount_uzs' => '1000000', 'rate' => '10', 'amount_uzs' => $amount, 'status' => 'ACCRUED', 'calc_snapshot' => []]);
    }

    private function salary(User $u, string $amount, array $extra = []): void
    {
        Employee::where('user_id', $u->id)->update(['base_salary' => $amount] + $extra);
    }

    private function setPartner(?User $partner = null, string $pct = '40'): void
    {
        $this->actingAsUser($this->ceo)->put('/settings/finance/partner', ['partner_id' => ($partner ?? $this->partner)->id, 'partner_pct' => $pct])->assertSessionHasNoErrors();
    }

    public function test_net_profit_subtracts_deal_commissions(): void
    {
        // Пример со скриншота CEO: 6 475 000 − 1 185 000 = 5 290 000, комиссии 636 000 → 4 654 000
        $p = $this->project(['price' => '6475000', 'price_uzs' => '6475000']);
        $this->expense($p, '1185000');
        $this->commission($p, '636000');
        $this->as($this->ceo);
        $f = FinanceService::project($p->id);
        $this->assertSame(['5290000.00', '636000.00', '0.00', '0.00', '4654000.00'], [$f['grossProfitUzs'], $f['commissionsUzs'], $f['overheadUzs'], $f['salaryUzs'], $f['netProfitUzs']]);
        $this->assertSame('81.70', $f['marginPct'], 'маржа считается от валовой прибыли, как раньше');
        $row = FinanceService::projectsProfit(null)->getCollection()->firstWhere('id', $p->id)->fin;
        $this->assertSame(['636000.00', '4654000.00'], [$row['commissionsUzs'], $row['netProfitUzs']]);

        $ceo = $this->actingAsUser($this->ceo);
        $ceo->get("/projects/{$p->id}?tab=finance")->assertOk()->assertSee(t('finance.project.salaries'))
            ->assertSee(t('finance.project.partnerShare', ['pct' => '40']))->assertSee($this->money('4654000'));
        $ceo->get('/finance/profit')->assertOk()->assertSee($this->money('4654000'));
    }

    public function test_fixed_salary_pool_is_split_between_projects_in_work(): void
    {
        $a = $this->project(['name' => 'A']);
        $b = $this->project(['name' => 'B']);
        $this->salary($this->manager, '4000000');
        $this->salary($this->rop, '4000000');
        // SMM-специалист с планом роликов — тоже с окладом
        $this->salary($this->executor, '3000000', ['video_target' => 12]);
        // CEO и партнёр в фонд не входят, даже с окладом
        $this->salary($this->ceo, '9000000');
        $this->salary($this->partner, '9000000');
        $this->as($this->ceo);
        $this->assertEqualsCanonicalizing([$this->manager->id, $this->rop->id, $this->executor->id], OverheadService::salaryUserIds());
        $month = Period::currentMonth();
        $this->assertSame([$month => '11000000.00'], OverheadService::salaryByMonth([$month]));
        $this->assertSame('5500000.00', (string) OverheadService::sharesForProjects([$a, $b])['salary'][$a->id]->toScale(2));

        // Есть расчёт зарплаты месяца — берётся рассчитанный оклад (например, при 50 % посещаемости)
        PayrollEntry::create(['user_id' => $this->manager->id, 'period' => $month, 'base_salary' => '2000000', 'base_full' => '4000000', 'attendance_pct' => '50', 'final_salary' => '2000000']);
        $this->assertSame([$month => '9000000.00'], OverheadService::salaryByMonth([$month]));
        $f = FinanceService::project($a->id);
        $this->assertSame('4500000.00', $f['salaryUzs']);
        $this->assertSame('1500000.00', $f['netProfitUzs']);

        // Список сотрудников фонда сохраняется явно; делитель — как у накладных
        $ceo = $this->actingAsUser($this->ceo);
        $ceo->put('/settings/finance/salaries', ['user_ids' => [$this->manager->id, $this->executor->id]])->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing([$this->manager->id, $this->executor->id], OverheadService::salaryUserIds());
        $this->assertTrue(AuditLog::where('action', 'settings.finance')->get()->contains(fn ($l) => isset($l->changes['salaryUserIds'])));
        $this->assertSame([$month => '5000000.00'], OverheadService::salaryByMonth([$month]));
        $ceo->put('/settings/finance/overhead', ['mode' => 'fixed', 'divisor' => 10])->assertSessionHasNoErrors();
        $this->assertSame('500000.00', (string) OverheadService::sharesForProjects([$a, $b])['salary'][$b->id]->toScale(2));
        $this->assertEqualsCanonicalizing([$this->manager->id, $this->executor->id], OverheadService::salaryUserIds(), 'настройка накладных не стирает список окладов');

        // Только кандидаты: CEO в фонд не добавить; без права на финансы компании — 403
        $ceo->put('/settings/finance/salaries', ['user_ids' => [$this->ceo->id]])->assertSessionHasErrors('user_ids.0');
        $ceo->put('/settings/finance/salaries', ['user_ids' => []])->assertSessionHasNoErrors();
        $this->assertSame([], OverheadService::salaryUserIds());
        $this->actingAsUser($this->rop)->put('/settings/finance/salaries', ['user_ids' => [$this->rop->id]])->assertForbidden();
        $this->actingAsUser($this->ceo)->get('/settings/finance')->assertOk()->assertSee(t('financeSettings.salariesTitle'))->assertSee('Manager One');
    }

    public function test_salary_fallback_for_months_without_payroll_respects_hire_date(): void
    {
        $tz = CarbonImmutable::now(Period::TZ);
        $prev = $tz->startOfMonth()->subMonthNoOverflow()->addDays(3);
        $old = $this->project(['name' => 'Old', 'created_at' => $prev->utc()]);
        $this->salary($this->manager, '4000000');
        $this->salary($this->rop, '2000000', ['hired_at' => $tz->startOfMonth()->toDateString()]);
        $this->as($this->ceo);
        $prevMonth = $prev->format('Y-m');
        $month = $tz->format('Y-m');
        $this->assertSame([$prevMonth => '4000000.00', $month => '6000000.00'], OverheadService::salaryByMonth([$prevMonth, $month]));
        // Прошлый месяц проект был единственным в работе — несёт весь фонд, этот месяц — тоже один
        $this->assertSame('10000000.00', (string) OverheadService::sharesForProjects([$old])['salary'][$old->id]->toScale(2));
        $new = $this->project(['name' => 'New']);
        $shares = OverheadService::sharesForProjects([$old->fresh(), $new]);
        $this->assertSame(['7000000.00', '3000000.00'], [(string) $shares['salary'][$old->id]->toScale(2), (string) $shares['salary'][$new->id]->toScale(2)]);
    }

    public function test_partner_share_payout_request_and_approval_by_first_ceo(): void
    {
        // Партнёра и процент меняет только CEO
        $this->actingAsUser($this->rop)->put('/settings/finance/partner', ['partner_id' => $this->rop->id, 'partner_pct' => '50'])->assertForbidden();
        $this->setPartner();
        $this->assertSame(['partnerId' => $this->partner->id, 'partnerPct' => '40'], PartnerService::settings());
        $this->assertTrue(AuditLog::where('action', 'settings.finance')->get()->contains(fn ($l) => ($l->changes['partnerId']['new'] ?? null) === $this->partner->id));
        $this->actingAsUser($this->ceo)->put('/settings/finance/partner', ['partner_id' => $this->partner->id, 'partner_pct' => '120'])->assertSessionHasErrors('partner_pct');

        // 10 000 000 − 2 000 000 − комиссии 1 000 000 = 7 000 000 → 40 % = 2 800 000
        $p = $this->project(['price' => '10000000', 'price_uzs' => '10000000']);
        $this->expense($p, '2000000');
        $this->commission($p, '1000000');
        $this->as($this->ceo);
        $this->assertSame(['configured' => true, 'pct' => '40', 'netProfitUzs' => '7000000.00', 'shareUzs' => '2800000.00', 'paidUzs' => '0.00', 'requestedUzs' => '0.00', 'remainingUzs' => '2800000.00'],
            FinanceService::project($p->id)['partner']);

        // Страница партнёра: партнёр и CEO — да, остальные — 403
        $partner = $this->actingAsUser($this->partner);
        $partner->get('/finance/partner')->assertOk()->assertSee($this->money('2800000'))->assertSee($p->name);
        $this->actingAsUser($this->manager)->get('/finance/partner')->assertForbidden();
        $this->actingAsUser($this->rop)->get('/finance/partner')->assertForbidden();
        $this->actingAsUser($this->manager)->post('/finance/partner/payouts', ['project_id' => $p->id, 'amount' => '1000'])->assertForbidden();

        // Больше остатка — нельзя
        $partner = $this->actingAsUser($this->partner);
        $partner->post('/finance/partner/payouts', ['project_id' => $p->id, 'amount' => '2 800 000,01'])->assertSessionHasErrors('amount');
        $partner->post('/finance/partner/payouts', ['project_id' => $p->id, 'amount' => '1 000 000', 'comment' => 'Аванс'])->assertSessionHasNoErrors();
        $payout = PartnerPayout::firstOrFail();
        $this->assertSame(['REQUESTED', '1000000.00', $this->partner->id, $this->partner->id], [$payout->status, (string) $payout->amount_uzs, $payout->partner_id, $payout->requested_by]);
        $this->assertTrue(AuditLog::where('action', 'partner_payout.request')->where('entity_id', $payout->id)->exists());
        $this->assertTrue(OutboxEvent::where('type', 'partner.payout_requested')->exists());
        // Заявка на утверждении уменьшает остаток
        $partner->post('/finance/partner/payouts', ['project_id' => $p->id, 'amount' => '2000000'])->assertSessionHasErrors('amount');

        Outbox::processBatch();
        $this->assertSame([$this->ceo->id], Notification::where('type', 'partner.payout_requested')->pluck('user_id')->all(), 'уведомление первому CEO, не самому партнёру');

        // Партнёр (тоже CEO) свою выплату не утверждает; РОП — тоже нет
        $partner->post("/finance/partner/payouts/{$payout->id}/approve")->assertForbidden();
        $this->actingAsUser($this->rop)->post("/finance/partner/payouts/{$payout->id}/approve")->assertForbidden();
        $this->assertSame('REQUESTED', $payout->fresh()->status);

        $ceo = $this->actingAsUser($this->ceo);
        $ceo->get('/finance/partner')->assertOk()->assertSee(t('partner.approve'))->assertSee('Аванс')->assertSee('<span class="badge">1</span>', false);
        $ceo->post("/finance/partner/payouts/{$payout->id}/approve")->assertSessionHasNoErrors();
        $this->assertSame(['APPROVED', $this->ceo->id], [$payout->fresh()->status, $payout->fresh()->decided_by]);
        $this->assertTrue(AuditLog::where('action', 'partner_payout.approve')->where('entity_id', $payout->id)->exists());
        $ceo->post("/finance/partner/payouts/{$payout->id}/approve")->assertSessionHas('error');
        Outbox::processBatch();
        $this->assertSame([$this->partner->id], Notification::where('type', 'partner.payout_decided')->pluck('user_id')->all());

        // CEO тоже может запросить; отказ — только с причиной
        $ceo->post('/finance/partner/payouts', ['project_id' => $p->id, 'amount' => '500000'])->assertSessionHasNoErrors();
        $second = PartnerPayout::where('status', 'REQUESTED')->firstOrFail();
        $ceo->post("/finance/partner/payouts/{$second->id}/reject", ['reject_reason' => ''])->assertSessionHasErrors('reject_reason');
        $ceo->post("/finance/partner/payouts/{$second->id}/reject", ['reject_reason' => 'Деньги ещё не получены'])->assertSessionHasNoErrors();
        $this->assertSame(['REJECTED', 'Деньги ещё не получены'], [$second->fresh()->status, $second->fresh()->reject_reason]);
        $this->assertTrue(AuditLog::where('action', 'partner_payout.reject')->where('entity_id', $second->id)->exists());
        $this->assertSame(2, PartnerPayout::count(), 'заявки не удаляются');

        $this->as($this->ceo);
        $share = FinanceService::project($p->id)['partner'];
        $this->assertSame(['2800000.00', '1000000.00', '0.00', '1800000.00'], [$share['shareUzs'], $share['paidUzs'], $share['requestedUzs'], $share['remainingUzs']]);
        $ceo->get("/projects/{$p->id}?tab=finance")->assertOk()->assertSee(t('finance.project.partnerPaid'))->assertSee($this->money('1800000'));
    }

    public function test_partner_with_restricted_role_sees_share_of_every_project(): void
    {
        $pm = $this->user('PROJECT_MANAGER', attrs: ['full_name' => 'Pm Partner']);
        $this->setPartner($pm, '50');
        $p = $this->project(['name' => 'Visible to PM', 'price' => '4000000', 'price_uzs' => '4000000']);
        $loss = $this->project(['name' => 'Loss project', 'price' => '1000000', 'price_uzs' => '1000000']);
        $this->expense($loss, '3000000');

        $partner = $this->actingAsUser($pm);
        // Финансы проекта роли закрыты…
        $partner->get('/finance/profit')->assertForbidden();
        // …но доля партнёра видна по каждому проекту; при убытке доля — 0
        $partner->get('/finance/partner')->assertOk()->assertSee('Visible to PM')->assertSee('Loss project')->assertSee($this->money('2000000'));
        $this->as($pm);
        $rows = PartnerService::overview()['rows']->keyBy('id');
        $this->assertSame(['-2000000.00', '0.00'], [$rows[$loss->id]->partner['netProfitUzs'], $rows[$loss->id]->partner['shareUzs']]);
        $this->assertSame('2000000.00', PartnerService::overview()['totals']['share']);
        $partner->post('/finance/partner/payouts', ['project_id' => $loss->id, 'amount' => '1'])->assertSessionHasErrors('amount');
        // Партнёр не CEO — утверждать не может
        $partner->post('/finance/partner/payouts', ['project_id' => $p->id, 'amount' => '500000'])->assertSessionHasNoErrors();
        $partner->post('/finance/partner/payouts/'.PartnerPayout::firstOrFail()->id.'/approve')->assertForbidden();
        // В меню пункт есть только у партнёра и CEO
        $partner->get('/dashboard')->assertSee('/finance/partner');
        $this->actingAsUser($this->manager)->get('/dashboard')->assertDontSee('/finance/partner');
        // Без назначенного партнёра заявку не создать
        $this->actingAsUser($this->ceo)->put('/settings/finance/partner', ['partner_id' => '', 'partner_pct' => '40'])->assertSessionHasNoErrors();
        $this->actingAsUser($pm)->get('/finance/partner')->assertForbidden();
        $this->actingAsUser($this->ceo)->post('/finance/partner/payouts', ['project_id' => $p->id, 'amount' => '1000'])->assertSessionHas('error');
    }
}
