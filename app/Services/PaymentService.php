<?php

namespace App\Services;

use App\Models\Debt;
use App\Models\GroupStudent;
use App\Models\Payment;
use App\Models\Student;
use App\Models\StudentEvent;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Money flow. Every operation runs in a DB transaction:
 * payment row + debt refresh + timeline + audit (model events) + notification.
 */
class PaymentService
{
    public function __construct(protected NotificationService $notifications) {}

    /**
     * @param array{student_id:int,amount:float|int|string,method_id?:int,group_id?:int,paid_at?:string,comment?:string,type?:string} $data
     */
    public function record(array $data, ?int $cashierId = null): Payment
    {
        $type = $data['type'] ?? 'payment';
        $amount = round((float) $data['amount'], 2);

        if (! in_array($type, ['payment', 'refund', 'correction'], true)) {
            throw ValidationException::withMessages(['type' => 'Неизвестный тип операции.']);
        }
        if ($type !== 'correction' && $amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Сумма должна быть больше нуля.']);
        }
        if ($type === 'correction' && $amount == 0.0) {
            throw ValidationException::withMessages(['amount' => 'Сумма коррекции не может быть нулевой.']);
        }

        return DB::transaction(function () use ($data, $type, $amount, $cashierId) {
            // findOrFail is tenant-scoped: a payment to a missing / foreign student is impossible
            $student = Student::withTrashed()->lockForUpdate()->findOrFail($data['student_id']);
            $enrollment = $this->resolveEnrollment($student, $data['group_id'] ?? null, $type);

            if ($type === 'refund') {
                $paid = $enrollment
                    ? (float) Payment::where('group_student_id', $enrollment->id)->sum(DB::raw(Payment::SIGNED_SQL))
                    : $student->financeSummary()['paid'];
                if ($amount > $paid + 0.001) {
                    throw ValidationException::withMessages(['amount' => 'Возврат больше суммы оплат ('.number_format($paid, 0, '.', ' ').').']);
                }
            }

            $payment = Payment::create([
                'branch_id' => $student->branch_id,
                'student_id' => $student->id,
                'group_id' => $enrollment?->group_id,
                'course_id' => $enrollment?->group?->course_id,
                'group_student_id' => $enrollment?->id,
                'method_id' => $data['method_id'] ?? null,
                'type' => $type,
                'amount' => $amount,
                'paid_at' => isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : now(),
                'cashier_id' => $cashierId ?? auth()->id(),
                'comment' => $data['comment'] ?? null,
            ]);

            if ($enrollment) {
                $this->refreshDebt($enrollment);
            }

            $label = Payment::TYPES[$type];
            StudentEvent::log($student, 'payment', "{$label}: ".number_format($amount, 0, '.', ' ').' сум', ['payment_id' => $payment->id]);

            if ($type === 'payment') {
                $this->notifications->toStudentAudience($student, 'payment_received',
                    $this->notifications->render('payment_received', ['name' => $student->full_name, 'amount' => number_format($amount, 0, '.', ' ')]),
                    'pay:'.$payment->id);
            }

            return $payment;
        });
    }

    /** Edit an existing payment (permission payments.correct). Old and new debts are both recalculated. */
    public function update(Payment $payment, array $data): Payment
    {
        return DB::transaction(function () use ($payment, $data) {
            $payment = Payment::lockForUpdate()->findOrFail($payment->id);
            $oldEnrollment = $payment->group_student_id;

            $payment->fill(array_intersect_key($data, array_flip(['amount', 'method_id', 'comment', 'paid_at'])));
            if (isset($data['paid_at'])) {
                $payment->paid_at = Carbon::parse($data['paid_at']);
            }
            if ($payment->type !== 'correction' && $payment->amount <= 0) {
                throw ValidationException::withMessages(['amount' => 'Сумма должна быть больше нуля.']);
            }
            $payment->save();

            if ($oldEnrollment && ($gs = GroupStudent::find($oldEnrollment))) {
                $this->refreshDebt($gs);
            }
            StudentEvent::log($payment->student_id, 'payment_edited', 'Изменена оплата #'.$payment->id);

            return $payment;
        });
    }

    public function delete(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $payment->delete();   // soft delete + audit
            if ($payment->group_student_id && ($gs = GroupStudent::find($payment->group_student_id))) {
                $this->refreshDebt($gs);
            }
            StudentEvent::log($payment->student_id, 'payment_deleted', 'Удалена оплата #'.$payment->id);
        });
    }

    /** Which membership does the money belong to? Explicit group → that group; else the oldest unpaid one. */
    protected function resolveEnrollment(Student $student, ?int $groupId, string $type): ?GroupStudent
    {
        $q = GroupStudent::with('group.course')->where('student_id', $student->id);

        if ($groupId) {
            $gs = (clone $q)->where('group_id', $groupId)->latest('id')->first();
            if (! $gs) {
                throw ValidationException::withMessages(['group_id' => 'Ученик не состоит в выбранной группе.']);
            }

            return $gs;
        }

        $open = (clone $q)->whereIn('status', ['active', 'frozen'])->orderBy('joined_at')->orderBy('id')->get();
        if ($open->isEmpty()) {
            return $type === 'payment' ? (clone $q)->latest('id')->first() : null;
        }
        if ($type === 'payment') {
            foreach ($open as $gs) {
                $debt = Debt::where('group_student_id', $gs->id)->value('balance');
                if ($debt === null || $debt > 0) {
                    return $gs;
                }
            }
        }

        return $open->first();
    }

    /** Recompute the debt ledger row of one membership from charge and payments. */
    public function refreshDebt(GroupStudent $gs): Debt
    {
        $gs->loadMissing('group');
        $charged = $gs->charge();
        $paid = (float) Payment::where('group_student_id', $gs->id)->sum(DB::raw(Payment::SIGNED_SQL));
        $last = Payment::where('group_student_id', $gs->id)->where('type', 'payment')->max('paid_at');

        return Debt::updateOrCreate(['group_student_id' => $gs->id], [
            'organization_id' => $gs->organization_id,
            'branch_id' => $gs->branch_id,
            'student_id' => $gs->student_id,
            'group_id' => $gs->group_id,
            'course_id' => $gs->group?->course_id,
            'charged' => $charged,
            'paid' => $paid,
            'balance' => round($charged - $paid, 2),
            'last_payment_at' => $last,
            'next_payment_date' => $gs->next_payment_date,
        ]);
    }

    /** Push notifications about overdue debts (cron 20:00). Returns number queued. */
    public function remindDebts(): int
    {
        $n = 0;
        Debt::with('student')->where('balance', '>', 0)
            ->where(fn ($q) => $q->whereNull('next_payment_date')->orWhere('next_payment_date', '<=', now()->toDateString()))
            ->get()->each(function (Debt $d) use (&$n) {
                if (! $d->student || $d->student->status === 'archived') {
                    return;
                }
                $n += $this->notifications->toStudentAudience($d->student, 'payment_due',
                    $this->notifications->render('payment_due', ['name' => $d->student->full_name, 'amount' => number_format($d->balance, 0, '.', ' ')]),
                    'due:'.$d->id.':'.now()->toDateString());
            });

        return $n;
    }
}
