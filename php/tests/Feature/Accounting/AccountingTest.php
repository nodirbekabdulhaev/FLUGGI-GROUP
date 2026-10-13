<?php

namespace Tests\Feature\Accounting;

use App\Models\AccAccount;
use App\Models\AccPayroll;
use App\Models\AccPerson;
use App\Models\AccTransaction;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\ExchangeRate;
use App\Models\Payment;
use App\Models\User;
use App\Services\Accounting\Accounting;
use App\Services\Accounting\AccountsService;
use App\Services\Accounting\CrmImportService;
use App\Services\Accounting\TaxesService;
use App\Services\Accounting\TaxPayrollService;
use App\Support\Outbox;
use Tests\TestCase;

/** Бухгалтерия: доступ, счета и остатки, переводы, удаление с причиной, оплаты из CRM, зарплата и налоги. */
class AccountingTest extends TestCase
{
    private User $accountant;

    private User $ceo;

    protected function setUp(): void
    {
        parent::setUp();
        Accounting::forgetRates();
        $this->accountant = $this->user('ACCOUNTANT', attrs: ['full_name' => 'Buxgalter']);
        $this->ceo = $this->user('CEO', attrs: ['full_name' => 'Ceo Boss']);
    }

    private function account(array $attrs = []): AccAccount
    {
        return AccAccount::create($attrs + ['name' => 'Bank '.uniqid(), 'kind' => 'BANK', 'currency' => 'UZS', 'opening_balance' => '0', 'crm_methods' => []]);
    }

    private function op(AccAccount $acc, string $dir, string $cat, string $amount, string $date, array $extra = []): AccTransaction
    {
        return AccTransaction::create($extra + ['account_id' => $acc->id, 'direction' => $dir, 'category' => $cat, 'amount' => $amount, 'date' => $date]);
    }

    private function payment(array $attrs = []): Payment
    {
        $manager = $this->user('MANAGER');
        $client = Client::create(['name' => 'Aziz Market', 'owner_id' => $manager->id]);
        $deal = Deal::create(['title' => 'SMM', 'client_id' => $client->id, 'owner_id' => $manager->id, 'amount' => '9000000', 'amount_uzs' => '9000000',
            'stage_id' => DealStage::where('code', 'PAID')->value('id'), 'status' => 'WON', 'won_at' => now(), 'created_by_id' => $manager->id]);

        return Payment::create($attrs + ['deal_id' => $deal->id, 'client_id' => $client->id, 'amount' => '9000000', 'amount_uzs' => '9000000',
            'type' => 'FULL', 'method' => 'BANK', 'status' => 'PAID', 'paid_at' => '2026-09-30 20:00:00', 'created_by_id' => $manager->id])->refresh();
    }

    private function publishPaid(Payment $p): void
    {
        Outbox::publish($p->type === 'REFUND' ? 'payment.refunded' : 'payment.paid', [
            'paymentId' => $p->id, 'dealId' => $p->deal_id, 'amountUzs' => (string) $p->amount_uzs,
            'managerId' => Deal::find($p->deal_id)->owner_id, 'teamId' => null, 'projectId' => null, 'projectCreated' => false,
        ]);
        Outbox::processBatch();
    }

    public function test_access_by_role(): void
    {
        $pages = ['/accounting', '/accounting/operations', '/accounting/accounts', '/accounting/people', '/accounting/payroll', '/accounting/taxes', '/accounting/settings'];
        foreach ([$this->accountant, $this->ceo] as $u) {
            $this->actingAsUser($u);
            foreach ($pages as $url) {
                $this->get($url)->assertOk();
            }
        }
        // Бухгалтер: главная и профиль открываются без ошибок
        $this->actingAsUser($this->accountant);
        foreach (['/', '/dashboard', '/profile'] as $url) {
            $this->assertLessThan(500, $this->get($url)->getStatusCode(), $url);
        }
        foreach (['ROP', 'MANAGER', 'EXECUTOR', 'PROJECT_MANAGER', 'HR_ADMIN'] as $role) {
            $this->actingAsUser($this->user($role));
            foreach ([...$pages, '/accounting/operations/export', '/accounting/taxes/export'] as $url) {
                $this->get($url)->assertForbidden();
            }
            $this->post('/accounting/accounts', ['name' => 'X', 'kind' => 'CASH', 'currency' => 'UZS'])->assertForbidden();
            $this->post('/accounting/payroll/calculate', ['period' => '2026-09'])->assertForbidden();
        }
        $this->assertSame(0, AccAccount::count());
    }

    public function test_accounts_balances_transfer_and_soft_delete(): void
    {
        $this->actingAsUser($this->accountant);
        $this->post('/accounting/accounts', ['name' => 'Kapitalbank', 'kind' => 'BANK', 'bank_name' => 'Kapitalbank', 'account_number' => '2020 8000 1234 5678 9012',
            'currency' => 'UZS', 'opening_balance' => '1 000 000', 'opening_date' => '2026-09-01', 'crm_methods' => ['BANK', 'TRANSFER']])->assertSessionHasNoErrors();
        $this->post('/accounting/accounts', ['name' => 'Kassa', 'kind' => 'CASH', 'currency' => 'UZS', 'opening_balance' => '0'])->assertSessionHasNoErrors();
        $bank = AccAccount::where('name', 'Kapitalbank')->first();
        $cash = AccAccount::where('name', 'Kassa')->first();
        $this->assertSame(['BANK', 'TRANSFER'], $bank->crm_methods);
        $this->assertSame('20208000123456789012', $bank->account_number);
        $this->assertTrue(AuditLog::where('action', 'accounting.account.create')->where('entity_id', $bank->id)->exists());

        $post = fn (array $d) => $this->post('/accounting/operations', $d + ['account_id' => $bank->id, 'date' => '2026-09-10']);
        $post(['direction' => 'IN', 'category' => 'OTHER_INCOME', 'amount' => '500 000'])->assertSessionHasNoErrors();
        $post(['direction' => 'OUT', 'category' => 'RENT', 'amount' => '200000,50'])->assertSessionHasNoErrors();
        // Обязательные поля по категориям, запрет авто-категорий, дата до начального остатка
        $post(['direction' => 'OUT', 'category' => 'SALARY', 'amount' => '1', 'period' => '2026-09'])->assertSessionHasErrors('person_id');
        $post(['direction' => 'OUT', 'category' => 'TAX_NDFL', 'amount' => '1'])->assertSessionHasErrors('period');
        $post(['direction' => 'OUT', 'category' => 'SUPPLIER', 'amount' => '1'])->assertSessionHasErrors('counterparty');
        $post(['direction' => 'IN', 'category' => 'TRANSFER_IN', 'amount' => '1'])->assertSessionHasErrors('category');
        $post(['direction' => 'IN', 'category' => 'SALARY', 'amount' => '1'])->assertSessionHasErrors('category');
        $post(['direction' => 'IN', 'category' => 'OTHER_INCOME', 'amount' => '0'])->assertSessionHasErrors('amount');
        $post(['direction' => 'IN', 'category' => 'OTHER_INCOME', 'amount' => '5', 'date' => '2026-08-31'])->assertSessionHasErrors('date');
        $this->assertSame('1299999.50', AccountsService::balance($bank->id));

        // Перевод: снятие наличных — две связанные строки
        $this->post('/accounting/operations/transfer', ['from_id' => $bank->id, 'to_id' => $bank->id, 'amount' => '1', 'date' => '2026-09-11'])->assertSessionHasErrors('to_id');
        $this->post('/accounting/operations/transfer', ['from_id' => $bank->id, 'to_id' => $cash->id, 'amount' => '300000', 'date' => '2026-09-11', 'description' => 'Naqd'])->assertSessionHasNoErrors();
        $pair = AccTransaction::whereNotNull('transfer_group')->orderBy('direction')->get();
        $this->assertCount(2, $pair);
        $this->assertSame($pair[0]->transfer_group, $pair[1]->transfer_group);
        $this->assertSame([['IN', 'TRANSFER_IN', $cash->id], ['OUT', 'TRANSFER_OUT', $bank->id]], $pair->map(fn ($r) => [$r->direction, $r->category, $r->account_id])->all());
        $this->assertSame('999999.50', AccountsService::balance($bank->id));
        $this->assertSame('300000.00', AccountsService::balance($cash->id));

        // Удаление — только с причиной; перевод удаляется целиком
        $this->delete("/accounting/operations/{$pair[0]->id}", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->delete("/accounting/operations/{$pair[0]->id}", ['reason' => 'Ошибка ввода'])->assertSessionHasNoErrors();
        $this->assertSame(0, AccTransaction::whereNotNull('transfer_group')->count());
        $deleted = AccTransaction::onlyTrashed()->whereNotNull('transfer_group')->get();
        $this->assertCount(2, $deleted);
        $this->assertSame([$this->accountant->id, 'Ошибка ввода'], [$deleted[0]->deleted_by_id, $deleted[0]->delete_reason]);
        $this->assertSame(2, AuditLog::where('action', 'accounting.operation.delete')->count());
        $this->assertSame('1299999.50', AccountsService::balance($bank->id));
        $this->assertSame('0.00', AccountsService::balance($cash->id));

        // Счёт с операциями не удаляется, а отключается; отключённый не принимает операций
        $this->delete("/accounting/accounts/{$cash->id}")->assertSessionHas('error');
        $this->post("/accounting/accounts/{$cash->id}/active", ['active' => 0]);
        $this->assertFalse($cash->refresh()->is_active);
        $this->post('/accounting/operations', ['account_id' => $cash->id, 'direction' => 'IN', 'category' => 'OTHER_INCOME', 'amount' => '1', 'date' => '2026-09-12'])->assertSessionHasErrors('account_id');
        $empty = $this->account(['name' => 'Empty']);
        $this->delete("/accounting/accounts/{$empty->id}")->assertSessionHasNoErrors();
        $this->assertNull(AccAccount::find($empty->id));
        // Валюту счёта с операциями менять нельзя
        $this->put("/accounting/accounts/{$bank->id}", ['name' => 'Kapitalbank', 'kind' => 'BANK', 'currency' => 'USD'])->assertSessionHasErrors('currency');

        $this->get('/accounting/operations?account='.$bank->id.'&direction=OUT&q=')->assertOk()->assertSee(t('accounting.category.RENT'));
        $this->get('/accounting/operations/export?period=2026-09')->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_crm_payments_are_imported_idempotently_with_refunds(): void
    {
        $bank = $this->account(['name' => 'Bank', 'crm_methods' => ['BANK']]);
        $usd = $this->account(['name' => 'USD', 'currency' => 'USD', 'crm_methods' => ['TRANSFER']]);
        ExchangeRate::create(['currency' => 'USD', 'date' => '2026-09-01', 'rate_to_uzs' => '12650']);

        $p = $this->payment();
        $this->publishPaid($p);
        $row = AccTransaction::where('payment_id', $p->id)->first();
        // 2026-09-30 20:00 UTC = 2026-10-01 01:00 по Ташкенту
        $this->assertSame([$bank->id, 'IN', 'CLIENT_PAYMENT', '9000000.00', '2026-10-01', 'Aziz Market', null],
            [$row->account_id, $row->direction, $row->category, (string) $row->amount, $row->date->toDateString(), $row->counterparty, $row->created_by_id]);
        $this->assertTrue(AuditLog::where('action', 'accounting.crm_import')->where('entity_id', $row->id)->exists());
        // Повтор события — без дубля
        $this->publishPaid($p);
        $this->assertSame(1, AccTransaction::where('payment_id', $p->id)->count());

        // Способа нет ни у одного счёта — пропуск
        $cashPay = $this->payment(['method' => 'CASH']);
        $this->publishPaid($cashPay);
        $this->assertFalse(AccTransaction::where('payment_id', $cashPay->id)->exists());

        // Долларовый счёт: оплата в сумах пересчитывается по курсу, в долларах — как есть
        $t1 = $this->payment(['method' => 'TRANSFER', 'amount' => '12650000', 'amount_uzs' => '12650000']);
        $t2 = $this->payment(['method' => 'TRANSFER', 'currency' => 'USD', 'exchange_rate' => '12650', 'amount' => '500', 'amount_uzs' => '6325000']);
        $this->assertSame('imported', CrmImportService::import($t1->id));
        $this->assertSame('imported', CrmImportService::import($t2->id));
        $this->assertSame(['1000.00', '500.00'], [(string) AccTransaction::where('payment_id', $t1->id)->value('amount'), (string) AccTransaction::where('payment_id', $t2->id)->value('amount')]);
        $this->assertSame('1500.00', AccountsService::balance($usd->id));

        // Возврат — расход «Оплата клиента» на счёт по способу возврата
        $refund = $this->payment(['type' => 'REFUND', 'refund_of_id' => $p->id, 'amount' => '1000000', 'amount_uzs' => '1000000', 'paid_at' => '2026-10-05 07:00:00']);
        $this->publishPaid($refund);
        $out = AccTransaction::where('payment_id', $refund->id)->first();
        $this->assertSame(['OUT', 'CLIENT_PAYMENT', '1000000.00'], [$out->direction, $out->category, (string) $out->amount]);
        $this->assertSame('8000000.00', AccountsService::balance($bank->id));

        // Строку из CRM вручную не удалить
        $this->actingAsUser($this->accountant)->delete("/accounting/operations/{$row->id}", ['reason' => 'test'])->assertSessionHas('error');
        $this->assertNull($row->refresh()->deleted_at);

        // Загрузка истории за период: новые оплаты загружаются, старые не дублируются; вне периода — нет
        $old = $this->payment(['paid_at' => '2026-08-15 10:00:00', 'amount' => '2000000', 'amount_uzs' => '2000000']);
        $late = $this->payment(['paid_at' => '2026-11-15 10:00:00']);
        $pending = $this->payment(['status' => 'PENDING', 'paid_at' => null]);
        $this->post('/accounting/import-crm', ['from' => '2026-08-01', 'to' => '2026-10-31'])->assertSessionHasNoErrors()
            ->assertSessionHas('ok', t('accounting.import.done', ['imported' => 1, 'exists' => 4, 'skipped' => 1]));
        $this->assertTrue(AccTransaction::where('payment_id', $old->id)->exists());
        $this->assertFalse(AccTransaction::where('payment_id', $late->id)->exists());
        $this->assertFalse(AccTransaction::where('payment_id', $pending->id)->exists());
        $this->post('/accounting/import-crm', ['from' => '2026-08-01', 'to' => '2026-10-31']);
        $this->assertSame(5, AccTransaction::whereNotNull('payment_id')->count());

        // Начальный остаток: оплаты раньше его даты не загружаются
        $bank->update(['opening_date' => '2026-09-01']);
        AccTransaction::where('payment_id', $old->id)->forceDelete();
        $this->assertSame('beforeOpening', CrmImportService::import($old->id));
        $this->post('/accounting/import-crm', ['from' => '2026-10-31', 'to' => '2026-10-01'])->assertSessionHasErrors('to');
        $this->actingAsUser($this->user('ROP'))->post('/accounting/import-crm', ['from' => '2026-08-01', 'to' => '2026-10-31'])->assertForbidden();
    }

    public function test_payroll_calculation_proration_rates_and_approval(): void
    {
        // По умолчанию ИНПС удерживается сверх НДФЛ (7,5 % + 0,1 %); здесь сначала — вариант «ИНПС в составе НДФЛ»
        $this->assertFalse(Accounting::settings()['inpsWithinNdfl']);
        $this->as($this->accountant);
        Accounting::saveSettings(['ndflRate' => '7.5', 'inpsRate' => '0.1', 'inpsWithinNdfl' => true, 'itparkRate' => '1']);
        $this->actingAsUser($this->accountant);
        $this->post('/accounting/people', ['full_name' => 'Aliyev Aziz', 'pinfl' => '3120 1950 1234 56', 'position' => 'Direktor', 'monthly_salary' => '10 000 000', 'hired_at' => '2026-01-01', 'is_active' => 1])->assertSessionHasNoErrors();
        $this->post('/accounting/people', ['full_name' => 'Karimova Dilnoza', 'monthly_salary' => '6000000', 'hired_at' => '2026-09-16', 'is_active' => 1])->assertSessionHasNoErrors();
        $this->post('/accounting/people', ['full_name' => 'Fired', 'monthly_salary' => '5000000', 'hired_at' => '2026-01-01', 'fired_at' => '2026-08-31', 'is_active' => 1])->assertSessionHasNoErrors();
        $this->post('/accounting/people', ['full_name' => 'Inactive', 'monthly_salary' => '5000000', 'is_active' => 0])->assertSessionHasNoErrors();
        $this->post('/accounting/people', ['full_name' => 'Bad', 'monthly_salary' => '1', 'pinfl' => '123'])->assertSessionHasErrors('pinfl');
        $this->post('/accounting/people', ['full_name' => 'Dup', 'monthly_salary' => '1', 'pinfl' => '31201950123456'])->assertSessionHasErrors('pinfl');
        $this->post('/accounting/people', ['full_name' => 'Dates', 'monthly_salary' => '1', 'hired_at' => '2026-05-01', 'fired_at' => '2026-04-01'])->assertSessionHasErrors('fired_at');
        $a = AccPerson::where('full_name', 'Aliyev Aziz')->first();
        $b = AccPerson::where('full_name', 'Karimova Dilnoza')->first();
        $this->assertSame('31201950123456', $a->pinfl);

        $this->post('/accounting/payroll/calculate', ['period' => '2026-09'])->assertSessionHasNoErrors();
        $rows = AccPayroll::where('period', '2026-09')->get()->keyBy('person_id');
        $this->assertCount(2, $rows);
        $vals = fn ($r) => [(string) $r->gross, (string) $r->ndfl, (string) $r->inps, (string) $r->net, $r->status];
        $this->assertSame(['10000000.00', '750000.00', '10000.00', '9250000.00', 'DRAFT'], $vals($rows[$a->id]));
        // Принята 16.09: 15 из 30 дней
        $this->assertSame(['3000000.00', '225000.00', '3000.00', '2775000.00', 'DRAFT'], $vals($rows[$b->id]));

        // Правка начисленного в черновике пересчитывает налоги
        $this->patch("/accounting/payroll/{$rows[$b->id]->id}", ['gross' => '4000000'])->assertSessionHasNoErrors();
        $this->assertSame(['4000000.00', '300000.00', '4000.00', '3700000.00', 'DRAFT'], $vals($rows[$b->id]->refresh()));

        // Утверждение: строка больше не меняется и не пересчитывается
        $this->post('/accounting/payroll/approve', ['period' => '2026-09', 'ids' => [$rows[$a->id]->id]])->assertSessionHasNoErrors();
        $this->assertSame('APPROVED', $rows[$a->id]->refresh()->status);
        $this->patch("/accounting/payroll/{$rows[$a->id]->id}", ['gross' => '1'])->assertSessionHas('error');
        $a->update(['monthly_salary' => '20000000']);
        $this->post('/accounting/payroll/calculate', ['period' => '2026-09']);
        $this->assertSame('10000000.00', (string) $rows[$a->id]->refresh()->gross);
        $this->assertSame('3000000.00', (string) $rows[$b->id]->refresh()->gross);

        // Выплачено — расходы SALARY с человеком и периодом
        $acc = $this->account(['name' => 'Bank']);
        $this->post('/accounting/operations', ['account_id' => $acc->id, 'direction' => 'OUT', 'category' => 'SALARY', 'person_id' => $a->id, 'period' => '2026-09', 'amount' => '5000000', 'date' => '2026-10-05'])->assertSessionHasNoErrors();
        $this->op($acc, 'OUT', 'SALARY', '777', '2026-10-05', ['person_id' => $a->id, 'period' => '2026-08']);
        $sheet = TaxPayrollService::sheet('2026-09');
        $ra = $sheet['rows']->firstWhere('person_id', $a->id);
        $this->assertSame(['5000000.00', '4250000.00'], [$ra->paid, $ra->remaining]);
        $this->assertSame('12025000.00', $sheet['totals']['net']);

        // ИНПС сверх НДФЛ: на руки = gross − НДФЛ − ИНПС
        $this->put('/accounting/settings', ['ndflRate' => '7.5', 'inpsRate' => '0.1', 'itparkRate' => '1'])->assertSessionHasNoErrors();
        $this->assertFalse(Accounting::settings()['inpsWithinNdfl']);
        $this->post('/accounting/payroll/calculate', ['period' => '2026-09']);
        $this->assertSame('2772000.00', (string) $rows[$b->id]->refresh()->net);

        // Уволенный в середине месяца: 10 из 31 дня
        $this->assertSame([10, 31], TaxPayrollService::employedDays('2025-01-01', '2026-10-10', '2026-10'));
        $this->assertNull(TaxPayrollService::employedDays('2026-11-01', null, '2026-10'));

        // Человека с начислениями нельзя удалить
        $this->delete("/accounting/people/{$a->id}")->assertSessionHas('error');
        $this->assertNotNull(AccPerson::find($a->id));
        $this->assertTrue(AuditLog::where('action', 'accounting.payroll.approve')->exists());
    }

    public function test_taxes_due_paid_and_reports(): void
    {
        $bank = $this->account(['name' => 'Bank']);
        $usdBank = $this->account(['name' => 'USD bank', 'currency' => 'USD']);
        $cash = $this->account(['name' => 'Kassa', 'kind' => 'CASH']);
        ExchangeRate::create(['currency' => 'USD', 'date' => '2026-09-01', 'rate_to_uzs' => '12500']);
        $a = AccPerson::create(['full_name' => 'Aliyev Aziz', 'monthly_salary' => '10000000', 'hired_at' => '2026-01-01']);
        AccPerson::create(['full_name' => 'Karimova Dilnoza', 'monthly_salary' => '6000000', 'hired_at' => '2026-09-16']);
        $this->as($this->accountant);
        Accounting::saveSettings(['ndflRate' => '7.5', 'inpsRate' => '0.1', 'inpsWithinNdfl' => true, 'itparkRate' => '1']);
        TaxPayrollService::calculate('2026-09');

        // База IT Park: поступления на банковские счета (оплаты клиентов и прочие доходы), без касс и переводов
        $this->op($bank, 'IN', 'CLIENT_PAYMENT', '9000000', '2026-09-05');
        $this->op($bank, 'IN', 'OTHER_INCOME', '1000000', '2026-09-30');
        $this->op($usdBank, 'IN', 'OTHER_INCOME', '100', '2026-09-20'); // 1 250 000 UZS
        $this->op($cash, 'IN', 'OTHER_INCOME', '5000000', '2026-09-10');
        $this->op($bank, 'IN', 'TRANSFER_IN', '7000000', '2026-09-11', ['transfer_group' => '00000000-0000-0000-0000-000000000001']);
        $this->op($bank, 'IN', 'OTHER_INCOME', '3000000', '2026-10-01');
        $this->op($bank, 'IN', 'OTHER_INCOME', '4000000', '2026-09-15')->delete();
        // Оплаты налогов за период
        $this->op($bank, 'OUT', 'TAX_NDFL', '500000', '2026-10-10', ['period' => '2026-09']);
        $this->op($bank, 'OUT', 'TAX_NDFL', '1', '2026-10-10', ['period' => '2026-08']);
        $this->op($bank, 'OUT', 'TAX_ITPARK', '112500', '2026-10-10', ['period' => '2026-09']);

        $t = TaxesService::forPeriod('2026-09');
        // НДФЛ: (750 000 + 225 000) − (10 000 + 3 000) = 962 000; ИНПС 13 000 → вместе 7,5 % от 13 000 000
        $this->assertSame(['962000.00', '500000.00', '462000.00'], [$t['taxes']['TAX_NDFL']['due'], $t['taxes']['TAX_NDFL']['paid'], $t['taxes']['TAX_NDFL']['balance']]);
        $this->assertSame(['13000.00', '0.00', '13000.00'], [$t['taxes']['TAX_INPS']['due'], $t['taxes']['TAX_INPS']['paid'], $t['taxes']['TAX_INPS']['balance']]);
        $this->assertSame('11250000.00', $t['bankIncome']);
        $this->assertSame(['112500.00', '112500.00', '0.00'], [$t['taxes']['TAX_ITPARK']['due'], $t['taxes']['TAX_ITPARK']['paid'], $t['taxes']['TAX_ITPARK']['balance']]);
        $this->assertSame(2, $t['drafts']);
        $this->assertFalse($t['missingRate']);

        // ИНПС сверх НДФЛ — НДФЛ к уплате целиком
        Accounting::saveSettings(['ndflRate' => '7.5', 'inpsRate' => '0.1', 'inpsWithinNdfl' => false, 'itparkRate' => '2']);
        $t2 = TaxesService::forPeriod('2026-09');
        $this->assertSame(['975000.00', '225000.00'], [$t2['taxes']['TAX_NDFL']['due'], $t2['taxes']['TAX_ITPARK']['due']]);
        $this->assertTrue(AuditLog::where('action', 'accounting.settings')->exists());

        // Страницы на всех языках, выгрузка отчёта, «Оплатить» с заполненной формой
        foreach (['uz', 'ru', 'en'] as $locale) {
            $this->accountant->update(['locale' => $locale]);
            $this->actingAsUser($this->accountant);
            foreach (['/accounting?period=2026-09', '/accounting/operations', '/accounting/operations?period=2026-09&category=TAX_NDFL', '/accounting/accounts', '/accounting/accounts?edit='.$bank->id,
                '/accounting/people', '/accounting/people?edit='.$a->id, '/accounting/payroll?period=2026-09', '/accounting/taxes?period=2026-09', '/accounting/settings',
                '/accounting/operations?new=OUT&p_category=TAX_INPS&p_period=2026-09&p_amount=13000&p_account='.$bank->id, '/accounting/operations?new=TRANSFER'] as $url) {
                $html = $this->get($url)->assertOk()->getContent();
                $this->assertDoesNotMatchRegularExpression('/(?<![\/\w])(accounting|sales|common)\.[a-z][A-Za-z_.]+/', strip_tags($html), "$locale $url: ключ без перевода");
            }
            $this->get('/accounting/taxes?period=2026-09')->assertSee(t('accounting.category.TAX_ITPARK'))->assertSee('p_category=TAX_INPS', false);
        }
        $row = AccPayroll::first();
        $this->get('/accounting/payroll?period=2026-09&edit='.$row->id)->assertOk();
        $res = $this->get('/accounting/taxes/export?period=2026-09')->assertOk();
        $this->assertStringStartsWith('PK', $res->getContent());
        $this->assertTrue(AuditLog::where('action', 'export.run')->where('entity_type', 'acc_tax_report')->exists());
        $this->get('/accounting/operations?new=OUT&p_category=TAX_INPS&p_period=2026-09&p_amount=13000')->assertSee('value="13000"', false);

        // Нет курса USD — суммы не теряются молча
        ExchangeRate::query()->delete();
        Accounting::forgetRates();
        $this->assertTrue(TaxesService::forPeriod('2026-09')['missingRate']);
    }
}
