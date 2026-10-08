<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Expense;
use App\Models\Lesson;
use App\Models\PaymentMethod;
use App\Models\SalaryAccrual;
use App\Models\SalaryPayment;
use App\Services\EnrollmentService;
use App\Services\PaymentService;
use App\Services\SalaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_four_pay_schemes_from_the_spec(): void
    {
        $this->makeOrg();
        $this->actingAs($this->makeUser('accountant'));
        $period = now()->format('Y-m');

        $fixed = $this->makeTeacher(['full_name' => 'Fixed', 'pay_type' => 'fixed', 'rate' => 5000000]);
        $perLesson = $this->makeTeacher(['full_name' => 'PerLesson', 'pay_type' => 'per_lesson', 'rate' => 100000]);
        $perStudent = $this->makeTeacher(['full_name' => 'PerStudent', 'pay_type' => 'per_student', 'rate' => 50000]);
        $percent = $this->makeTeacher(['full_name' => 'Percent', 'pay_type' => 'percent', 'rate' => 20]);

        // per_lesson: 40 held lessons this month
        $g = $this->makeGroup(['teacher_id' => $perLesson->id]);
        foreach (range(1, 40) as $i) {
            Lesson::create(['group_id' => $g->id, 'teacher_id' => $perLesson->id, 'branch_id' => $g->branch_id, 'lesson_date' => today(), 'start_time' => '10:00:00', 'end_time' => '11:00:00', 'status' => 'held']);
        }
        Lesson::create(['group_id' => $g->id, 'teacher_id' => $perLesson->id, 'branch_id' => $g->branch_id, 'lesson_date' => today(), 'start_time' => '12:00:00', 'end_time' => '13:00:00', 'status' => 'cancelled']);   // not paid

        // per_student: 3 students in 2 groups → distinct 3
        $g1 = $this->makeGroup(['teacher_id' => $perStudent->id, 'max_students' => 10]);
        $g2 = $this->makeGroup(['teacher_id' => $perStudent->id, 'max_students' => 10]);
        $students = collect(range(1, 3))->map(fn () => $this->makeStudent());
        foreach ($students as $s) { app(EnrollmentService::class)->enroll($s, $g1); }
        app(EnrollmentService::class)->enroll($students[0], $g2);

        // percent: payments of 1,000,000 into the teacher's group
        $gp = $this->makeGroup(['teacher_id' => $percent->id, 'price' => 1000000]);
        $ps = $this->makeStudent();
        app(EnrollmentService::class)->enroll($ps, $gp);
        app(PaymentService::class)->record(['student_id' => $ps->id, 'group_id' => $gp->id, 'amount' => 1000000, 'method_id' => PaymentMethod::first()->id]);

        $employee = Employee::create(['full_name' => 'Cleaner', 'branch_id' => $this->branch()->id, 'rate' => 3000000, 'status' => 'active', 'pay_type' => 'fixed']);

        $svc = app(SalaryService::class);
        $svc->accrue($period);
        $amount = fn ($m) => (float) SalaryAccrual::where('payable_type', $m->getMorphClass())->where('payable_id', $m->id)->where('period', $period)->value('amount');

        $this->assertEquals(5000000, $amount($fixed));
        $this->assertEquals(4000000, $amount($perLesson));      // 100 000 × 40
        $this->assertEquals(150000, $amount($perStudent));      // 50 000 × 3
        $this->assertEquals(200000, $amount($percent));         // 20% of 1 000 000
        $this->assertEquals(3000000, $amount($employee));

        // recalculation is idempotent (one accrual per payee/period)
        $svc->accrue($period);
        $this->assertSame(5, SalaryAccrual::where('period', $period)->count());
    }

    public function test_payment_reduces_salary_debt_and_creates_an_expense(): void
    {
        $this->makeOrg();
        $this->actingAs($this->makeUser('accountant'));
        $t = $this->makeTeacher(['pay_type' => 'fixed', 'rate' => 5000000]);
        $svc = app(SalaryService::class);
        $svc->accrue(now()->format('Y-m'));
        $this->assertEquals(['accrued' => 5000000.0, 'paid' => 0.0, 'debt' => 5000000.0], $svc->balance($t));

        $svc->pay($t, 2000000, PaymentMethod::first()->id);

        $this->assertEquals(['accrued' => 5000000.0, 'paid' => 2000000.0, 'debt' => 3000000.0], $svc->balance($t));
        $expense = Expense::first();
        $this->assertSame('Зарплата', $expense->category->name);
        $this->assertEquals(2000000, $expense->amount);
        $this->assertSame($expense->id, SalaryPayment::first()->expense_id);
    }

    public function test_changing_a_teachers_rate_updates_the_salary_rule(): void
    {
        $this->makeOrg();
        $t = $this->makeTeacher(['pay_type' => 'fixed', 'rate' => 1000]);
        $t->update(['pay_type' => 'per_lesson', 'rate' => 70000]);
        $rule = \App\Models\SalaryRule::where('payable_id', $t->id)->first();
        $this->assertSame('per_lesson', $rule->type);
        $this->assertEquals(70000, $rule->rate);
        $this->assertSame(1, \App\Models\SalaryRule::count());
    }

    public function test_invalid_period_is_rejected(): void
    {
        $this->makeOrg();
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(SalaryService::class)->accrue('2026-13');
    }
}
