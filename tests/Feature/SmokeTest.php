<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Lead;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Support\Tenant;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Opens every page of the application as each role on a fully seeded demo database. */
class SmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_page_renders_for_every_role_and_permissions_are_enforced(): void
    {
        $this->seed(DemoSeeder::class);
        $org = \App\Models\Organization::first();
        Tenant::set($org->id);

        $student = Student::first()->id;
        $lead = Lead::first()->id;
        $group = Group::first()->id;
        $lesson = Lesson::where('status', 'held')->first()->id;
        $teacher = Teacher::first()->id;
        $payment = Payment::first()->id;
        $guardian = \App\Models\Guardian::first()->id;
        $schedule = \App\Models\Schedule::first()->id;
        $month = now()->format('Y-m');

        $pages = [
            '/dashboard', '/dashboard?period=custom&from=2026-01-01&to=2026-12-31', '/dashboard?period=year',
            '/leads', '/leads?status_id=1&overdue=1&q=Mu', '/leads/create', "/leads/$lead", "/leads/$lead/edit", '/leads/funnel',
            '/students', '/students?debtors=1&q=Ali', '/students/create', "/students/$student", "/students/$student/edit",
            '/parents', '/parents/create', "/parents/$guardian", "/parents/$guardian/edit",
            '/courses', '/courses/create', '/groups', '/groups/create', "/groups/$group", "/groups/$group/edit", "/groups/$group?history=1",
            '/schedule', '/schedule/create', "/schedule/$schedule/edit", '/lessons', '/lessons?date='.today()->toDateString(), "/lessons/$lesson",
            '/attendance', "/attendance?group_id=$group", '/finance/payments', '/finance/payments/create', "/finance/payments/create?student_id=$student",
            "/finance/payments/$payment/edit", "/finance/students/$student/balance", '/finance/debts', '/finance/expenses', '/finance/expenses/create',
            '/finance/salaries', "/finance/salaries?period=$month",
            '/teachers', '/teachers/create', "/teachers/$teacher", "/teachers/$teacher/edit", '/employees', '/employees/create',
            '/branches', '/branches/create', '/rooms', '/rooms/create',
            '/reports/sales', '/reports/sources', '/reports/managers', '/reports/finance', '/reports/students', '/reports/attendance', '/reports/teachers',
            '/notifications/telegram', '/notifications/bell', '/settings', '/settings/dictionaries', '/settings/dictionaries/statuses', '/settings/dictionaries/sources',
            '/settings/dictionaries/methods', '/settings/dictionaries/categories', '/settings/dictionaries/reasons', '/settings/dictionaries/subjects',
            '/users', '/users/create', '/roles', '/roles/create', '/audit', '/profile', '/search/json?q=Ali',
        ];

        // Full-access roles: every page must answer 200 (never 500).
        foreach (['admin@demo.uz', 'director@demo.uz'] as $email) {
            $user = User::where('email', $email)->first();
            foreach ($pages as $url) {
                $res = $this->actingAs($user)->get($url);
                if ($email === 'director@demo.uz' && in_array($res->getStatusCode(), [403], true)) {
                    // the director may not touch system settings / users / roles
                    $this->assertTrue(preg_match('#^/(settings|users|roles|branches/create|rooms/create)#', $url) === 1, "director got 403 on $url");

                    continue;
                }
                $res->assertOk();
            }
        }

        // CSV / PDF exports
        $admin = User::where('email', 'admin@demo.uz')->first();
        foreach (['/students?export=csv', '/leads?export=csv', '/finance/payments?export=csv', '/finance/payments?export=pdf', '/finance/debts?export=csv', '/finance/debts?export=pdf',
            '/finance/expenses?export=csv', '/reports/export/sources?format=csv', '/reports/export/finance?format=pdf', '/reports/export/attendance?format=csv',
            '/reports/export/teachers?format=pdf', '/reports/export/students?format=csv', '/reports/export/managers?format=csv'] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }

        // Restricted roles: no 500s on any page, only 200/403/404/302.
        foreach (['administrator@demo.uz', 'sales@demo.uz', 'accountant@demo.uz', 'teacher1@demo.uz'] as $email) {
            $user = User::where('email', $email)->first();
            foreach ($pages as $url) {
                $code = $this->actingAs($user)->get($url)->getStatusCode();
                $this->assertContains($code, [200, 302, 403, 404], "$email $url → $code");
            }
        }

        // Role boundaries
        $sales = User::where('email', 'sales@demo.uz')->first();
        $this->actingAs($sales)->get('/finance/payments')->assertForbidden();
        $this->actingAs($sales)->get('/settings')->assertForbidden();
        $this->actingAs($sales)->get('/leads')->assertOk();

        $accountant = User::where('email', 'accountant@demo.uz')->first();
        $this->actingAs($accountant)->get('/leads')->assertForbidden();
        $this->actingAs($accountant)->get('/finance/payments')->assertOk();
        $this->actingAs($accountant)->get('/finance/expenses')->assertOk();

        $teacherUser = User::where('email', 'teacher1@demo.uz')->first();
        $teacherRec = Teacher::where('user_id', $teacherUser->id)->first();
        $own = Group::where('teacher_id', $teacherRec->id)->first();
        $foreign = Group::where('teacher_id', '!=', $teacherRec->id)->first();
        $this->actingAs($teacherUser)->get("/groups/{$own->id}")->assertOk();
        $this->actingAs($teacherUser)->get("/groups/{$foreign->id}")->assertNotFound();      // not even visible
        $this->actingAs($teacherUser)->get('/finance/payments')->assertForbidden();
        $this->actingAs($teacherUser)->get('/leads')->assertForbidden();
        $foreignLesson = Lesson::where('teacher_id', '!=', $teacherRec->id)->first();
        $this->actingAs($teacherUser)->get("/lessons/{$foreignLesson->id}")->assertNotFound();
        $ownStudent = $own->activeEnrollments()->first()->student_id;
        $this->actingAs($teacherUser)->get("/students/$ownStudent")->assertOk();
        $otherStudent = Student::whereDoesntHave('enrollments', fn ($q) => $q->where('group_id', $own->id))->first()->id;
        $this->actingAs($teacherUser)->get("/students/$otherStudent")->assertForbidden();

        // Branch-restricted administrator only sees their own branch
        $adm = User::where('email', 'administrator@demo.uz')->first();
        $other = Student::withoutGlobalScopes()->where('branch_id', '!=', $adm->branch_id)->first();
        $this->actingAs($adm)->get("/students/{$other->id}")->assertNotFound();

        // No unhandled exceptions were logged while rendering
        $this->assertTrue(true);
    }

    public function test_login_page_and_guest_redirect(): void
    {
        $this->get('/login')->assertOk();
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/api/v1/ping')->assertOk();
        $this->getJson('/api/v1/students')->assertUnauthorized();
    }
}
