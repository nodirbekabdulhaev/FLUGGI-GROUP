<?php

namespace Tests\Feature\Finance;

use App\Models\AuditLog;
use App\Models\Commission;
use App\Models\CommissionRule;
use App\Models\Contract;
use App\Models\ExchangeRate;
use App\Models\Expense;
use App\Models\FinanceCategory;
use App\Models\OtherIncome;
use App\Models\Payment;
use App\Models\Project;
use App\Models\ProjectCostLine;
use App\Models\Proposal;
use App\Models\Tariff;
use App\Models\WorkItem;
use App\Providers\ProjectsServiceProvider;
use App\Services\Finance\CostLineService;
use App\Services\Finance\FinanceService;
use App\Services\Finance\OverheadService;
use App\Services\Finance\Period;
use App\Support\Format;
use App\Support\Outbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Projects\ProjectFixtures;
use Tests\TestCase;

/** Финансы (ТЗ §25–27): расходы, карточка проекта, дашборд, прочие поступления, план по тарифу. */
class FinanceTest extends TestCase
{
    use ProjectFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPeople();
        ExchangeRate::create(['currency' => 'USD', 'date' => Format::today()->subDays(3)->toDateString(), 'rate_to_uzs' => '12650', 'set_by_id' => null]);
    }

    private function today(): string
    {
        return Format::today()->toDateString();
    }

    /** Подписанный договор и оплаты по сделке проекта. */
    private function sale(Project $p, array $payments, ?string $contract = null): array
    {
        $contract ??= (string) array_sum($payments);
        Contract::create(['deal_id' => $p->deal_id, 'client_id' => $p->client_id, 'contract_date' => $this->today(), 'amount' => $contract, 'amount_uzs' => $contract,
            'status' => 'SIGNED', 'signed_at' => now(), 'created_by_id' => $this->manager->id]);
        $out = [];
        foreach ($payments as $amount) {
            $out[] = Payment::create(['deal_id' => $p->deal_id, 'client_id' => $p->client_id, 'project_id' => $p->id, 'amount' => $amount, 'amount_uzs' => $amount,
                'type' => 'PARTIAL', 'method' => 'BANK', 'status' => 'PAID', 'paid_at' => now(), 'created_by_id' => $this->manager->id]);
        }

        return $out;
    }

    private function commission(Payment $pay, string $amount, string $role = 'MANAGER', ?string $userId = null): void
    {
        Commission::create(['user_id' => $userId ?? ($role === 'MANAGER' ? $this->manager->id : $this->rop->id), 'payment_id' => $pay->id, 'deal_id' => $pay->deal_id,
            'rule_id' => CommissionRule::first()->id, 'role' => $role, 'period' => Period::currentMonth(), 'base_amount_uzs' => $pay->amount_uzs,
            'rate' => '10', 'amount_uzs' => $amount, 'status' => 'ACCRUED', 'calc_snapshot' => []]);
    }

    public function test_project_finance_example_from_spec(): void
    {
        $p = $this->project(['price' => '9000000', 'price_uzs' => '9000000']);
        [$pay] = $this->sale($p, ['9000000']);
        $this->commission($pay, '900000');
        $this->commission($pay, '900000', 'ROP');
        $ceo = $this->actingAsUser($this->ceo);
        foreach ([['DESIGN', '700000'], ['VIDEO', '500000'], ['DEVELOPMENT', '2 500 000'], ['EXECUTOR', '500000'], ['ADS', '300000'], ['OTHER', '200000']] as [$cat, $amount]) {
            $ceo->post('/finance/expenses', ['scope' => 'PROJECT', 'project_id' => $p->id, 'category' => $cat, 'amount' => $amount, 'currency' => 'UZS', 'expense_date' => $this->today()])->assertSessionHasNoErrors();
        }
        $this->as($this->ceo);
        $f = FinanceService::project($p->id);
        $this->assertSame(['9000000.00', '9000000.00', '0.00', '4700000.00', '4300000.00', '47.78', '1800000.00'],
            [$f['revenueUzs'], $f['collectedUzs'], $f['receivableUzs'], $f['expensesUzs'], $f['grossProfitUzs'], $f['marginPct'], $f['commissionsUzs']]);
        $this->assertSame(['category' => 'DEVELOPMENT', 'name' => 'Разработка', 'amountUzs' => '2500000.00'], $f['byCategory'][0]);
        $ceo->get("/projects/{$p->id}?tab=finance")->assertOk()->assertSee('47.78%');

        // Финансы проекта — только CEO: РОП, менеджер и исполнитель их не видят
        $rop = $this->actingAsUser($this->rop);
        $rop->get("/projects/{$p->id}?tab=finance")->assertOk()->assertDontSee('47.78%');
        $rop->post('/finance/expenses', ['scope' => 'PROJECT', 'project_id' => $p->id, 'category' => 'OTHER', 'amount' => '1', 'currency' => 'UZS', 'expense_date' => $this->today()])->assertForbidden();
        $rop->get('/finance/expenses')->assertForbidden();
        $this->actingAsUser($this->manager)->get('/finance/revenue')->assertForbidden();
        $this->actingAsUser($this->executor)->get('/finance/profit')->assertForbidden();
    }

    public function test_expenses_ceo_only_with_audit_and_soft_delete(): void
    {
        $p = $this->project();
        $company = ['scope' => 'COMPANY', 'category' => 'SERVICES', 'amount' => '1000000', 'currency' => 'UZS', 'expense_date' => $this->today()];
        $this->actingAsUser($this->rop)->post('/finance/expenses', $company)->assertForbidden();
        $ceo = $this->actingAsUser($this->ceo);
        $ceo->post('/finance/expenses', $company)->assertSessionHasNoErrors();
        // Проектный расход без проекта и расход компании с проектом — ошибка
        $ceo->post('/finance/expenses', ['scope' => 'PROJECT'] + $company)->assertSessionHasErrors('project_id');
        $ceo->post('/finance/expenses', ['project_id' => $p->id] + $company)->assertSessionHasErrors('project_id');
        $ceo->post('/finance/expenses', ['scope' => 'PROJECT', 'project_id' => $p->id, 'category' => 'DESIGN', 'amount' => '100', 'currency' => 'USD',
            'expense_date' => $this->today(), 'payee_user_id' => $this->executor->id])->assertSessionHasNoErrors();
        $e = Expense::where('currency', 'USD')->firstOrFail();
        $this->assertSame('1265000.00', (string) $e->amount_uzs);
        $this->assertMatchesRegularExpression('/^EXP-\d{5}$/', Format::code('EXP', $e->number));
        $ceo->get('/finance/expenses')->assertOk()->assertSee(Format::code('EXP', $e->number));
        $ceo->get('/finance/expenses?edit='.$e->id)->assertOk();

        $ceo->patch("/finance/expenses/{$e->id}", ['amount' => '1 500 000', 'currency' => 'UZS', 'return_to' => '/finance/expenses'])->assertRedirect('/finance/expenses');
        $this->assertSame('1500000.00', (string) $e->fresh()->amount_uzs);
        $ceo->delete("/finance/expenses/{$e->id}")->assertSessionHasNoErrors();
        $this->assertSame(1, Expense::count());
        $this->assertSame(2, Expense::withTrashed()->count());
        $this->assertSame(['expense.create', 'expense.update', 'expense.delete'],
            AuditLog::where('entity_type', 'expense')->where('entity_id', $e->id)->orderBy('created_at')->pluck('action')->all());
    }

    public function test_dashboard_summary_company_profit_only_for_ceo(): void
    {
        $p = $this->project(['price' => '9000000', 'price_uzs' => '9000000']);
        [$p1, $p2] = $this->sale($p, ['5000000', '4000000']);
        Payment::create(['deal_id' => $p->deal_id, 'client_id' => $p->client_id, 'refund_of_id' => $p2->id, 'amount' => '1000000', 'amount_uzs' => '1000000',
            'type' => 'REFUND', 'method' => 'BANK', 'status' => 'PAID', 'paid_at' => now(), 'created_by_id' => $this->rop->id]);
        foreach ([$p1, $p2] as $pay) {
            $this->commission($pay, (string) ((int) $pay->amount_uzs / 10));
            $this->commission($pay, (string) ((int) $pay->amount_uzs / 10), 'ROP');
        }
        $this->commission($p2, '-200000', 'MANAGER', $this->otherManager->id); // сторно с возврата
        $ceo = $this->actingAsUser($this->ceo);
        $ceo->post('/finance/expenses', ['scope' => 'PROJECT', 'project_id' => $p->id, 'category' => 'DESIGN', 'amount' => '2000000', 'currency' => 'UZS', 'expense_date' => $this->today()]);
        $ceo->post('/finance/expenses', ['scope' => 'COMPANY', 'category' => 'SERVICES', 'amount' => '300000', 'currency' => 'UZS', 'expense_date' => $this->today()]);

        $this->as($this->ceo);
        $s = FinanceService::summary(Period::resolve('month'));
        $this->assertSame(
            ['9000000.00', '9000000.00', '1000000.00', '1000000.00', '2000000.00', '300000.00', '1600000.00', '6000000.00', '4100000.00', '75.00'],
            [$s['revenueUzs'], $s['collectedUzs'], $s['refundsUzs'], $s['receivablesUzs'], $s['projectExpensesUzs'], $s['companyExpensesUzs'], $s['commissionsUzs'], $s['grossProfitUzs'], $s['operatingProfitUzs'], $s['marginPct']],
        );
        $empty = FinanceService::summary(Period::resolve('custom', '2020-01-01', '2020-12-31'));
        $this->assertSame('0.00', $empty['collectedUzs']);
        $row = FinanceService::projectsProfit(null)->getCollection()->first()->fin;
        $this->assertSame(['9000000.00', '8000000.00', '2000000.00', '7000000.00', '77.78'], [$row['revenueUzs'], $row['collectedUzs'], $row['expensesUzs'], $row['grossProfitUzs'], $row['marginPct']]);

        foreach (['ru', 'uz'] as $locale) {
            $this->ceo->update(['locale' => $locale]);
            $c = $this->actingAsUser($this->ceo);
            $c->get('/finance/revenue?period=month')->assertOk()->assertSee(money('4100000.00'));
            $c->get('/finance/revenue?period=custom&from=2020-01-01&to=2020-12-31')->assertOk();
            $c->get('/finance/profit')->assertOk()->assertSee('77.78%');
            $c->get('/finance/expenses')->assertOk();
            $c->get('/finance/incomes')->assertOk();
            $c->get('/settings/finance')->assertOk();
        }
        $this->actingAsUser($this->rop)->get('/finance/revenue')->assertForbidden();
        $this->actingAsUser($this->manager)->get('/finance/incomes')->assertForbidden();
    }

    public function test_categories_and_other_incomes(): void
    {
        $ceo = $this->actingAsUser($this->ceo);
        $ceo->post('/settings/finance/categories', ['kind' => 'EXPENSE', 'name' => 'Обучение сотрудников', 'account_hint' => '9420', 'is_active' => '1'])->assertSessionHasNoErrors();
        $cat = FinanceCategory::where('name', 'Обучение сотрудников')->firstOrFail();
        $this->assertMatchesRegularExpression('/^C_[0-9A-F]{8}$/', $cat->code);
        $this->actingAsUser($this->rop)->post('/settings/finance/categories', ['kind' => 'EXPENSE', 'name' => 'x'])->assertForbidden();
        $ceo = $this->actingAsUser($this->ceo);
        $ceo->post('/finance/expenses', ['scope' => 'COMPANY', 'category' => $cat->code, 'amount' => '500000', 'currency' => 'UZS', 'expense_date' => $this->today()])->assertSessionHasNoErrors();
        $ceo->put("/settings/finance/categories/{$cat->id}", ['kind' => 'EXPENSE', 'name' => 'Курсы и обучение', 'account_hint' => '9420', 'is_active' => '1']);
        $ceo->get('/finance/expenses')->assertSee('Курсы и обучение');
        // Используемую категорию не удалить; неизвестную или доходную — не выбрать для расхода; тип не меняется
        $ceo->delete("/settings/finance/categories/{$cat->id}")->assertSessionHas('error');
        foreach (['NOPE', 'PARTNER'] as $code) {
            $ceo->post('/finance/expenses', ['scope' => 'COMPANY', 'category' => $code, 'amount' => '1', 'currency' => 'UZS', 'expense_date' => $this->today()])->assertSessionHasErrors('category');
        }
        $ceo->put("/settings/finance/categories/{$cat->id}", ['kind' => 'INCOME', 'name' => 'x'])->assertSessionHas('error');

        $ceo->post('/finance/incomes', ['category' => 'PARTNER', 'amount' => '2000000', 'currency' => 'UZS', 'income_date' => $this->today(), 'description' => 'Вознаграждение партнёра'])->assertSessionHasNoErrors();
        $inc = OtherIncome::firstOrFail();
        $this->assertSame('2000000.00', (string) $inc->amount_uzs);
        $ceo->post('/finance/incomes', ['category' => 'RENT', 'amount' => '1', 'currency' => 'UZS', 'income_date' => $this->today()])->assertSessionHasErrors('category');
        $ceo->get('/finance/incomes')->assertOk()->assertSee('Партнёрское вознаграждение');
        $this->as($this->ceo);
        $s = FinanceService::summary(Period::resolve('month'));
        $this->assertSame('2000000.00', $s['otherIncomeUzs']);
        $this->assertSame('1500000.00', $s['operatingProfitUzs']);
        $this->actingAsUser($this->rop)->get('/finance/incomes')->assertForbidden();
        $this->actingAsUser($this->ceo)->delete("/finance/incomes/{$inc->id}")->assertSessionHasNoErrors();
        $this->as($this->ceo);
        $this->assertSame('0.00', FinanceService::summary(Period::resolve('month'))['otherIncomeUzs']);
        $this->assertTrue(AuditLog::where('action', 'other_income.delete')->exists());
        // Неиспользуемую категорию можно удалить
        $this->actingAsUser($this->ceo)->post('/settings/finance/categories', ['kind' => 'INCOME', 'name' => 'Гранты', 'is_active' => '1']);
        $grant = FinanceCategory::where('name', 'Гранты')->firstOrFail();
        $this->delete("/settings/finance/categories/{$grant->id}")->assertSessionHasNoErrors();
        $this->assertNull($grant->fresh());
    }

    public function test_cost_lines_from_tariff_personal_rate_and_accrual(): void
    {
        $p = $this->project();
        $tariff = Tariff::where('name', 'Эконом')->firstOrFail();
        $proposal = Proposal::create(['deal_id' => $p->deal_id, 'client_id' => $p->client_id, 'manager_id' => $this->manager->id, 'title' => 'SMM', 'status' => 'ACCEPTED', 'accepted_at' => now()]);
        DB::table('proposal_items')->insert(['id' => (string) Str::uuid(), 'proposal_id' => $proposal->id, 'tariff_id' => $tariff->id,
            'description' => 'SMM', 'quantity' => 1, 'unit_price' => 650, 'total' => 650, 'sort' => 0]);
        // План строится подписчиком события project.created
        (new ProjectsServiceProvider(app()))->boot();
        Outbox::publish('project.created', ['projectId' => $p->id, 'dealId' => $p->deal_id]);
        Outbox::drain(5);
        $this->assertSame(4, ProjectCostLine::where('project_id', $p->id)->count());
        $this->assertSame(0, CostLineService::generate($p->id)); // повторно — ничего

        $videographer = $this->user('EXECUTOR', attrs: ['full_name' => 'Video Pro'], employee: ['specialty' => 'VIDEOGRAPHER']);
        $reel = WorkItem::where('code', 'REEL')->first();
        $this->actingAsUser($this->ceo)->put("/team/employees/{$videographer->id}/rates", ['rates' => [['work_item_id' => $reel->id, 'rate' => '120000', 'currency' => 'UZS']]])->assertSessionHasNoErrors();
        $this->assertTrue(AuditLog::where('action', 'employee.rates')->exists());
        $this->actingAsUser($this->rop)->put("/team/employees/{$videographer->id}/rates", ['rates' => []])->assertForbidden();
        $this->actingAsUser($this->rop)->post("/projects/{$p->id}/members", ['user_id' => $videographer->id, 'role' => 'VIDEOGRAPHER'])->assertSessionHasNoErrors();
        $line = ProjectCostLine::where('project_id', $p->id)->where('work_item_id', $reel->id)->firstOrFail();
        $this->assertSame($videographer->id, $line->assignee_id);
        $this->assertSame('120000.00', (string) $line->rate);

        $this->as($this->ceo);
        $this->assertSame('960000.00', CostLineService::list($p->id)->firstWhere('id', $line->id)->amount);
        $ceo = $this->actingAsUser($this->ceo);
        $ceo->get("/projects/{$p->id}?tab=finance")->assertOk()->assertSee(t('costLines.personalRate'));
        // Без исполнителя — не начислить
        $cover = ProjectCostLine::where('project_id', $p->id)->whereNull('assignee_id')->first();
        $ceo->post("/finance/cost-lines/{$cover->id}/accrue")->assertSessionHas('error');
        $ceo->patch("/finance/cost-lines/{$cover->id}", ['assignee_id' => $this->otherManager->id])->assertSessionHasErrors('assignee_id');
        $ceo->post("/finance/cost-lines/{$line->id}/accrue")->assertSessionHasNoErrors();
        $expense = Expense::where('category', 'EXECUTOR')->firstOrFail();
        $this->assertSame([$videographer->id, '960000.00'], [$expense->payee_user_id, (string) $expense->amount_uzs]);
        $this->assertSame('ACCRUED', $line->fresh()->status);
        $ceo->post("/finance/cost-lines/{$line->id}/accrue")->assertSessionHas('error');
        $ceo->post("/finance/cost-lines/{$cover->id}/cancel")->assertSessionHasNoErrors();
        $this->assertSame('CANCELLED', $cover->fresh()->status);
        $this->actingAsUser($this->rop)->post("/finance/cost-lines/{$line->id}/cancel")->assertForbidden();
    }

    public function test_overhead_is_split_between_active_projects(): void
    {
        $a = $this->project(['name' => 'A']);
        $b = $this->project(['name' => 'B']);
        $this->actingAsUser($this->ceo)->post('/finance/expenses', ['scope' => 'COMPANY', 'category' => 'RENT', 'amount' => '1000000', 'currency' => 'UZS', 'expense_date' => $this->today()]);
        $this->assertSame('500000.00', (string) OverheadService::forProjects([$a->fresh(), $b->fresh()])[$a->id]->toScale(2));
        $this->put('/settings/finance/overhead', ['mode' => 'fixed', 'divisor' => 4])->assertSessionHasNoErrors();
        $this->assertSame(4, OverheadService::settings()['overheadDivisor']);
        $this->assertSame('250000.00', (string) OverheadService::forProjects([$a->fresh()])[$a->id]->toScale(2));
        $this->actingAsUser($this->rop)->put('/settings/finance/overhead', ['mode' => 'auto'])->assertForbidden();
    }
}
