<?php

namespace Tests;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\OrganizationSetup;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::clear();
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    /** Creates an organization with dictionaries, one branch and system roles; leaves the tenant context set. */
    protected function makeOrg(string $name = 'Test Center'): Organization
    {
        OrganizationSetup::syncRoles();
        $org = app(OrganizationSetup::class)->createOrganization($name);
        Tenant::set($org->id);
        Branch::create(['name' => 'Main', 'status' => 'active']);

        return $org;
    }

    protected function makeUser(string $role = 'super_admin', array $attrs = []): User
    {
        static $n = 0;
        $n++;
        $user = User::create($attrs + ['name' => "User $n", 'email' => "u{$n}_".uniqid().'@test.uz', 'password' => 'Password12345', 'is_active' => true]);
        $user->roles()->attach(Role::where('slug', $role)->whereNull('organization_id')->value('id'));

        return $user->fresh();
    }

    protected function branch(): Branch
    {
        return Branch::first();
    }

    protected function makeCourse(array $a = []): \App\Models\Course
    {
        return \App\Models\Course::create($a + ['name' => 'IELTS', 'price' => 600000, 'duration_months' => 3, 'lessons_count' => 36, 'status' => 'active']);
    }

    protected function makeTeacher(array $a = []): \App\Models\Teacher
    {
        return \App\Models\Teacher::create($a + ['full_name' => 'Sardor T', 'branch_id' => $this->branch()->id, 'pay_type' => 'fixed', 'rate' => 5000000, 'status' => 'active']);
    }

    protected function makeRoom(string $name = 'Room 204'): \App\Models\Room
    {
        return \App\Models\Room::create(['name' => $name, 'branch_id' => $this->branch()->id, 'capacity' => 20]);
    }

    protected function makeGroup(array $a = []): \App\Models\Group
    {
        return \App\Models\Group::create($a + [
            'name' => 'IELTS-'.uniqid(), 'course_id' => ($a['course_id'] ?? $this->makeCourse()->id), 'branch_id' => $this->branch()->id,
            'teacher_id' => $a['teacher_id'] ?? $this->makeTeacher()->id, 'room_id' => $a['room_id'] ?? $this->makeRoom(uniqid('R'))->id,
            'max_students' => 3, 'status' => 'active', 'start_date' => today()->subMonth()->toDateString(),
        ]);
    }

    protected function makeStudent(array $a = []): \App\Models\Student
    {
        return \App\Models\Student::create($a + ['first_name' => 'Muhammad', 'last_name' => 'A', 'phone' => '+99890'.random_int(1000000, 9999999), 'branch_id' => $this->branch()->id, 'status' => 'active']);
    }
}
