<?php

namespace Tests\Feature;

use App\Models\Guardian;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Notification;
use App\Models\Student;
use App\Services\LeadService;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LeadFlowTest extends TestCase
{
    use RefreshDatabase;

    private function lead(array $a = []): Lead
    {
        return app(LeadService::class)->create($a + [
            'first_name' => 'Muhammad', 'phone' => '+998 90 111 22 33', 'branch_id' => $this->branch()->id,
            'source_id' => LeadSource::where('name', 'Instagram')->value('id'), 'parent_name' => 'Bahrom A.', 'parent_phone' => '90 555 44 33',
        ]);
    }

    public function test_create_lead_via_form_sets_defaults_and_notifies_managers(): void
    {
        $this->makeOrg();
        $admin = $this->makeUser('admin');
        $manager = $this->makeUser('sales_manager');
        $course = $this->makeCourse();

        $this->actingAs($admin)->post('/leads', [
            'first_name' => 'Aziz', 'phone' => '901234567', 'source_id' => LeadSource::first()->id, 'course_id' => $course->id,
            'branch_id' => $this->branch()->id, 'manager_id' => $manager->id, 'status_id' => LeadStatus::where('slug', 'new')->value('id'),
        ])->assertRedirect();

        $lead = Lead::first();
        $this->assertSame('+998901234567', $lead->phone);      // normalised
        $this->assertSame(1, $lead->max_stage);
        $this->assertSame('Лид создан', $lead->notes()->first()->body);
        $this->assertTrue(Notification::where('user_id', $manager->id)->where('type', 'new_lead')->exists());
    }

    public function test_status_changes_track_funnel_stage_and_history(): void
    {
        $this->makeOrg();
        $this->actingAs($this->makeUser('admin'));
        $lead = $this->lead();
        $svc = app(LeadService::class);

        $svc->changeStatus($lead, LeadStatus::where('slug', 'trial_attended')->first());
        $this->assertSame(4, $lead->fresh()->max_stage);
        // going "back" or to a lost status does not lower the reached stage
        $svc->changeStatus($lead->fresh(), LeadStatus::where('slug', 'lost')->first());
        $this->assertSame(4, $lead->fresh()->max_stage);
        $this->assertStringContainsString('→', $lead->notes()->first()->body);
    }

    public function test_funnel_rates(): void
    {
        $this->makeOrg();
        $this->actingAs($this->makeUser('admin'));
        $svc = app(LeadService::class);
        $st = fn ($s) => LeadStatus::where('slug', $s)->first();
        // 10 leads → 8 contacted → 5 booked → 4 attended → 2 sold
        $leads = collect(range(1, 10))->map(fn ($i) => $this->lead(['phone' => "+99890000000$i", 'first_name' => "L$i"]));
        foreach ($leads->take(8) as $l) { $svc->changeStatus($l, $st('contacted')); }
        foreach ($leads->take(5) as $l) { $svc->changeStatus($l, $st('trial_booked')); }
        foreach ($leads->take(4) as $l) { $svc->changeStatus($l, $st('trial_attended')); }
        foreach ($leads->take(2) as $l) { $svc->changeStatus($l, $st('paid')); }

        $f = app(ReportService::class)->funnel(now()->startOfMonth(), now()->endOfMonth());
        $this->assertSame([10, 8, 5, 4, 2], [$f['total'], $f['contacted'], $f['booked'], $f['attended'], $f['sold']]);
        $this->assertSame(80.0, $f['contact_rate']);
        $this->assertSame(62.5, $f['booking_rate']);
        $this->assertSame(80.0, $f['show_rate']);
        $this->assertSame(20.0, $f['conversion_rate']);
    }

    public function test_convert_lead_creates_student_with_parent_and_keeps_the_lead(): void
    {
        $this->makeOrg();
        $this->actingAs($admin = $this->makeUser('admin'));
        $course = $this->makeCourse();
        $group = $this->makeGroup(['course_id' => $course->id]);
        $lead = $this->lead(['course_id' => $course->id, 'manager_id' => $admin->id]);

        $student = app(LeadService::class)->convert($lead, ['group_id' => $group->id]);

        $this->assertSame('Muhammad', $student->first_name);
        $this->assertSame($lead->phone, $student->phone);
        $this->assertSame($lead->source_id, $student->source_id);
        $this->assertSame($admin->id, $student->manager_id);
        $this->assertSame($lead->id, $student->lead_id);
        $this->assertSame('Bahrom A.', $student->guardians()->first()->full_name);
        $this->assertTrue($student->enrollments()->where('group_id', $group->id)->exists());
        $this->assertEquals(600000, $student->financeSummary()['charged']);

        $lead->refresh();
        $this->assertNull($lead->deleted_at);                       // not deleted
        $this->assertSame($student->id, $lead->converted_student_id);
        $this->assertSame('converted', $lead->status->slug);
        $this->assertSame(5, $lead->max_stage);
    }

    public function test_cannot_convert_twice_and_existing_parent_is_reused(): void
    {
        $this->makeOrg();
        $this->actingAs($this->makeUser('admin'));
        $svc = app(LeadService::class);
        $a = $this->lead();
        $b = $this->lead(['phone' => '+998907777777', 'first_name' => 'Sibling']);

        $svc->convert($a);
        try {
            $svc->convert($a->fresh());
            $this->fail('second conversion must fail');
        } catch (ValidationException) {
        }
        $svc->convert($b);
        $this->assertSame(2, Student::count());
        $this->assertSame(1, Guardian::count());       // same parent phone → one parent, two children
        $this->assertSame(2, Guardian::first()->children()->count());
    }

    public function test_sales_manager_sees_only_own_and_unassigned_leads(): void
    {
        $this->makeOrg();
        $m1 = $this->makeUser('sales_manager');
        $m2 = $this->makeUser('sales_manager');
        $mine = $this->lead(['manager_id' => $m1->id, 'first_name' => 'Mine', 'phone' => '+998900000001']);
        $theirs = $this->lead(['manager_id' => $m2->id, 'first_name' => 'Theirs', 'phone' => '+998900000002']);
        $free = $this->lead(['first_name' => 'Free', 'phone' => '+998900000003']);

        $html = $this->actingAs($m1)->get('/leads')->assertOk()->getContent();
        $this->assertStringContainsString('Mine', $html);
        $this->assertStringContainsString('Free', $html);
        $this->assertStringNotContainsString('Theirs', $html);
        $this->actingAs($m1)->get("/leads/{$theirs->id}")->assertForbidden();
        $this->actingAs($m1)->post("/leads/{$theirs->id}/status", ['status_id' => 1])->assertForbidden();
    }

    public function test_webhook_creates_lead_authenticates_and_dedupes(): void
    {
        $org = $this->makeOrg();
        $this->makeCourse(['name' => 'IELTS']);
        $payload = ['name' => 'Muhammad', 'phone' => '+998901234567', 'course' => 'ielts', 'source' => 'instagram'];

        $this->postJson('/api/v1/webhooks/leads', $payload)->assertUnauthorized();
        $this->postJson('/api/v1/webhooks/leads', $payload, ['X-Webhook-Key' => 'x'.$org->webhook_key])->assertUnauthorized();

        $res = $this->postJson('/api/v1/webhooks/leads', $payload, ['X-Webhook-Key' => $org->webhook_key])->assertCreated()->assertJson(['duplicate' => false]);
        $lead = Lead::find($res->json('id'));
        $this->assertSame('Instagram', $lead->source->name);
        $this->assertSame('IELTS', $lead->course->name);
        $this->assertSame($org->id, $lead->organization_id);

        $this->postJson('/api/v1/webhooks/leads', $payload, ['X-Webhook-Key' => $org->webhook_key])->assertOk()->assertJson(['duplicate' => true, 'id' => $lead->id]);
        $this->assertSame(1, Lead::count());

        $this->postJson('/api/v1/webhooks/leads', ['name' => 'NoPhone'], ['X-Webhook-Key' => $org->webhook_key])->assertStatus(422);
    }
}
