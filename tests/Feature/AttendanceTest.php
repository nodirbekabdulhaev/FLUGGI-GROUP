<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\Notification;
use App\Models\Guardian;
use App\Models\TelegramAccount;
use App\Services\AttendanceService;
use App\Services\EnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    private function setup3(): array
    {
        $this->makeOrg();
        $group = $this->makeGroup(['max_students' => 5]);
        $students = collect(['Muhammad', 'Aziz', 'Ali', 'Bekzod'])->map(function ($n) use ($group) {
            $s = $this->makeStudent(['first_name' => $n]);
            app(EnrollmentService::class)->enroll($s, $group);

            return $s;
        });
        $lesson = Lesson::create(['group_id' => $group->id, 'branch_id' => $group->branch_id, 'teacher_id' => $group->teacher_id, 'lesson_date' => today(), 'start_time' => '18:00:00', 'end_time' => '19:30:00']);

        return [$group, $students, $lesson];
    }

    public function test_marks_all_four_statuses_and_flows_into_student_stats(): void
    {
        [$group, $s, $lesson] = $this->setup3();
        $admin = $this->makeUser('admin');

        app(AttendanceService::class)->mark($lesson, [
            $s[0]->id => ['status' => 'present'], $s[1]->id => ['status' => 'late', 'comment' => 'Пробки'],
            $s[2]->id => ['status' => 'absent'], $s[3]->id => ['status' => 'excused', 'comment' => 'Справка'],
        ], $admin->id);

        $this->assertSame('held', $lesson->fresh()->status);
        $this->assertNotNull($lesson->fresh()->held_at);
        $this->assertSame(4, Attendance::count());
        $this->assertSame('Пробки', Attendance::where('student_id', $s[1]->id)->value('comment'));

        $this->assertEquals(['total' => 1, 'visited' => 1, 'missed' => 0, 'percent' => 100], $s[0]->attendanceStats());
        $this->assertEquals(100, $s[1]->attendanceStats()['percent']);       // late counts as visited
        $this->assertEquals(0, $s[2]->attendanceStats()['percent']);
        $this->assertSame(1, $s[3]->attendanceStats()['missed']);
    }

    public function test_remarking_updates_instead_of_duplicating(): void
    {
        [$group, $s, $lesson] = $this->setup3();
        $svc = app(AttendanceService::class);
        $svc->mark($lesson, [$s[0]->id => ['status' => 'absent']]);
        $svc->mark($lesson->fresh(), [$s[0]->id => ['status' => 'present']]);
        $this->assertSame(1, Attendance::count());
        $this->assertSame('present', Attendance::first()->status);
    }

    public function test_cannot_mark_foreign_students_future_or_cancelled_lessons(): void
    {
        [$group, $s, $lesson] = $this->setup3();
        $svc = app(AttendanceService::class);
        $outsider = $this->makeStudent();

        $this->expectValidation(fn () => $svc->mark($lesson, [$outsider->id => ['status' => 'present']]));
        $this->expectValidation(fn () => $svc->mark($lesson, [$s[0]->id => ['status' => 'maybe']]));

        $future = Lesson::create(['group_id' => $group->id, 'branch_id' => $group->branch_id, 'lesson_date' => today()->addDay(), 'start_time' => '18:00:00', 'end_time' => '19:00:00']);
        $this->expectValidation(fn () => $svc->mark($future, [$s[0]->id => ['status' => 'present']]));

        $lesson->update(['status' => 'cancelled']);
        $this->expectValidation(fn () => $svc->mark($lesson->fresh(), [$s[0]->id => ['status' => 'present']]));
        $this->assertSame(0, Attendance::count());
    }

    private function expectValidation(callable $fn): void
    {
        try {
            $fn();
            $this->fail('ValidationException expected');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_absence_notifies_linked_parent_in_telegram_once(): void
    {
        [$group, $s, $lesson] = $this->setup3();
        $parent = Guardian::create(['full_name' => 'Parent']);
        $s[2]->guardians()->attach($parent->id);
        TelegramAccount::create(['linkable_type' => 'guardian', 'linkable_id' => $parent->id, 'telegram_user_id' => 777, 'chat_id' => 777, 'linked_at' => now()]);

        $svc = app(AttendanceService::class);
        $svc->mark($lesson, [$s[2]->id => ['status' => 'absent']]);
        $svc->mark($lesson->fresh(), [$s[2]->id => ['status' => 'absent']]);      // re-save must not spam

        $n = Notification::where('type', 'absence')->get();
        $this->assertCount(1, $n);
        $this->assertSame(777, (int) $n[0]->chat_id);
        $this->assertStringContainsString('Ali', $n[0]->body);
        $this->assertStringContainsString($group->name, $n[0]->body);
    }

    public function test_teacher_can_mark_only_own_lessons_via_web_and_api(): void
    {
        [$group, $s, $lesson] = $this->setup3();
        $teacherUser = $this->makeUser('teacher');
        $group->teacher->update(['user_id' => $teacherUser->id]);

        $rows = [$s[0]->id => ['status' => 'present'], $s[1]->id => ['status' => 'absent']];
        $this->actingAs($teacherUser)->post("/lessons/{$lesson->id}/attendance", ['rows' => $rows])->assertRedirect();
        $this->assertSame(2, Attendance::count());

        // someone else's lesson is invisible (404)
        $other = $this->makeGroup();
        $otherLesson = Lesson::create(['group_id' => $other->id, 'branch_id' => $other->branch_id, 'teacher_id' => $other->teacher_id, 'lesson_date' => today(), 'start_time' => '10:00:00', 'end_time' => '11:00:00']);
        $this->actingAs($teacherUser)->post("/lessons/{$otherLesson->id}/attendance", ['rows' => [$s[0]->id => ['status' => 'present']]])->assertNotFound();

        \Laravel\Sanctum\Sanctum::actingAs($teacherUser, ['*']);
        $this->postJson('/api/v1/attendance', ['lesson_id' => $lesson->id, 'rows' => [['student_id' => $s[2]->id, 'status' => 'late']]])->assertOk()->assertJsonPath('data.marked', 1);
        $this->postJson('/api/v1/attendance', ['lesson_id' => $otherLesson->id, 'rows' => [['student_id' => $s[2]->id, 'status' => 'late']]])->assertNotFound();
    }
}
