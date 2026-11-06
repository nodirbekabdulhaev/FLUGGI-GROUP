<?php

namespace Tests\Feature\Accounting;

use App\Models\AccAccount;
use App\Models\AccCategory;
use App\Models\AccPerson;
use App\Models\AccTransaction;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounting\Accounting;
use App\Services\Accounting\AnnualReport;
use App\Services\Accounting\OverviewService;
use App\Services\Accounting\TaxesService;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/** Свои статьи прихода и расхода: права, аудит, операции со статьёй, налоги и годовой отчёт — по базовой категории. */
class CategoriesTest extends TestCase
{
    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();
        Accounting::forgetRates();
        $this->accountant = $this->user('ACCOUNTANT');
    }

    private function account(array $attrs = []): AccAccount
    {
        return AccAccount::create($attrs + ['name' => 'Bank '.uniqid(), 'kind' => 'BANK', 'currency' => 'UZS', 'opening_balance' => '0', 'crm_methods' => []]);
    }

    private function article(string $dir, string $name, string $base, bool $active = true): AccCategory
    {
        return AccCategory::create(['direction' => $dir, 'name' => $name, 'base' => $base, 'is_active' => $active, 'sort' => 0]);
    }

    /** Пользователь только с просмотром бухгалтерии (роль HR с добавленным accounting.read). */
    private function readOnlyUser(): User
    {
        $role = Role::where('code', 'HR_ADMIN')->value('id');
        DB::table('role_permissions')->insertOrIgnore(['role_id' => $role, 'permission_id' => DB::table('permissions')->where('code', 'accounting.read')->value('id'), 'scope' => 'ALL']);

        return $this->user('HR_ADMIN');
    }

    /** @return list<list<mixed>> */
    private function xlsxRows(string $body): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($tmp, $body);
        $rows = IOFactory::load($tmp)->getSheet(0)->toArray();
        @unlink($tmp);

        return $rows;
    }

    public function test_articles_management_permissions_and_audit(): void
    {
        $existing = $this->article('OUT', 'Ofis ijarasi', 'RENT');

        // Только просмотр: страница открывается, изменения — 403
        $reader = $this->readOnlyUser();
        $this->actingAsUser($reader);
        $this->get('/accounting/categories')->assertOk()->assertSee('Ofis ijarasi')->assertDontSee(t('accounting.categories.add'));
        $this->post('/accounting/categories', ['direction' => 'OUT', 'name' => 'Reklama', 'base' => 'OTHER'])->assertForbidden();
        $this->put("/accounting/categories/{$existing->id}", ['direction' => 'OUT', 'name' => 'X', 'base' => 'RENT'])->assertForbidden();
        $this->post("/accounting/categories/{$existing->id}/active", ['active' => 0])->assertForbidden();
        $this->delete("/accounting/categories/{$existing->id}")->assertForbidden();
        // Без права бухгалтерии — нет и просмотра
        $this->actingAsUser($this->user('MANAGER'))->get('/accounting/categories')->assertForbidden();
        $this->assertSame(1, AccCategory::count());

        $this->actingAsUser($this->accountant);
        $this->post('/accounting/categories', ['direction' => 'OUT', 'name' => '  Ombor   ijarasi ', 'base' => 'RENT', 'sort' => 5])->assertSessionHasNoErrors();
        $art = AccCategory::where('name', 'Ombor ijarasi')->firstOrFail();
        $this->assertSame(['OUT', 'RENT', true, 5], [$art->direction, $art->base, $art->is_active, $art->sort]);
        $this->assertTrue(AuditLog::where('action', 'accounting.category.create')->where('entity_type', 'acc_category')->where('entity_id', $art->id)->exists());

        // Повтор названия в том же направлении, чужая база, системная категория — отказ
        $this->post('/accounting/categories', ['direction' => 'OUT', 'name' => 'ombor ijarasi', 'base' => 'OTHER'])->assertSessionHasErrors('name');
        $this->post('/accounting/categories', ['direction' => 'IN', 'name' => 'Ijara', 'base' => 'RENT'])->assertSessionHasErrors('base');
        $this->post('/accounting/categories', ['direction' => 'OUT', 'name' => 'Perevod', 'base' => 'TRANSFER_OUT'])->assertSessionHasErrors('base');
        $this->post('/accounting/categories', ['direction' => 'IN', 'name' => 'Vozvrat', 'base' => 'TRANSFER_IN'])->assertSessionHasErrors('base');
        // То же название у прихода — можно
        $this->post('/accounting/categories', ['direction' => 'IN', 'name' => 'Ombor ijarasi', 'base' => 'OTHER_INCOME'])->assertSessionHasNoErrors();

        // Переименование и смена базы
        $this->put("/accounting/categories/{$art->id}", ['direction' => 'OUT', 'name' => 'Ombor ijarasi (Chilonzor)', 'base' => 'UTILITIES', 'sort' => 1])->assertSessionHasNoErrors();
        $this->assertSame(['Ombor ijarasi (Chilonzor)', 'UTILITIES'], [$art->refresh()->name, $art->base]);
        $upd = AuditLog::where('action', 'accounting.category.update')->where('entity_id', $art->id)->firstOrFail();
        $this->assertEquals(['old' => 'RENT', 'new' => 'UTILITIES'], $upd->changes['base']);

        // Отключение и включение
        $this->post("/accounting/categories/{$art->id}/active", ['active' => 0])->assertSessionHasNoErrors();
        $this->assertFalse($art->refresh()->is_active);
        $this->post("/accounting/categories/{$art->id}/active", ['active' => 1]);
        $this->assertTrue($art->refresh()->is_active);
        $this->assertSame(1, AuditLog::where('action', 'accounting.category.deactivate')->where('entity_id', $art->id)->count());
        $this->assertSame(1, AuditLog::where('action', 'accounting.category.activate')->where('entity_id', $art->id)->count());

        // Статья с операциями (даже удалённой) не удаляется и не меняет направление
        $bank = $this->account();
        $op = AccTransaction::create(['account_id' => $bank->id, 'direction' => 'OUT', 'category' => 'UTILITIES', 'category_id' => $art->id, 'amount' => '10', 'date' => '2026-09-01']);
        $op->delete();
        $this->delete("/accounting/categories/{$art->id}")->assertSessionHas('error');
        $this->assertNotNull(AccCategory::find($art->id));
        $this->put("/accounting/categories/{$art->id}", ['direction' => 'IN', 'name' => 'Ijara daromadi', 'base' => 'OTHER_INCOME'])->assertSessionHasErrors('direction');
        // Неиспользованная — удаляется
        $this->delete("/accounting/categories/{$existing->id}")->assertSessionHasNoErrors();
        $this->assertNull(AccCategory::find($existing->id));
        $this->assertTrue(AuditLog::where('action', 'accounting.category.delete')->where('entity_id', $existing->id)->exists());

        $this->get('/accounting/categories')->assertOk()->assertSee('Ombor ijarasi (Chilonzor)')->assertSee(t('accounting.categories.add'));
        $this->get("/accounting/categories?edit={$art->id}")->assertOk()->assertSee(t('accounting.categories.directionLocked'));
    }

    public function test_operation_with_article_stores_base_and_is_listed_and_exported(): void
    {
        $bank = $this->account(['name' => 'Kapitalbank']);
        $rent = $this->article('OUT', 'Ombor ijarasi', 'RENT');
        $income = $this->article('IN', 'Depozit foizlari', 'OTHER_INCOME');
        $off = $this->article('OUT', 'Eski statya', 'OTHER', active: false);
        $salary = $this->article('OUT', 'Bonus', 'SALARY');

        $this->actingAsUser($this->accountant);
        $this->get('/accounting/operations')->assertOk()->assertSee('Ombor ijarasi')->assertSee('Depozit foizlari')->assertDontSee('Eski statya</option>', false);

        $post = fn (array $d) => $this->post('/accounting/operations', $d + ['account_id' => $bank->id, 'date' => '2026-09-10', 'amount' => '1 500 000']);
        $post(['direction' => 'OUT', 'category' => $rent->id, 'counterparty' => 'Ombor MChJ'])->assertSessionHasNoErrors();
        $op = AccTransaction::where('category_id', $rent->id)->firstOrFail();
        $this->assertSame(['OUT', 'RENT', '1500000.00'], [$op->direction, $op->category, (string) $op->amount]);
        $audit = AuditLog::where('action', 'accounting.operation.create')->where('entity_id', $op->id)->firstOrFail();
        $this->assertSame($rent->id, $audit->changes['category_id']['new']);
        $this->assertSame('Ombor ijarasi', $audit->changes['article']['new']);

        // Статья другого направления, отключённая, несуществующая — отказ; правила базы действуют и для статьи
        $post(['direction' => 'IN', 'category' => $rent->id])->assertSessionHasErrors('category');
        $post(['direction' => 'OUT', 'category' => $off->id])->assertSessionHasErrors('category');
        $post(['direction' => 'OUT', 'category' => '00000000-0000-4000-8000-000000000000'])->assertSessionHasErrors('category');
        $post(['direction' => 'OUT', 'category' => 'NOT_A_CODE'])->assertSessionHasErrors('category');
        $post(['direction' => 'OUT', 'category' => $salary->id, 'period' => '2026-09'])->assertSessionHasErrors('person_id');
        $person = AccPerson::create(['full_name' => 'Aliyev Aziz', 'monthly_salary' => '1000000', 'hired_at' => '2026-01-01']);
        $post(['direction' => 'OUT', 'category' => $salary->id, 'period' => '2026-09', 'person_id' => $person->id])->assertSessionHasNoErrors();
        $this->assertSame('SALARY', AccTransaction::where('category_id', $salary->id)->value('category'));
        $this->assertSame(0, AccTransaction::where('category_id', $off->id)->count());

        $post(['direction' => 'IN', 'category' => $income->id])->assertSessionHasNoErrors();
        $post(['direction' => 'OUT', 'category' => 'RENT', 'amount' => '200000'])->assertSessionHasNoErrors();

        // Список: название статьи с базой; фильтр по статье и по базе (включает статьи этой базы)
        $this->get('/accounting/operations')->assertOk()->assertSee('Ombor ijarasi')->assertSee(t('accounting.category.RENT'));
        $byArticle = $this->get('/accounting/operations?category='.$rent->id)->assertOk();
        $this->assertSame(1, $byArticle->viewData('items')->total());
        $this->assertSame(2, $this->get('/accounting/operations?category=RENT')->viewData('items')->total());
        $this->get('/accounting/operations?category=BAD')->assertSessionHasErrors('category');

        // Отключённая статья остаётся у уже внесённой операции и в фильтре
        $this->post("/accounting/categories/{$rent->id}/active", ['active' => 0]);
        $this->get('/accounting/operations')->assertOk()->assertSee('Ombor ijarasi');
        $this->assertSame($rent->id, $op->refresh()->category_id);
        $post(['direction' => 'OUT', 'category' => $rent->id])->assertSessionHasErrors('category');

        // Выгрузка: колонка «Статья»
        $res = $this->get('/accounting/operations/export?period=2026-09')->assertOk();
        $rows = $this->xlsxRows($res->getContent());
        $col = array_search(t('accounting.fields.article'), $rows[0], true);
        $this->assertNotFalse($col);
        $byArt = collect(array_slice($rows, 1))->mapWithKeys(fn ($r) => [$r[$col] ?? '' => $r[$col - 1]]);
        $this->assertSame(t('accounting.category.RENT'), $byArt['Ombor ijarasi']);
        $this->assertSame(t('accounting.category.OTHER_INCOME'), $byArt['Depozit foizlari']);
        $this->assertTrue($byArt->has(''));
    }

    public function test_article_counts_as_its_base_in_taxes_overview_and_annual_report(): void
    {
        $this->as($this->accountant);
        $bank = $this->account(['opening_date' => '2025-12-31']);
        $deposit = $this->article('IN', 'Depozit foizlari', 'OTHER_INCOME');
        $warehouse = $this->article('OUT', 'Ombor ijarasi', 'RENT');
        $make = fn (array $d) => AccTransaction::create($d + ['account_id' => $bank->id]);
        $make(['direction' => 'IN', 'category' => 'CLIENT_PAYMENT', 'amount' => '40000000', 'date' => '2026-01-10']);
        $make(['direction' => 'IN', 'category' => 'OTHER_INCOME', 'amount' => '1000000', 'date' => '2026-01-15']);
        $make(['direction' => 'IN', 'category' => 'OTHER_INCOME', 'category_id' => $deposit->id, 'amount' => '500000', 'date' => '2026-01-20']);
        $make(['direction' => 'OUT', 'category' => 'RENT', 'category_id' => $warehouse->id, 'amount' => '2000000', 'date' => '2026-01-25']);
        $make(['direction' => 'OUT', 'category' => 'RENT', 'amount' => '300000', 'date' => '2026-01-26']);

        // IT Park: база = 40 + 1 + 0,5 млн (статья с базой «Прочий доход» входит)
        $this->assertSame('41500000.00', TaxesService::forPeriod('2026-01')['bankIncome']);
        $lines = collect(AnnualReport::results(2026))->mapWithKeys(fn ($r) => [$r[0] => (string) $r[2]->toScale(2)]);
        $this->assertSame('40000000.00', $lines['010']);
        $this->assertSame('1500000.00', $lines['090']);           // прочие доходы, включая статью
        $this->assertSame('2300000.00', $lines['060']);           // аренда со статьёй и без

        // Обзор: итог по базе и разбивка по статьям внутри неё
        $m = OverviewService::month('2026-01');
        $this->assertSame('1500000.00', $m['income']['OTHER_INCOME']);
        $this->assertSame([['id' => $deposit->id, 'name' => 'Depozit foizlari', 'sum' => '500000.00']], $m['incomeArticles']['OTHER_INCOME']);
        $this->assertSame('2300000.00', $m['expenses']['RENT']);
        $this->assertSame([['id' => $warehouse->id, 'name' => 'Ombor ijarasi', 'sum' => '2000000.00']], $m['expensesArticles']['RENT']);
        $this->actingAsUser($this->accountant)->get('/accounting?period=2026-01')->assertOk()->assertSee('Depozit foizlari')->assertSee('Ombor ijarasi');

        // Налоговая выгрузка: в листе поступлений есть статья
        $res = $this->get('/accounting/taxes/export?period=2026-01')->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($tmp, $res->getContent());
        $income = IOFactory::load($tmp)->getSheet(2)->toArray();
        @unlink($tmp);
        $this->assertContains(t('accounting.fields.article'), $income[0]);
        $this->assertContains('Depozit foizlari', array_merge(...$income));
    }
}
