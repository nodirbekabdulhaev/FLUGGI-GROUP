<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\GroupStudent;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\SalaryAccrual;
use App\Models\SalaryPayment;
use App\Models\SalaryRule;
use App\Models\Teacher;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalaryService
{
    /** Keep salary_rules in sync with the pay_type/rate saved on a teacher or employee. */
    public function syncRule(Model $payable): void
    {
        SalaryRule::updateOrCreate(
            ['payable_type' => $payable->getMorphClass(), 'payable_id' => $payable->getKey()],
            ['organization_id' => $payable->organization_id, 'type' => $payable->pay_type ?? 'fixed', 'rate' => $payable->rate ?? 0, 'is_active' => $payable->status === 'active']
        );
    }

    /** Calculate (or recalculate) accruals for a YYYY-MM period. Returns the accrual rows. */
    public function accrue(string $period): \Illuminate\Support\Collection
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw ValidationException::withMessages(['period' => 'Период в формате ГГГГ-ММ.']);
        }
        $from = Carbon::createFromFormat('Y-m-d', $period.'-01')->startOfDay();
        $to = $from->copy()->endOfMonth();

        return DB::transaction(function () use ($period, $from, $to) {
            $rows = collect();

            foreach (Teacher::where('status', '!=', 'inactive')->get() as $t) {
                $rows->push($this->accrueTeacher($t, $period, $from, $to));
            }
            foreach (Employee::where('status', 'active')->get() as $e) {
                $rows->push($this->store($e, $period, 'fixed', (float) $e->rate, 1, (float) $e->rate, 'Оклад'));
            }

            return $rows->filter();
        });
    }

    protected function accrueTeacher(Teacher $t, string $period, Carbon $from, Carbon $to): ?SalaryAccrual
    {
        $rule = SalaryRule::where('payable_type', $t->getMorphClass())->where('payable_id', $t->id)->where('is_active', true)->first();
        $type = $rule?->type ?? $t->pay_type;
        $rate = (float) ($rule?->rate ?? $t->rate);

        switch ($type) {
            case 'per_lesson':
                $units = Lesson::where('teacher_id', $t->id)->where('status', 'held')
                    ->whereBetween('lesson_date', [$from->toDateString(), $to->toDateString()])->count();
                $amount = $units * $rate;
                $note = "{$units} занятий × ".number_format($rate, 0, '.', ' ');
                break;
            case 'per_student':
                $groupIds = $t->groups()->pluck('id');
                $units = GroupStudent::whereIn('group_id', $groupIds)->where('status', 'active')
                    ->where('joined_at', '<=', $to->toDateString())->distinct()->count('student_id');
                $amount = $units * $rate;
                $note = "{$units} учеников × ".number_format($rate, 0, '.', ' ');
                break;
            case 'percent':
                $groupIds = $t->groups()->withTrashed()->pluck('id');
                $units = (float) Payment::whereIn('group_id', $groupIds)->whereBetween('paid_at', [$from, $to])->sum(DB::raw(Payment::SIGNED_SQL));
                $amount = round($units * $rate / 100, 2);
                $note = "{$rate}% от ".number_format($units, 0, '.', ' ');
                break;
            default:
                $units = 1;
                $amount = $rate;
                $note = 'Оклад';
        }

        return $this->store($t, $period, $type, $rate, $units, $amount, $note);
    }

    protected function store(Model $payable, string $period, string $type, float $rate, float $units, float $amount, ?string $note): ?SalaryAccrual
    {
        return SalaryAccrual::updateOrCreate(
            ['payable_type' => $payable->getMorphClass(), 'payable_id' => $payable->getKey(), 'period' => $period],
            ['organization_id' => $payable->organization_id, 'branch_id' => $payable->branch_id, 'rule_type' => $type,
                'rate' => $rate, 'units' => $units, 'amount' => round($amount, 2), 'note' => $note]
        );
    }

    /** accrued, paid, debt for a payee. */
    public function balance(Model $payable): array
    {
        $accrued = (float) SalaryAccrual::where('payable_type', $payable->getMorphClass())->where('payable_id', $payable->getKey())->sum('amount');
        $paid = (float) SalaryPayment::where('payable_type', $payable->getMorphClass())->where('payable_id', $payable->getKey())->sum('amount');

        return ['accrued' => $accrued, 'paid' => $paid, 'debt' => $accrued - $paid];
    }

    /** Pay salary: salary_payment + matching expense (so profit is correct), in one transaction. */
    public function pay(Model $payable, float $amount, ?int $methodId = null, ?string $date = null, ?string $comment = null): SalaryPayment
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Сумма должна быть больше нуля.']);
        }

        return DB::transaction(function () use ($payable, $amount, $methodId, $date, $comment) {
            $category = ExpenseCategory::firstOrCreate(['name' => 'Зарплата']);
            $name = $payable->full_name;
            $expense = Expense::create([
                'branch_id' => $payable->branch_id, 'category_id' => $category->id, 'method_id' => $methodId,
                'employee_id' => $payable instanceof Employee ? $payable->id : null,
                'spent_at' => $date ?? today()->toDateString(), 'amount' => $amount,
                'description' => 'Зарплата: '.$name, 'created_by' => auth()->id(),
            ]);

            return SalaryPayment::create([
                'organization_id' => $payable->organization_id, 'branch_id' => $payable->branch_id,
                'payable_type' => $payable->getMorphClass(), 'payable_id' => $payable->getKey(),
                'amount' => $amount, 'paid_at' => $date ?? today()->toDateString(), 'method_id' => $methodId,
                'expense_id' => $expense->id, 'comment' => $comment, 'created_by' => auth()->id(),
            ]);
        });
    }
}
