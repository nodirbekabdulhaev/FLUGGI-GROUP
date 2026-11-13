<?php

namespace Tests\Feature\Accounting;

use App\Models\AccAccount;
use App\Models\AccLoan;
use App\Models\AccLoanPayment;
use App\Models\AccTransaction;
use App\Models\Todo;
use App\Models\User;
use App\Services\Accounting\LoansService;
use App\Services\Accounting\Statements;
use App\Services\Automation\Scheduler;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Кредиты: договор, график вручную и из файла, отметка «оплачено», напоминание за неделю. */
class LoansTest extends TestCase
{
    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accountant = $this->user('ACCOUNTANT', attrs: ['full_name' => 'Buxgalter']);
    }

    private function loanData(array $extra = []): array
    {
        return $extra + ['_form' => 'loan', 'name' => 'Test kredit', 'lender' => 'Test Bank', 'currency' => 'UZS', 'principal' => '140 000 000', 'rate' => '24', 'remind_days' => 7, 'owner_id' => $this->accountant->id];
    }

    public function test_loan_with_manual_schedule_and_paid_marks(): void
    {
        $this->actingAsUser($this->accountant);
        $this->post('/accounting/loans', $this->loanData(['rows' => [
            ['due_date' => '2026-12-10', 'principal' => '5 000 000', 'interest' => '2 800 000', 'total' => ''],
            ['due_date' => '2027-01-10', 'principal' => '5 000 000', 'interest' => '2 700 000', 'total' => ''],
            ['due_date' => '', 'principal' => '', 'interest' => '', 'total' => ''],
        ]]))->assertSessionHasNoErrors();
        $loan = AccLoan::firstOrFail();
        $this->assertSame('140000000.00', (string) $loan->principal);
        $this->assertSame(['7800000.00', '7700000.00'], $loan->payments->pluck('total')->map(fn ($v) => (string) $v)->all());
        $this->get('/accounting/loans')->assertOk()->assertSee('Test kredit');
        $this->get('/accounting/loans/'.$loan->id)->assertOk()->assertSee('10.12.2026');

        $first = $loan->payments->first();
        $this->post('/accounting/loan-payments/'.$first->id.'/paid', ['paid_at' => '2026-12-09'])->assertSessionHasNoErrors();
        $stats = LoansService::stats($loan->fresh('payments'));
        $this->assertSame('7800000.00', (string) $stats['paidTotal']->toScale(2));
        $this->assertSame('135000000.00', (string) $stats['balance']->toScale(2));
        $this->assertSame('7700000.00', (string) $stats['leftTotal']->toScale(2));
        $this->delete('/accounting/loan-payments/'.$first->id.'/paid');
        $this->assertNull($first->fresh()->paid_at);

        $this->post('/accounting/loans', $this->loanData(['rows' => [['due_date' => '2026-13-40', 'principal' => '1', 'interest' => '0']]]))->assertSessionHasErrors('rows');
        $this->actingAsUser($this->user('MANAGER'))->post('/accounting/loans', $this->loanData())->assertForbidden();
    }

    public function test_ready_schedule_is_imported_from_file(): void
    {
        $this->actingAsUser($this->accountant);
        $this->post('/accounting/loans', $this->loanData())->assertSessionHasNoErrors();
        $loan = AccLoan::firstOrFail();
        $csv = "Дата платежа;Основной долг;Проценты;Сумма платежа\n10.12.2026;5 000 000;2 800 000;7 800 000\n10.01.2027;5 000 000;2 700 000;7 700 000\nИтого;10 000 000;5 500 000;15 500 000\n";
        $this->post('/accounting/loans/'.$loan->id.'/import', ['file' => UploadedFile::fake()->createWithContent('plan.csv', $csv), 'replace' => '1'])->assertSessionHasNoErrors();
        $rows = $loan->payments()->get();
        $this->assertCount(2, $rows);
        $this->assertSame(['2026-12-10', '2027-01-10'], $rows->map(fn ($r) => $r->due_date->toDateString())->all());
        $this->assertSame(['2800000.00', '7800000.00'], [(string) $rows[0]->interest, (string) $rows[0]->total]);
        // Повторная загрузка с «заменить» не плодит дубли, оплаченное сохраняется
        $this->post('/accounting/loan-payments/'.$rows[0]->id.'/paid');
        $this->post('/accounting/loans/'.$loan->id.'/import', ['file' => UploadedFile::fake()->createWithContent('plan.csv', $csv), 'replace' => '1']);
        $this->assertSame(3, AccLoanPayment::where('loan_id', $loan->id)->count());
        $bad = $this->post('/accounting/loans/'.$loan->id.'/import', ['file' => UploadedFile::fake()->createWithContent('plan.csv', "a;b\nx;y\n")]);
        $bad->assertSessionHasErrors('file');
    }

    public function test_reminder_week_before_creates_one_todo_and_paid_closes_it(): void
    {
        $this->actingAsUser($this->accountant);
        $soon = now()->addDays(5)->format('Y-m-d');
        $far = now()->addDays(40)->format('Y-m-d');
        $this->post('/accounting/loans', $this->loanData(['rows' => [
            ['due_date' => $soon, 'principal' => '1000000', 'interest' => '500000'],
            ['due_date' => $far, 'principal' => '1000000', 'interest' => '480000'],
        ]]));
        $this->assertSame(1, LoansService::remind()['created']);
        $this->assertSame(0, LoansService::remind()['created']);
        $todo = Todo::where('owner_id', $this->accountant->id)->firstOrFail();
        $this->assertSame(['PAYMENT', 'OPEN'], [$todo->kind, $todo->status]);
        $this->assertStringContainsString('Test kredit', $todo->title);
        $payment = AccLoanPayment::whereNotNull('todo_id')->firstOrFail();
        $this->post('/accounting/loan-payments/'.$payment->id.'/paid');
        $this->assertSame('DONE', $todo->fresh()->status);
        $this->assertContains('loan-reminders', Scheduler::names());
    }

    public function test_payments_and_receipt_are_found_in_operations_and_reach_the_statements(): void
    {
        $this->actingAsUser($this->accountant);
        $bank = AccAccount::create(['name' => 'Bank', 'kind' => 'BANK', 'currency' => 'UZS', 'opening_balance' => '0', 'crm_methods' => []]);
        $rows = [];
        foreach (range(0, 35) as $i) {
            $rows[] = ['due_date' => now()->setDate(2025, 2, 10)->addMonthsNoOverflow($i)->format('Y-m-d'), 'principal' => '1000000', 'interest' => '100000'];
        }
        $this->post('/accounting/loans', $this->loanData(['principal' => '36000000', 'start_date' => '2025-01-15', 'rows' => $rows]))->assertSessionHasNoErrors();
        $loan = AccLoan::firstOrFail();
        $in = AccTransaction::create(['account_id' => $bank->id, 'direction' => 'IN', 'category' => 'OTHER_INCOME', 'amount' => '36000000', 'date' => '2025-01-15']);
        $out = AccTransaction::create(['account_id' => $bank->id, 'direction' => 'OUT', 'category' => 'OTHER', 'amount' => '1100000', 'date' => '2025-02-11']);
        $other = AccTransaction::create(['account_id' => $bank->id, 'direction' => 'OUT', 'category' => 'OTHER', 'amount' => '1100000.50', 'date' => '2025-02-11']);
        $this->assertSame(['paid' => 1, 'received' => 1], LoansService::autoMatch());
        $this->assertSame('LOAN_RECEIPT', $in->fresh()->category);
        $this->assertSame('LOAN_PAYMENT', $out->fresh()->category);
        $first = $loan->payments()->first();
        $this->assertSame([$out->id, '2025-02-11'], [$first->transaction_id, $first->paid_at->toDateString()]);
        $this->assertSame(['paid' => 0, 'received' => 0], LoansService::autoMatch());
        // Баланс на конец 2025: выплачен 1 млн; неоплаченные платежи 2025 (10 шт.) и 2026 (12 шт.) — краткосрочный долг, остальное долгосрочный
        $b = LoansService::balancesAt('2025-12-31');
        $this->assertSame(['22000000.00', '13000000.00'], [(string) $b['short']->toScale(2), (string) $b['long']->toScale(2)]);
        $this->assertSame('1100000.00', (string) LoansService::interestFor(2025)->toScale(2));
        $this->assertSame('1100000.00', (string) Statements::results(2025)['180']['v']->toScale(2));
        $close = Statements::closing(2025);
        $this->assertSame(['13000000.00', '22000000.00'], [(string) $close['570']['v']->toScale(2), (string) $close['730']['v']->toScale(2)]);
        // Поступление кредита и погашение долга не попадают в выручку и прочие доходы; найденный платёж — не расход
        $this->assertSame('0.00', (string) Statements::results(2025)['090']['v']->toScale(2));
        $this->assertSame('1100000.50', (string) Statements::results(2025)['060']['v']->toScale(2));   // только несовпавший расход
    }
}
