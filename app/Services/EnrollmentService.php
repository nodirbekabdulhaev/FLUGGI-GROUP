<?php

namespace App\Services;

use App\Models\Debt;
use App\Models\Group;
use App\Models\GroupStudent;
use App\Models\Student;
use App\Models\StudentEvent;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Student ↔ group membership with full history (join / transfer / freeze / leave) + the debt ledger. */
class EnrollmentService
{
    public function __construct(protected PaymentService $payments) {}

    public function enroll(Student $student, Group $group, array $opts = []): GroupStudent
    {
        return DB::transaction(function () use ($student, $group, $opts) {
            $group = Group::lockForUpdate()->findOrFail($group->id);

            if (in_array($group->status, ['completed', 'archived'], true)) {
                throw ValidationException::withMessages(['group_id' => 'Группа закрыта для записи.']);
            }
            if (in_array($student->status, ['expelled', 'archived'], true)) {
                throw ValidationException::withMessages(['student_id' => 'Отчисленного/архивного ученика нельзя записать в группу.']);
            }
            if (GroupStudent::where('group_id', $group->id)->where('student_id', $student->id)->whereIn('status', ['active', 'frozen'])->exists()) {
                throw ValidationException::withMessages(['student_id' => 'Ученик уже в этой группе.']);
            }
            if ($group->activeEnrollments()->count() >= $group->max_students) {
                throw ValidationException::withMessages(['group_id' => 'В группе нет свободных мест ('.$group->max_students.').']);
            }

            $gs = GroupStudent::create([
                'branch_id' => $group->branch_id,
                'group_id' => $group->id,
                'student_id' => $student->id,
                'status' => 'active',
                'joined_at' => $opts['joined_at'] ?? now()->toDateString(),
                'price' => $opts['price'] ?? $group->effectivePrice(),
                'discount' => $opts['discount'] ?? 0,
                'next_payment_date' => $opts['next_payment_date'] ?? null,
                'transferred_from_id' => $opts['transferred_from_id'] ?? null,
                'note' => $opts['note'] ?? null,
            ]);

            if ($student->status !== 'active') {
                $student->update(['status' => 'active', 'status_changed_at' => now()->toDateString()]);
            }

            $this->payments->refreshDebt($gs);
            StudentEvent::log($student, 'enrolled', 'Записан в группу '.$group->name, ['group_id' => $group->id]);

            return $gs;
        });
    }

    /** Move to another group. The old membership is kept as "transferred"; unpaid charge moves with the student. */
    public function transfer(GroupStudent $from, Group $to, array $opts = []): GroupStudent
    {
        return DB::transaction(function () use ($from, $to, $opts) {
            $from = GroupStudent::lockForUpdate()->findOrFail($from->id);
            if (! $from->isCurrent()) {
                throw ValidationException::withMessages(['group_id' => 'Перевод возможен только из текущей группы.']);
            }
            if ($from->group_id === $to->id) {
                throw ValidationException::withMessages(['group_id' => 'Выберите другую группу.']);
            }

            // keep the amount already charged: the new group inherits the price unless overridden
            $price = $opts['price'] ?? $from->price;
            $new = $this->enroll($from->student, $to, [
                'price' => $price,
                'discount' => $opts['discount'] ?? $from->discount,
                'next_payment_date' => $from->next_payment_date?->toDateString(),
                'transferred_from_id' => $from->id,
                'note' => $opts['note'] ?? null,
            ]);

            // payments already received for the old membership follow the student
            DB::table('payments')->where('group_student_id', $from->id)->update([
                'group_student_id' => $new->id, 'group_id' => $to->id, 'course_id' => $to->course_id,
            ]);

            $from->update(['status' => 'transferred', 'left_at' => now()->toDateString()]);
            Debt::where('group_student_id', $from->id)->delete();
            $this->payments->refreshDebt($new);

            StudentEvent::log($from->student_id, 'transferred', 'Переведён: '.$from->group->name.' → '.$to->name,
                ['from' => $from->group_id, 'to' => $to->id]);

            return $new;
        });
    }

    public function freeze(GroupStudent $gs): void
    {
        $this->setStatus($gs, 'frozen', 'frozen', 'Обучение заморожено');
    }

    public function unfreeze(GroupStudent $gs): void
    {
        $this->setStatus($gs, 'active', 'unfrozen', 'Вернулся к обучению');
    }

    public function complete(GroupStudent $gs): void
    {
        $this->setStatus($gs, 'completed', 'completed', 'Завершил обучение в группе');
    }

    public function leave(GroupStudent $gs): void
    {
        $this->setStatus($gs, 'left', 'left', 'Выбыл из группы');
    }

    protected function setStatus(GroupStudent $gs, string $status, string $event, string $title): void
    {
        DB::transaction(function () use ($gs, $status, $event, $title) {
            $closing = in_array($status, ['completed', 'left'], true);
            $gs->update(['status' => $status, 'left_at' => $closing ? now()->toDateString() : null]);
            StudentEvent::log($gs->student_id, $event, $title.' '.$gs->group?->name, ['group_id' => $gs->group_id]);

            // student-level status follows when the last active membership frozen/closed
            $student = $gs->student;
            if ($status === 'frozen' && ! $student->enrollments()->where('status', 'active')->exists()) {
                $student->update(['status' => 'frozen', 'status_changed_at' => now()->toDateString()]);
            } elseif ($status === 'active' && $student->status === 'frozen') {
                $student->update(['status' => 'active', 'status_changed_at' => now()->toDateString()]);
            } elseif ($status === 'completed' && ! $student->enrollments()->whereIn('status', ['active', 'frozen'])->exists()) {
                $student->update(['status' => 'completed', 'status_changed_at' => now()->toDateString()]);
            }
        });
    }

    /** Renewal: adds one more period charge to the membership and moves the next payment date. */
    public function renew(GroupStudent $gs, ?float $amount = null): GroupStudent
    {
        return DB::transaction(function () use ($gs, $amount) {
            $amount ??= $gs->group->effectivePrice();
            $months = max(1, (int) ($gs->group->course->duration_months ?? 1));
            $base = $gs->next_payment_date && $gs->next_payment_date->isFuture() ? $gs->next_payment_date : Carbon::today();

            $gs->update(['price' => $gs->price + $amount, 'next_payment_date' => $base->copy()->addMonths($months)->toDateString()]);
            $this->payments->refreshDebt($gs);
            StudentEvent::log($gs->student_id, 'renewed', 'Продление: '.$gs->group->name, ['amount' => $amount]);

            return $gs;
        });
    }
}
