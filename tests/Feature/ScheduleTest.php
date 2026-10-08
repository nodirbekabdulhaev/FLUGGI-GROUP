<?php

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\Schedule;
use App\Services\ScheduleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    use RefreshDatabase;

    private function slot(array $a): array
    {
        return $a + ['weekday' => 1, 'start_time' => '18:00', 'end_time' => '19:30', 'start_date' => today()->toDateString(), 'end_date' => today()->addMonth()->toDateString(), 'is_active' => true];
    }

    private function conflicts(callable $fn): array
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            return $e->errors()['conflict'];
        }

        return [];
    }

    public function test_teacher_conflict_is_detected_example_from_spec(): void
    {
        $this->makeOrg();
        $svc = app(ScheduleService::class);
        $sardor = $this->makeTeacher(['full_name' => 'Sardor']);
        $g17 = $this->makeGroup(['name' => 'IELTS-17', 'teacher_id' => $sardor->id]);
        $g22 = $this->makeGroup(['name' => 'IELTS-22', 'teacher_id' => $sardor->id]);

        $svc->create($this->slot(['group_id' => $g17->id]));                       // 18:00–19:30
        $msgs = $this->conflicts(fn () => $svc->create($this->slot(['group_id' => $g22->id, 'start_time' => '18:30', 'end_time' => '20:00'])));

        $this->assertNotEmpty($msgs);
        $this->assertStringContainsString('Sardor', $msgs[0]);
        $this->assertSame(1, Schedule::count());           // nothing was saved
    }

    public function test_room_and_group_conflicts_and_boundaries(): void
    {
        $this->makeOrg();
        $svc = app(ScheduleService::class);
        $room = $this->makeRoom('Room 204');
        $g1 = $this->makeGroup(['room_id' => $room->id]);
        $g2 = $this->makeGroup(['room_id' => $room->id]);
        $svc->create($this->slot(['group_id' => $g1->id]));

        // same room, overlapping, different teacher
        $m = $this->conflicts(fn () => $svc->create($this->slot(['group_id' => $g2->id, 'start_time' => '19:00', 'end_time' => '20:00'])));
        $this->assertStringContainsString('Room 204', implode(' ', $m));

        // the same group cannot have two lessons at once (even in another room, with another teacher)
        $other = $this->makeRoom('Room 9');
        $m = $this->conflicts(fn () => $svc->create($this->slot(['group_id' => $g1->id, 'room_id' => $other->id, 'teacher_id' => $this->makeTeacher(['full_name' => 'Other'])->id, 'start_time' => '19:00', 'end_time' => '20:00'])));
        $this->assertStringContainsString('группы', implode(' ', $m));

        // back-to-back (19:30 → 19:30) is NOT a conflict; neither is another weekday or a later date range
        $svc->create($this->slot(['group_id' => $g2->id, 'start_time' => '19:30', 'end_time' => '21:00']));
        $svc->create($this->slot(['group_id' => $g2->id, 'weekday' => 2]));
        $svc->create($this->slot(['group_id' => $g2->id, 'start_date' => today()->addMonths(3)->toDateString(), 'end_date' => null, 'start_time' => '18:00', 'end_time' => '19:30']));
        $this->assertSame(4, Schedule::count());
    }

    public function test_editing_a_slot_does_not_conflict_with_itself_and_regenerates_future_lessons(): void
    {
        $this->makeOrg();
        $svc = app(ScheduleService::class);
        $g = $this->makeGroup();
        $s = $svc->create($this->slot(['group_id' => $g->id, 'start_date' => today()->toDateString(), 'end_date' => today()->addDays(60)->toDateString()]));
        $before = Lesson::where('schedule_id', $s->id)->count();
        $this->assertGreaterThan(5, $before);

        $svc->update($s, $this->slot(['start_time' => '17:00', 'end_time' => '18:30', 'start_date' => today()->toDateString(), 'end_date' => today()->addDays(60)->toDateString()]));
        $this->assertSame($before, Lesson::where('schedule_id', $s->id)->count());
        $this->assertSame('17:00:00', Lesson::where('schedule_id', $s->id)->first()->start_time);
    }

    public function test_lessons_are_generated_on_the_right_weekdays_and_idempotently(): void
    {
        $this->makeOrg();
        $svc = app(ScheduleService::class);
        $g = $this->makeGroup();
        $start = Carbon::parse('2026-11-02');     // a Monday
        $s = $svc->create($this->slot(['group_id' => $g->id, 'weekday' => 3, 'start_date' => $start->toDateString(), 'end_date' => '2026-11-30']));

        $dates = Lesson::where('schedule_id', $s->id)->orderBy('lesson_date')->pluck('lesson_date')->map->toDateString()->all();
        $this->assertSame(['2026-11-04', '2026-11-11', '2026-11-18', '2026-11-25'], $dates);     // Wednesdays
        $this->assertSame(0, $svc->generateLessons($s));       // second run creates nothing
        $this->assertSame(4, Lesson::count());
        $this->assertSame($g->teacher_id, Lesson::first()->teacher_id);
    }

    public function test_cancel_and_reschedule_a_lesson(): void
    {
        $this->makeOrg();
        $svc = app(ScheduleService::class);
        $g = $this->makeGroup();
        $svc->create($this->slot(['group_id' => $g->id, 'start_date' => today()->addDay()->toDateString()]));
        [$a, $b] = Lesson::orderBy('lesson_date')->take(2)->get();

        $svc->cancel($a, 'Болезнь');
        $this->assertSame('cancelled', $a->fresh()->status);

        $new = $svc->reschedule($b, ['lesson_date' => $b->lesson_date->addDay()->toDateString(), 'start_time' => '10:00', 'end_time' => '11:00']);
        $this->assertSame('rescheduled', $b->fresh()->status);
        $this->assertSame('planned', $new->status);
        $this->assertSame('10:00:00', $new->start_time);

        // a cancelled/rescheduled lesson no longer blocks the slot
        $this->assertEmpty($svc->lessonConflicts(['lesson_date' => $b->lesson_date->toDateString(), 'start_time' => '18:00', 'end_time' => '19:30', 'group_id' => $g->id, 'teacher_id' => $g->teacher_id, 'room_id' => $g->room_id]));
    }

    public function test_conflict_check_endpoint_and_form_show_the_error(): void
    {
        $this->makeOrg();
        $admin = $this->makeUser('admin');
        $teacher = $this->makeTeacher();
        $g1 = $this->makeGroup(['teacher_id' => $teacher->id]);
        $g2 = $this->makeGroup(['teacher_id' => $teacher->id]);
        app(ScheduleService::class)->create($this->slot(['group_id' => $g1->id]));

        $payload = ['group_id' => $g2->id, 'teacher_id' => $teacher->id, 'weekdays' => [1], 'start_time' => '18:30', 'end_time' => '20:00', 'start_date' => today()->toDateString()];
        $this->actingAs($admin)->postJson('/schedule/check', $payload)->assertOk()->assertJsonCount(1, 'conflicts');
        $this->actingAs($admin)->post('/schedule', $payload)->assertSessionHasErrors('conflict');
        $this->assertSame(1, Schedule::count());
    }
}
