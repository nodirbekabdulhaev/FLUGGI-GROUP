<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\GroupStudent;
use App\Models\Lesson;
use App\Models\Student;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendanceService
{
    public function __construct(protected NotificationService $notifications) {}

    /** Students that can be marked in a lesson: current members + anyone already marked. */
    public function roster(Lesson $lesson)
    {
        $ids = GroupStudent::where('group_id', $lesson->group_id)->whereIn('status', ['active', 'frozen'])->pluck('student_id')
            ->merge(Attendance::where('lesson_id', $lesson->id)->pluck('student_id'))->unique();

        return Student::withTrashed()->whereIn('id', $ids)->orderBy('first_name')->orderBy('last_name')->get();
    }

    /**
     * @param array<int,array{status:string,comment?:?string}> $rows keyed by student_id
     */
    public function mark(Lesson $lesson, array $rows, ?int $userId = null): Lesson
    {
        return DB::transaction(function () use ($lesson, $rows, $userId) {
            $lesson = Lesson::lockForUpdate()->findOrFail($lesson->id);

            if ($lesson->status === 'cancelled' || $lesson->status === 'rescheduled') {
                throw ValidationException::withMessages(['lesson' => 'Занятие отменено/перенесено — отметка невозможна.']);
            }
            if ($lesson->lesson_date->isFuture() && ! $lesson->lesson_date->isToday()) {
                throw ValidationException::withMessages(['lesson' => 'Нельзя отмечать посещаемость будущих занятий.']);
            }

            $allowed = $this->roster($lesson)->pluck('id')->flip();
            $group = $lesson->group()->first();

            foreach ($rows as $studentId => $row) {
                if (! $allowed->has((int) $studentId)) {
                    throw ValidationException::withMessages(['students' => "Ученик #{$studentId} не состоит в группе этого занятия."]);
                }
                if (! array_key_exists($row['status'] ?? '', Attendance::STATUSES)) {
                    throw ValidationException::withMessages(['students' => 'Недопустимый статус посещаемости.']);
                }

                $att = Attendance::updateOrCreate(
                    ['lesson_id' => $lesson->id, 'student_id' => (int) $studentId],
                    ['branch_id' => $lesson->branch_id, 'organization_id' => $lesson->organization_id,
                        'status' => $row['status'], 'comment' => $row['comment'] ?? null, 'marked_by' => $userId ?? auth()->id()]
                );

                if ($att->status === 'absent') {
                    $student = Student::withTrashed()->find($studentId);
                    $this->notifications->toStudentAudience($student, 'absence',
                        $this->notifications->render('absence', [
                            'name' => $student->full_name, 'group' => $group?->name, 'date' => $lesson->lesson_date->format('d.m.Y'),
                        ]),
                        'abs:'.$lesson->id.':'.$studentId);
                }
            }

            $lesson->update(['status' => 'held', 'held_at' => $lesson->held_at ?? now()]);

            return $lesson;
        });
    }

    /** Morning reminders for today's lessons (cron 08:00). Returns messages queued. */
    public function remindToday(): int
    {
        $n = 0;
        Lesson::with(['group', 'room'])->whereDate('lesson_date', today())->where('status', 'planned')->get()
            ->each(function (Lesson $l) use (&$n) {
                $students = Student::whereIn('id', GroupStudent::where('group_id', $l->group_id)->where('status', 'active')->pluck('student_id'))->get();
                foreach ($students as $s) {
                    $n += $this->notifications->toStudentAudience($s, 'lesson_reminder',
                        $this->notifications->render('lesson_reminder', [
                            'group' => $l->group?->name, 'time' => substr($l->start_time, 0, 5), 'room' => $l->room?->name ?? '',
                        ]),
                        'lr:'.$l->id.':'.$s->id);
                }
            });

        return $n;
    }
}
