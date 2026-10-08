<?php

namespace Tests\Feature;

use App\Models\Debt;
use App\Models\GroupStudent;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Student;
use App\Services\EnrollmentService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EnrollmentTest extends TestCase
{
    use RefreshDatabase;

    private function svc(): EnrollmentService
    {
        return app(EnrollmentService::class);
    }

    public function test_capacity_duplicates_and_closed_groups(): void
    {
        $this->makeOrg();
        $group = $this->makeGroup(['max_students' => 2]);
        foreach (range(1, 2) as $i) {
            $this->svc()->enroll($this->makeStudent(), $group);
        }
        $third = $this->makeStudent();
        $this->assertRejects(fn () => $this->svc()->enroll($third, $group));          // full

        $other = $this->makeGroup(['max_students' => 5]);
        $this->svc()->enroll($third, $other);
        $this->assertRejects(fn () => $this->svc()->enroll($third, $other));           // already a member

        $closed = $this->makeGroup(['status' => 'archived']);
        $this->assertRejects(fn () => $this->svc()->enroll($this->makeStudent(), $closed));
        $expelled = $this->makeStudent(['status' => 'expelled']);
        $this->assertRejects(fn () => $this->svc()->enroll($expelled, $other));
    }

    private function assertRejects(callable $fn): void
    {
        try {
            $fn();
            $this->fail('ValidationException expected');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_transfer_keeps_history_and_moves_payments_with_the_student(): void
    {
        $this->makeOrg();
        $this->actingAs($this->makeUser('admin'));
        $from = $this->makeGroup(['price' => 600000]);
        $to = $this->makeGroup(['price' => 700000]);
        $student = $this->makeStudent();
        $old = $this->svc()->enroll($student, $from);
        app(PaymentService::class)->record(['student_id' => $student->id, 'amount' => 400000, 'method_id' => PaymentMethod::first()->id]);

        $new = $this->svc()->transfer($old, $to);

        $this->assertSame('transferred', $old->fresh()->status);
        $this->assertNotNull($old->fresh()->left_at);
        $this->assertSame($old->id, $new->transferred_from_id);
        $this->assertSame(['transferred', 'active'], GroupStudent::where('student_id', $student->id)->orderBy('id')->pluck('status')->all());
        $this->assertSame($new->id, Payment::first()->group_student_id);
        $this->assertSame($to->id, Payment::first()->group_id);
        // the new membership inherits the agreed price (600k), paid 400k → 200k debt; the old debt row is gone
        $this->assertEquals([200000], Debt::pluck('balance')->map(fn ($b) => (float) $b)->all());
        $this->assertEquals(200000, $student->financeSummary()['balance']);
        $this->assertSame(1, $student->events()->where('type', 'transferred')->count());
    }

    public function test_freeze_return_and_complete_update_student_status(): void
    {
        $this->makeOrg();
        $student = $this->makeStudent();
        $gs = $this->svc()->enroll($student, $this->makeGroup());

        $this->svc()->freeze($gs);
        $this->assertSame('frozen', $student->fresh()->status);
        $this->assertSame('frozen', $gs->fresh()->status);

        $this->svc()->unfreeze($gs->fresh());
        $this->assertSame('active', $student->fresh()->status);

        $this->svc()->complete($gs->fresh());
        $this->assertSame('completed', $student->fresh()->status);
        $this->assertSame('completed', $gs->fresh()->status);
    }

    public function test_renewal_adds_a_period_charge_and_moves_next_payment_date(): void
    {
        $this->makeOrg();
        $group = $this->makeGroup(['price' => 300000]);
        $gs = $this->svc()->enroll($this->makeStudent(), $group);
        $this->svc()->renew($gs);
        $this->assertEquals(600000, Debt::first()->charged);
        $this->assertTrue($gs->fresh()->next_payment_date->isFuture());
    }

    public function test_expelling_a_student_via_http_requires_a_reason_and_closes_memberships(): void
    {
        $this->makeOrg();
        $admin = $this->makeUser('admin');
        $student = $this->makeStudent();
        $gs = $this->svc()->enroll($student, $this->makeGroup());

        $this->actingAs($admin)->post("/students/{$student->id}/status", ['status' => 'expelled'])->assertSessionHasErrors('expulsion_reason_id');
        $reason = \App\Models\ExpulsionReason::where('name', 'Дорого')->value('id');
        $this->actingAs($admin)->post("/students/{$student->id}/status", ['status' => 'expelled', 'expulsion_reason_id' => $reason])->assertRedirect();

        $this->assertSame('expelled', $student->fresh()->status);
        $this->assertSame($reason, $student->fresh()->expulsion_reason_id);
        $this->assertSame('left', $gs->fresh()->status);
    }

    public function test_student_soft_delete_keeps_payments_and_requires_permission(): void
    {
        $this->makeOrg();
        $student = $this->makeStudent();
        $this->svc()->enroll($student, $this->makeGroup());
        $admin = $this->makeUser('admin');             // admin has no students.delete
        $this->actingAs($admin)->delete("/students/{$student->id}")->assertForbidden();

        $boss = $this->makeUser('director');
        $this->actingAs($boss)->delete("/students/{$student->id}")->assertRedirect();
        $this->assertSoftDeleted($student);
        $this->assertSame(1, Debt::count());
    }

    public function test_student_card_totals(): void
    {
        $this->makeOrg();
        $this->actingAs($this->makeUser('admin'));
        $student = $this->makeStudent();
        $this->svc()->enroll($student, $this->makeGroup(['price' => 600000]));
        app(PaymentService::class)->record(['student_id' => $student->id, 'amount' => 400000, 'method_id' => PaymentMethod::first()->id]);
        $html = $this->get("/students/{$student->id}")->assertOk()->getContent();
        $this->assertStringContainsString('200 000', $html);
    }
}
