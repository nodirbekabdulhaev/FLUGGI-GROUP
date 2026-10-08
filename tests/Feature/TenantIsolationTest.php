<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\Student;
use App\Services\OrganizationSetup;
use App\Services\PaymentService;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:\App\Models\Organization,1:\App\Models\Organization} */
    private function twoOrgs(): array
    {
        $a = $this->makeOrg('Center A');
        $studentA = $this->makeStudent(['first_name' => 'Alpha']);
        $this->makeUser('super_admin', ['email' => 'a@a.uz']);
        $b = app(OrganizationSetup::class)->createOrganization('Center B');
        Tenant::set($b->id);
        Branch::create(['name' => 'B main', 'status' => 'active']);
        $this->makeUser('super_admin', ['email' => 'b@b.uz']);
        $this->makeStudent(['first_name' => 'Beta', 'branch_id' => Branch::first()->id]);
        Tenant::clear();

        return [$a, $b];
    }

    public function test_queries_are_scoped_to_the_current_organization(): void
    {
        [$a, $b] = $this->twoOrgs();
        Tenant::set($a->id);
        $this->assertSame(['Alpha'], Student::pluck('first_name')->all());
        Tenant::set($b->id);
        $this->assertSame(['Beta'], Student::pluck('first_name')->all());
    }

    public function test_user_of_org_b_cannot_open_or_change_records_of_org_a(): void
    {
        [$a, $b] = $this->twoOrgs();
        Tenant::set($a->id);
        $alpha = Student::first();
        Tenant::clear();

        $userB = \App\Models\User::where('email', 'b@b.uz')->first();
        $this->actingAs($userB)->get("/students/{$alpha->id}")->assertNotFound();
        $this->actingAs($userB)->get("/students/{$alpha->id}/edit")->assertNotFound();
        $this->actingAs($userB)->put("/students/{$alpha->id}", ['first_name' => 'Hacked', 'branch_id' => 1])->assertNotFound();
        $this->actingAs($userB)->getJson("/finance/students/{$alpha->id}/balance")->assertNotFound();
        $this->assertSame('Alpha', Student::withoutGlobalScopes()->find($alpha->id)->first_name);

        $page = $this->actingAs($userB)->get('/students')->assertOk()->getContent();
        $this->assertStringContainsString('Beta', $page);
        $this->assertStringNotContainsString('Alpha', $page);
    }

    public function test_cannot_reference_foreign_records_in_forms_or_services(): void
    {
        [$a, $b] = $this->twoOrgs();
        Tenant::set($a->id);
        $alpha = Student::first();
        $branchA = Branch::first();
        Tenant::set($b->id);

        // payment to a student of another organization is impossible
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        app(PaymentService::class)->record(['student_id' => $alpha->id, 'amount' => 1000]);
    }

    public function test_foreign_ids_are_rejected_by_form_validation(): void
    {
        [$a, $b] = $this->twoOrgs();
        Tenant::set($a->id);
        $branchA = Branch::first();
        Tenant::clear();
        $userB = \App\Models\User::where('email', 'b@b.uz')->first();

        $this->actingAs($userB)->post('/students', ['first_name' => 'Evil', 'branch_id' => $branchA->id])->assertSessionHasErrors('branch_id');
        Tenant::set($b->id);
        $this->assertFalse(Student::where('first_name', 'Evil')->exists());
    }

    public function test_organization_is_forced_on_create_even_if_request_tries_to_set_it(): void
    {
        [$a, $b] = $this->twoOrgs();
        Tenant::set($b->id);
        $lead = Lead::create(['organization_id' => $a->id, 'first_name' => 'X', 'phone' => '+998901112233', 'branch_id' => Branch::first()->id]);
        $this->assertSame($b->id, $lead->organization_id);
    }

    public function test_api_is_tenant_scoped_too(): void
    {
        [$a, $b] = $this->twoOrgs();
        $userB = \App\Models\User::where('email', 'b@b.uz')->first();
        \Laravel\Sanctum\Sanctum::actingAs($userB, ['*']);
        $names = collect($this->getJson('/api/v1/students')->assertOk()->json('data'))->pluck('first_name')->all();
        $this->assertSame(['Beta'], $names);
    }
}
