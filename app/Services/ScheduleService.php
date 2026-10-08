<?php

namespace App\Services;

use App\Models\Lesson;
use App\Models\Schedule;
use App\Models\StudentEvent;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScheduleService
{
    public const DEFAULT_HORIZON_DAYS = 90;

    public static function norm(string $time): string
    {
        return strlen($time) === 5 ? $time.':00' : $time;
    }

    /** Two intervals overlap when each starts before the other ends. */
    protected static function overlap(string $s1, string $e1, string $s2, string $e2): bool
    {
        return $s1 < $e2 && $s2 < $e1;
    }

    /**
     * Conflicts of a recurring slot with existing schedules (teacher, room, group).
     *
     * @return array<int, array{type:string,message:string}>
     */
    public function conflicts(array $d, ?int $exceptScheduleId = null): array
    {
        $start = static::norm($d['start_time']);
        $end = static::norm($d['end_time']);
        $from = Carbon::parse($d['start_date'])->toDateString();
        $to = ! empty($d['end_date']) ? Carbon::parse($d['end_date'])->toDateString() : '9999-12-31';

        $q = Schedule::with(['group', 'teacher', 'room'])
            ->where('is_active', true)
            ->where('weekday', $d['weekday'])
            ->where('start_time', '<', $end)->where('end_time', '>', $start)
            ->where('start_date', '<=', $to)
            ->where(fn ($w) => $w->whereNull('end_date')->orWhere('end_date', '>=', $from));
        if ($exceptScheduleId) {
            $q->where('id', '!=', $exceptScheduleId);
        }

        $out = [];
        foreach ($q->get() as $s) {
            $slot = substr($s->start_time, 0, 5).'–'.substr($s->end_time, 0, 5);
            if (! empty($d['teacher_id']) && $s->teacher_id == $d['teacher_id']) {
                $out[] = ['type' => 'teacher', 'message' => "Преподаватель {$s->teacher?->full_name} уже занят: {$slot}, группа {$s->group?->name}"];
            }
            if (! empty($d['room_id']) && $s->room_id == $d['room_id']) {
                $out[] = ['type' => 'room', 'message' => "Аудитория {$s->room?->name} занята: {$slot}, группа {$s->group?->name}"];
            }
            if (! empty($d['group_id']) && $s->group_id == $d['group_id']) {
                $out[] = ['type' => 'group', 'message' => "У группы {$s->group?->name} уже есть занятие: {$slot}"];
            }
        }

        return $out;
    }

    /** Conflicts of a single dated lesson with other (non-cancelled) lessons. */
    public function lessonConflicts(array $d, ?int $exceptLessonId = null): array
    {
        $start = static::norm($d['start_time']);
        $end = static::norm($d['end_time']);

        $q = Lesson::with(['group', 'teacher', 'room'])
            ->whereDate('lesson_date', $d['lesson_date'])
            ->whereIn('status', ['planned', 'held'])
            ->where('start_time', '<', $end)->where('end_time', '>', $start);
        if ($exceptLessonId) {
            $q->where('id', '!=', $exceptLessonId);
        }

        $out = [];
        foreach ($q->get() as $l) {
            $slot = substr($l->start_time, 0, 5).'–'.substr($l->end_time, 0, 5);
            if (! empty($d['teacher_id']) && $l->teacher_id == $d['teacher_id']) {
                $out[] = ['type' => 'teacher', 'message' => "Преподаватель {$l->teacher?->full_name} занят: {$slot}, {$l->group?->name}"];
            }
            if (! empty($d['room_id']) && $l->room_id == $d['room_id']) {
                $out[] = ['type' => 'room', 'message' => "Аудитория {$l->room?->name} занята: {$slot}, {$l->group?->name}"];
            }
            if (! empty($d['group_id']) && $l->group_id == $d['group_id']) {
                $out[] = ['type' => 'group', 'message' => "У группы {$l->group?->name} уже есть занятие: {$slot}"];
            }
        }

        return $out;
    }

    protected function failIfConflicts(array $conflicts): void
    {
        if ($conflicts) {
            throw ValidationException::withMessages(['conflict' => array_column($conflicts, 'message')]);
        }
    }

    /** Teacher / room / branch default to the group's own when the form leaves them empty. */
    protected function withGroupDefaults(array $d, \App\Models\Group $group): array
    {
        $d['branch_id'] = $group->branch_id;
        $d['teacher_id'] = ! empty($d['teacher_id']) ? $d['teacher_id'] : $group->teacher_id;
        $d['room_id'] = ! empty($d['room_id']) ? $d['room_id'] : $group->room_id;

        return $d;
    }

    public function create(array $d): Schedule
    {
        return DB::transaction(function () use ($d) {
            $d['start_time'] = static::norm($d['start_time']);
            $d['end_time'] = static::norm($d['end_time']);
            $d = $this->withGroupDefaults($d, \App\Models\Group::findOrFail($d['group_id']));
            $this->failIfConflicts($this->conflicts($d));

            $schedule = Schedule::create($d);
            $this->generateLessons($schedule);

            return $schedule;
        });
    }

    public function update(Schedule $schedule, array $d): Schedule
    {
        return DB::transaction(function () use ($schedule, $d) {
            $d['start_time'] = static::norm($d['start_time']);
            $d['end_time'] = static::norm($d['end_time']);
            $d = $this->withGroupDefaults($d + ['group_id' => $schedule->group_id], $schedule->group()->firstOrFail());
            $this->failIfConflicts($this->conflicts($d, $schedule->id));

            $schedule->update($d);
            $this->purgeFutureLessons($schedule);
            if ($schedule->is_active) {
                $this->generateLessons($schedule);
            }

            return $schedule;
        });
    }

    public function delete(Schedule $schedule): void
    {
        DB::transaction(function () use ($schedule) {
            $this->purgeFutureLessons($schedule);
            $schedule->delete();
        });
    }

    /** Remove not-yet-held lessons of the schedule (today and later) that have no attendance. */
    protected function purgeFutureLessons(Schedule $schedule): void
    {
        $schedule->lessons()->where('status', 'planned')->whereDate('lesson_date', '>=', today())
            ->whereDoesntHave('attendance')->delete();
    }

    /** Create lessons for each matching weekday up to the horizon. Idempotent. Returns number created. */
    public function generateLessons(Schedule $schedule, ?Carbon $until = null): int
    {
        $from = $schedule->start_date->copy()->startOfDay();
        $until ??= Carbon::today()->addDays(self::DEFAULT_HORIZON_DAYS);
        if ($schedule->end_date && $schedule->end_date->lt($until)) {
            $until = $schedule->end_date->copy();
        }
        // the first matching weekday on/after start_date
        $day = $from->copy();
        $shift = ($schedule->weekday - $day->dayOfWeekIso + 7) % 7;
        $day->addDays($shift);

        $existing = Lesson::where('schedule_id', $schedule->id)->pluck('lesson_date')->map(fn ($d) => Carbon::parse($d)->toDateString())->flip();

        $created = 0;
        for (; $day->lte($until); $day->addWeek()) {
            if ($existing->has($day->toDateString())) {
                continue;
            }
            Lesson::create([
                'branch_id' => $schedule->branch_id,
                'group_id' => $schedule->group_id,
                'schedule_id' => $schedule->id,
                'teacher_id' => $schedule->teacher_id,
                'room_id' => $schedule->room_id,
                'lesson_date' => $day->toDateString(),
                'start_time' => $schedule->start_time,
                'end_time' => $schedule->end_time,
                'status' => 'planned',
            ]);
            $created++;
        }

        return $created;
    }

    public function cancel(Lesson $lesson, ?string $reason = null): Lesson
    {
        if ($lesson->status === 'held') {
            throw ValidationException::withMessages(['lesson' => 'Проведённое занятие нельзя отменить.']);
        }
        $lesson->update(['status' => 'cancelled', 'cancel_reason' => $reason]);

        return $lesson;
    }

    /** Move a lesson to another date/time: the original is kept as "rescheduled", a new planned lesson is created. */
    public function reschedule(Lesson $lesson, array $d): Lesson
    {
        return DB::transaction(function () use ($lesson, $d) {
            if ($lesson->status === 'held') {
                throw ValidationException::withMessages(['lesson' => 'Проведённое занятие нельзя перенести.']);
            }
            $new = [
                'lesson_date' => $d['lesson_date'],
                'start_time' => static::norm($d['start_time']),
                'end_time' => static::norm($d['end_time']),
                'teacher_id' => $d['teacher_id'] ?? $lesson->teacher_id,
                'room_id' => $d['room_id'] ?? $lesson->room_id,
                'group_id' => $lesson->group_id,
            ];
            $this->failIfConflicts($this->lessonConflicts($new, $lesson->id));

            $lesson->update(['status' => 'rescheduled']);

            return Lesson::create($new + ['branch_id' => $lesson->branch_id, 'schedule_id' => null, 'status' => 'planned', 'topic' => $lesson->topic]);
        });
    }

    /** Extend every active schedule to the default horizon (cron). */
    public function extendAll(): int
    {
        $n = 0;
        Schedule::where('is_active', true)->get()->each(function ($s) use (&$n) {
            $n += $this->generateLessons($s);
        });

        return $n;
    }
}
