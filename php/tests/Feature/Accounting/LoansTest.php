<?php

namespace Tests\Feature\Accounting;

use App\Models\AccLoan;
use App\Models\AccLoanPayment;
use App\Models\Todo;
use App\Models\User;
use App\Services\Accounting\LoansService;
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
}
