<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Debt;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Student;
use App\Models\StudentEvent;
use App\Models\TelegramAccount;
use App\Services\EnrollmentService;
use App\Services\PaymentService;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FinanceTest extends TestCase
{
    use RefreshDatabase;

    private function enrolled(float $price = 600000): array
    {
        $this->makeOrg();
        $this->actingAs($this->makeUser('accountant'));
        $group = $this->makeGroup(['price' => $price]);
        $student = $this->makeStudent();
        $gs = app(EnrollmentService::class)->enroll($student, $group);

        return [$student, $group, $gs];
    }

    private function pay(Student $s, float $amount, array $extra = []): Payment
    {
        return app(PaymentService::class)->record($extra + ['student_id' => $s->id, 'amount' => $amount, 'method_id' => PaymentMethod::where('code', 'cash')->value('id')]);
    }

    public function test_spec_example_partial_payment_leaves_debt_of_200k(): void
    {
        [$student, $group, $gs] = $this->enrolled(600000);
        $this->assertEquals(600000, Debt::first()->balance);          // charged on enrollment

        $this->pay($student, 400000);

        $debt = Debt::first();
        $this->assertEquals([600000, 400000, 200000], [$debt->charged, $debt->paid, $debt->balance]);
        $this->assertEquals(200000, $student->financeSummary()['balance']);
        $this->assertEquals(200000, Student::withFinance()->find($student->id)->balance);

        $this->pay($student, 200000);
        $this->assertEquals(0, Debt::first()->balance);
        $this->assertNotNull(Debt::first()->last_payment_at);
    }

    public function test_overpayment_becomes_credit_and_discount_reduces_charge(): void
    {
        [$student, $group, $gs] = $this->enrolled(500000);
        $gs->update(['discount' => 100000]);
        app(PaymentService::class)->refreshDebt($gs);
        $this->assertEquals(400000, Debt::first()->balance);

        $this->pay($student, 450000);
        $this->assertEquals(-50000, Debt::first()->balance);
        $this->assertEquals(0, Debt::where('balance', '>', 0)->count());      // not a debtor
    }

    public function test_refund_and_correction_adjust_revenue_and_debt(): void
    {
        [$student, $group, $gs] = $this->enrolled(600000);
        $this->pay($student, 600000);
        $this->pay($student, 100000, ['type' => 'refund']);
        $this->assertEquals(100000, Debt::first()->balance);
        $this->assertEquals(500000, app(ReportService::class)->revenue(now()->startOfMonth(), now()->endOfMonth()));

        $this->pay($student, -30000, ['type' => 'correction']);
        $this->assertEquals(130000, Debt::first()->balance);

        // cannot refund more than was paid
        $this->expectException(ValidationException::class);
        $this->pay($student, 9999999, ['type' => 'refund']);
    }

    public function test_invalid_amounts_are_rejected_and_nothing_is_written(): void
    {
        [$student] = $this->enrolled();
        foreach ([0, -5] as $bad) {
            try {
                $this->pay($student, $bad);
                $this->fail('must reject');
            } catch (ValidationException) {
            }
        }
        $this->assertSame(0, Payment::count());
    }

    public function test_payment_to_wrong_group_is_atomic(): void
    {
        [$student] = $this->enrolled();
        $otherGroup = $this->makeGroup();
        try {
            $this->pay($student, 1000, ['group_id' => $otherGroup->id]);     // student is not in that group
            $this->fail('must reject');
        } catch (ValidationException) {
        }
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, StudentEvent::where('type', 'payment')->count());
    }

    public function test_payment_without_group_is_allocated_to_oldest_open_debt(): void
    {
        [$student, $g1, $gs1] = $this->enrolled(300000);
        $g2 = $this->makeGroup(['price' => 200000]);
        $gs2 = app(EnrollmentService::class)->enroll($student, $g2);

        $p1 = $this->pay($student, 300000);       // fully closes the first membership
        $p2 = $this->pay($student, 50000);        // goes to the second one
        $this->assertSame($gs1->id, $p1->group_student_id);
        $this->assertSame($gs2->id, $p2->group_student_id);
        $this->assertEquals([0, 150000], Debt::orderBy('group_student_id')->pluck('balance')->map(fn ($b) => (float) $b)->all());
        $this->assertEquals(150000, $student->financeSummary()['balance']);
    }

    public function test_editing_and_deleting_a_payment_recalculates_debt_and_is_audited(): void
    {
        [$student] = $this->enrolled(600000);
        $p = $this->pay($student, 400000);
        AuditLog::query()->delete();

        app(PaymentService::class)->update($p, ['amount' => 500000]);
        $this->assertEquals(100000, Debt::first()->balance);

        $log = AuditLog::where('event', 'updated')->where('auditable_type', 'Payment')->first();
        $this->assertEquals(400000, $log->old_values['amount']);          // spec §48: amount 400000 → 500000
        $this->assertEquals(500000, $log->new_values['amount']);
        $this->assertNotNull($log->user_id);

        app(PaymentService::class)->delete($p->fresh());
        $this->assertEquals(600000, Debt::first()->balance);
        $this->assertSoftDeleted($p);
    }

    public function test_payment_notifies_parent_and_debt_reminders_are_sent_once_per_day(): void
    {
        [$student, $group, $gs] = $this->enrolled(600000);
        TelegramAccount::create(['linkable_type' => 'student', 'linkable_id' => $student->id, 'telegram_user_id' => 5, 'chat_id' => 5, 'linked_at' => now()]);

        $this->pay($student, 100000);
        $this->assertSame(1, Notification::where('type', 'payment_received')->count());

        $gs->update(['next_payment_date' => today()->subDay()]);
        app(PaymentService::class)->refreshDebt($gs->fresh());
        $svc = app(PaymentService::class);
        $this->assertSame(1, $svc->remindDebts());
        $this->assertSame(0, $svc->remindDebts());                      // dedupe
        $body = Notification::where('type', 'payment_due')->value('body');
        $this->assertStringContainsString('500 000', $body);
    }

    public function test_payment_form_flow_over_http_and_permission_for_corrections(): void
    {
        [$student] = $this->enrolled(600000);
        $accountant = $this->makeUser('accountant');
        $cashier = $this->makeUser('admin');                     // admin: payments.create but no payments.correct
        $method = PaymentMethod::where('code', 'click')->value('id');

        $this->actingAs($cashier)->post('/finance/payments', ['student_id' => $student->id, 'amount' => 250000, 'method_id' => $method])->assertRedirect("/students/{$student->id}");
        $this->assertEquals(350000, Debt::first()->balance);
        $this->assertSame($cashier->id, Payment::first()->cashier_id);

        $this->actingAs($cashier)->post('/finance/payments', ['student_id' => $student->id, 'amount' => 1000, 'method_id' => $method, 'type' => 'refund'])->assertForbidden();
        $this->actingAs($cashier)->get('/finance/payments/'.Payment::first()->id.'/edit')->assertForbidden();
        $this->actingAs($accountant)->put('/finance/payments/'.Payment::first()->id, ['amount' => 300000, 'method_id' => $method, 'paid_at' => now()->format('Y-m-d\TH:i')])->assertRedirect();
        $this->assertEquals(300000, Debt::first()->balance);

        $this->actingAs($cashier)->post('/finance/payments', ['student_id' => $student->id, 'amount' => 'abc', 'method_id' => $method])->assertSessionHasErrors('amount');
    }

    public function test_profit_is_revenue_minus_expenses(): void
    {
        [$student] = $this->enrolled(600000);
        $this->pay($student, 600000);
        \App\Models\Expense::create(['spent_at' => today(), 'amount' => 150000, 'category_id' => \App\Models\ExpenseCategory::first()->id]);
        $f = app(ReportService::class)->finance(now()->startOfMonth(), now()->endOfMonth());
        $this->assertEquals([600000, 150000, 450000, 1, 600000], [$f['revenue'], $f['expenses'], $f['profit'], $f['payments_count'], $f['avg_check']]);
    }
}
