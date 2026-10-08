<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Course;
use App\Models\Debt;
use App\Models\Expense;
use App\Models\Group;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Lesson;
use App\Models\Notification;
use App\Models\PaymentMethod;
use App\Models\Student;
use App\Models\User;
use App\Services\ScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** The acceptance criteria of the specification (§67), driven through the HTTP layer. */
class AcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_business_chain_lead_to_profit_over_http(): void
    {
        $this->makeOrg();
        $admin = $this->makeUser('super_admin');
        $this->actingAs($admin);

        // 3. branch  4. course  5. group
        $this->post('/branches', ['name' => 'Nurtepa', 'status' => 'active'])->assertRedirect();
        $branch = Branch::where('name', 'Nurtepa')->first();
        $this->post('/courses', ['name' => 'IELTS', 'duration_months' => 3, 'lessons_count' => 36, 'price' => 600000, 'status' => 'active', 'branches' => [$branch->id]])->assertRedirect();
        $course = Course::where('name', 'IELTS')->first();
        $this->assertSame([$branch->id], $course->branches()->pluck('branches.id')->all());
        $this->post('/rooms', ['name' => 'Room 204', 'branch_id' => $branch->id, 'capacity' => 20, 'status' => 'active']);
        $this->post('/teachers', ['full_name' => 'Sardor', 'branch_id' => $branch->id, 'pay_type' => 'fixed', 'rate' => 5000000, 'status' => 'active']);
        $teacher = \App\Models\Teacher::first();
        $room = \App\Models\Room::first();
        $this->post('/groups', ['name' => 'IELTS-17', 'course_id' => $course->id, 'branch_id' => $branch->id, 'room_id' => $room->id, 'teacher_id' => $teacher->id,
            'max_students' => 12, 'start_date' => today()->subWeek()->toDateString(), 'status' => 'active'])->assertRedirect();
        $group = Group::first();

        // 7. lead  8. convert → student (+ 9. enrolled in group)
        $manager = $this->makeUser('sales_manager');
        $this->post('/leads', ['first_name' => 'Muhammad', 'last_name' => 'Abdullayev', 'phone' => '+998 90 123 45 67', 'source_id' => LeadSource::where('name', 'Instagram')->value('id'),
            'course_id' => $course->id, 'branch_id' => $branch->id, 'manager_id' => $manager->id, 'status_id' => LeadStatus::where('slug', 'new')->value('id'),
            'parent_name' => 'Bahrom Abdullayev', 'parent_phone' => '+998901112233'])->assertRedirect();
        $lead = Lead::first();
        $this->post("/leads/{$lead->id}/status", ['status_id' => LeadStatus::where('slug', 'trial_attended')->value('id')])->assertRedirect();
        $this->post("/leads/{$lead->id}/convert", ['group_id' => $group->id])->assertRedirect();
        $student = Student::first();
        $this->assertSame($lead->id, $student->lead_id);
        $this->assertTrue($student->enrollments()->where('group_id', $group->id)->where('status', 'active')->exists());

        // 10–11. schedule + conflict detection
        $slot = ['group_id' => $group->id, 'weekdays' => [1, 3], 'start_time' => '18:00', 'end_time' => '19:30', 'start_date' => today()->subWeek()->toDateString(), 'end_date' => today()->addMonth()->toDateString()];
        $this->post('/schedule', $slot)->assertRedirect();
        $this->assertSame(2, \App\Models\Schedule::count());
        $other = $this->makeGroup(['teacher_id' => $teacher->id, 'branch_id' => $branch->id]);
        $this->post('/schedule', ['group_id' => $other->id, 'weekdays' => [1], 'start_time' => '18:30', 'end_time' => '20:00', 'start_date' => today()->toDateString()])->assertSessionHasErrors('conflict');

        // 12–13. hold a lesson and mark attendance
        $lesson = Lesson::where('group_id', $group->id)->whereDate('lesson_date', '<=', today())->orderBy('lesson_date')->first();
        $this->assertNotNull($lesson);
        $this->post("/lessons/{$lesson->id}/attendance", ['rows' => [$student->id => ['status' => 'late', 'comment' => 'пробки']]])->assertRedirect();
        $this->assertSame('held', $lesson->fresh()->status);

        // 14–15. accept payment, debt is computed automatically
        $this->post('/finance/payments', ['student_id' => $student->id, 'amount' => 400000, 'method_id' => PaymentMethod::where('code', 'payme')->value('id')])->assertRedirect();
        $this->assertEquals(200000, Debt::first()->balance);
        $this->get('/finance/debts')->assertOk()->assertSee('200 000')->assertSee('Muhammad');

        // 16–17. expense + financial result
        $this->post('/finance/expenses', ['spent_at' => today()->toDateString(), 'category_id' => \App\Models\ExpenseCategory::where('name', 'Аренда')->value('id'), 'amount' => 100000, 'description' => 'Rent'])->assertRedirect();
        $this->assertSame(1, Expense::count());
        $f = app(\App\Services\ReportService::class)->finance(now()->startOfMonth(), now()->endOfMonth());
        $this->assertEquals([400000, 100000, 300000, 200000], [$f['revenue'], $f['expenses'], $f['profit'], $f['debts']]);

        // 18. dashboard + 22. audit + 23. backup
        $this->get('/dashboard')->assertOk()->assertSee('400 000');
        $this->assertGreaterThan(10, AuditLog::count());
        $this->get('/audit')->assertOk()->assertSee('Payment');
        $this->post('/settings/backups')->assertRedirect();
        $this->assertNotEmpty(app(\App\Services\BackupService::class)->list());
    }

    public function test_global_search_language_branch_switch_and_profile(): void
    {
        $this->makeOrg();
        $second = Branch::create(['name' => 'Second', 'status' => 'active']);
        $admin = $this->makeUser('super_admin');
        $s = $this->makeStudent(['first_name' => 'Muhammad', 'last_name' => 'Karimov', 'phone' => '+998901234567', 'telegram' => '@mkarimov']);
        $group = $this->makeGroup(['name' => 'IELTS-17']);
        $lead = app(\App\Services\LeadService::class)->create(['first_name' => 'Aziz', 'phone' => '+998977777777', 'branch_id' => $this->branch()->id], null, false);

        foreach (['Muhammad', 'kari', '90 123', '+998901234567', '#'.$s->id, '@mkarimov'] as $q) {
            $res = $this->actingAs($admin)->getJson('/search/json?q='.urlencode($q))->assertOk()->json('results');
            $this->assertNotEmpty($res['students'] ?? [], "student not found by '$q'");
        }
        $this->assertNotEmpty($this->actingAs($admin)->getJson('/search/json?q=IELTS')->json('results.groups'));
        $this->assertNotEmpty($this->actingAs($admin)->getJson('/search/json?q=7777777')->json('results.leads'));
        $this->assertSame([], $this->actingAs($admin)->getJson('/search/json?q=a')->json('results'));          // too short

        // branch switcher recalculates the data scope
        $this->actingAs($admin)->post('/branch', ['branch_id' => $second->id])->assertRedirect();
        $this->assertSame([], $this->getJson('/search/json?q=Muhammad')->json('results'));       // student belongs to the other branch
        $this->post('/branch', ['branch_id' => ''])->assertRedirect();
        $this->assertNotEmpty($this->getJson('/search/json?q=Muhammad')->json('results.students'));
        $this->post('/branch', ['branch_id' => 99999])->assertStatus(422);

        // a branch-restricted user cannot switch out
        $restricted = $this->makeUser('admin', ['branch_id' => $second->id]);
        $this->actingAs($restricted)->post('/branch', ['branch_id' => ''])->assertForbidden();
        $this->assertNotContains('Muhammad', $this->getJson('/search/json?q=Muhammad')->json('results.students.*.title') ?? []);

        // language switch: Uzbek menu, persisted for the user
        $this->actingAs($admin)->get('/locale/uz')->assertRedirect();
        $this->assertSame('uz', $admin->fresh()->locale);
        $this->get('/students')->assertSee('O‘quvchilar');
        $this->get('/locale/xx')->assertNotFound();

        // profile: password change needs the current password and a strong new one
        $this->actingAs($admin)->put('/profile', ['name' => 'Boss', 'locale' => 'ru', 'current_password' => 'bad', 'password' => 'NewStrong12345', 'password_confirmation' => 'NewStrong12345'])->assertSessionHasErrors('current_password');
        $this->put('/profile', ['name' => 'Boss', 'locale' => 'ru', 'current_password' => 'Password12345', 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->put('/profile', ['name' => 'Boss', 'locale' => 'ru', 'current_password' => 'Password12345', 'password' => 'NewStrong12345', 'password_confirmation' => 'NewStrong12345'])->assertSessionHasNoErrors();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('NewStrong12345', $admin->fresh()->password));
    }

    public function test_uploads_are_validated_and_stay_private(): void
    {
        Storage::fake('local');
        $this->makeOrg();
        $admin = $this->makeUser('super_admin');
        $this->actingAs($admin);

        $jpg = UploadedFile::fake()->image('photo.jpg', 200, 200);
        $this->post('/students', ['first_name' => 'Pic', 'branch_id' => $this->branch()->id, 'photo' => $jpg])->assertRedirect();
        $student = Student::first();
        $this->assertNotNull($student->photo_path);
        $this->assertStringStartsWith('students/', $student->photo_path);
        Storage::disk('local')->assertExists($student->photo_path);
        $this->get("/files/student-photo/{$student->id}")->assertOk();

        // disguised script / wrong type / oversize are refused
        // (a real UploadedFile, so the content is sniffed with finfo exactly like in production)
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($tmp, '<?php system($_GET["c"]); ?>');
        $evil = new UploadedFile($tmp, 'shell.jpg', 'image/jpeg', null, true);
        $this->post('/students', ['first_name' => 'Evil', 'branch_id' => $this->branch()->id, 'photo' => $evil])->assertSessionHasErrors('photo');
        $this->assertThrows422(fn () => \App\Support\Upload::store($evil, 'x', 'photo'));
        $this->post('/students', ['first_name' => 'Pdf', 'branch_id' => $this->branch()->id, 'photo' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')])->assertSessionHasErrors('photo');
        $this->post('/students', ['first_name' => 'Big', 'branch_id' => $this->branch()->id, 'photo' => UploadedFile::fake()->image('big.jpg')->size(9000)])->assertSessionHasErrors('photo');
        $this->assertSame(1, Student::count());

        // receipts: pdf ok, served only to users with expenses.view
        $this->post('/finance/expenses', ['spent_at' => today()->toDateString(), 'category_id' => \App\Models\ExpenseCategory::first()->id, 'amount' => 5000,
            'file' => UploadedFile::fake()->create('check.pdf', 50, 'application/pdf')])->assertRedirect();
        $expense = Expense::first();
        $this->assertStringStartsWith('expenses/', $expense->file_path);
        $this->get("/files/expense/{$expense->id}")->assertOk();
        $this->actingAs($this->makeUser('sales_manager'))->get("/files/expense/{$expense->id}")->assertForbidden();
        auth()->logout();
        $this->get("/files/expense/{$expense->id}")->assertRedirect('/login');
        $this->assertFalse(file_exists(public_path($expense->file_path)));          // nothing in the public dir
    }

    public function test_dictionaries_users_roles_and_settings_management(): void
    {
        $this->makeOrg();
        $admin = $this->makeUser('super_admin');
        $this->actingAs($admin);

        // custom lead status + source + payment method
        $this->post('/settings/dictionaries/statuses', ['name' => 'Ждёт скидку', 'stage' => 2, 'color' => 'amber', 'sort' => 20])->assertRedirect();
        $this->assertTrue(LeadStatus::where('name', 'Ждёт скидку')->exists());
        $new = LeadStatus::where('slug', 'new')->first();
        $this->delete("/settings/dictionaries/statuses/{$new->id}")->assertSessionHasErrors('delete');       // system status is protected
        $this->post('/settings/dictionaries/sources', ['name' => 'TikTok', 'is_active' => 1])->assertRedirect();
        $this->post('/settings/dictionaries/methods', ['name' => 'Humo', 'code' => 'humo', 'is_active' => 1])->assertRedirect();
        $this->post('/settings/dictionaries/categories', ['name' => 'Налоги'])->assertRedirect();
        $this->post('/settings/dictionaries/reasons', ['name' => 'Болезнь'])->assertRedirect();

        // user + custom role
        $this->post('/roles', ['name' => 'Кассир', 'permissions' => ['payments.view', 'payments.create']])->assertRedirect();
        $role = \App\Models\Role::where('name', 'Кассир')->first();
        $this->assertSame(2, $role->permissions()->count());
        $this->post('/users', ['name' => 'Cashier', 'email' => 'cash@test.uz', 'password' => 'Strong12345!', 'roles' => [$role->id], 'is_active' => 1])->assertRedirect();
        $cashier = User::where('email', 'cash@test.uz')->first();
        $this->actingAs($cashier)->get('/finance/payments')->assertOk();
        $this->actingAs($cashier)->get('/finance/debts')->assertForbidden();
        $this->actingAs($admin)->delete("/roles/{$role->id}")->assertSessionHasErrors('delete');              // assigned role cannot be removed
        $this->delete('/users/'.$admin->id)->assertSessionHasErrors('delete');                               // cannot delete yourself

        // system roles are read-only
        $sys = \App\Models\Role::where('slug', 'director')->first();
        $this->get("/roles/{$sys->id}/edit")->assertForbidden();

        // org settings and notification templates
        $this->put('/settings', ['name' => 'New Name', 'currency' => 'UZS', 'timezone' => 'Asia/Tashkent', 'locale' => 'uz', 'tpl' => ['payment_due' => 'Qarz: {amount}']])->assertSessionHasNoErrors();
        $this->assertSame('New Name', \App\Support\Tenant::organization()?->fresh()->name ?? \App\Models\Organization::first()->name);
        $this->assertSame('Qarz: 1', app(\App\Services\NotificationService::class)->render('payment_due', ['amount' => 1]));
        $this->put('/settings', ['name' => 'X', 'currency' => 'UZS', 'timezone' => 'Mars/Phobos', 'locale' => 'ru'])->assertSessionHasErrors('timezone');
        $old = \App\Models\Organization::first()->webhook_key;
        $this->put('/settings', ['name' => 'X', 'currency' => 'UZS', 'timezone' => 'Asia/Tashkent', 'locale' => 'ru', 'regenerate_key' => 1]);
        $this->assertNotSame($old, \App\Models\Organization::first()->webhook_key);
    }

    public function test_notification_centre_and_schedule_conflict_alerts(): void
    {
        $this->makeOrg();
        $admin = $this->makeUser('super_admin');
        $teacher = $this->makeTeacher();
        $g1 = $this->makeGroup(['teacher_id' => $teacher->id]);
        $g2 = $this->makeGroup(['teacher_id' => $teacher->id]);
        // two lessons of the same teacher at the same time (e.g. created before a teacher change)
        foreach ([$g1, $g2] as $g) {
            Lesson::create(['group_id' => $g->id, 'teacher_id' => $teacher->id, 'branch_id' => $g->branch_id, 'room_id' => $g->room_id, 'lesson_date' => today()->addDay(), 'start_time' => '18:00:00', 'end_time' => '19:00:00']);
        }
        $this->assertCount(1, app(ScheduleService::class)->conflictsAhead());
        $this->artisan('lessons:extend')->assertSuccessful();
        $this->artisan('lessons:extend')->assertSuccessful();         // deduplicated
        $this->assertSame(1, Notification::where('user_id', $admin->id)->where('title', 'Конфликт расписания')->count());

        $this->actingAs($admin)->getJson('/notifications/bell')->assertOk()->assertJsonPath('items.0.title', 'Конфликт расписания');
        $this->postJson('/notifications/read')->assertOk();
        $this->assertSame(0, Notification::where('user_id', $admin->id)->whereNull('read_at')->count());
        // other users never see it
        $other = $this->makeUser('teacher');
        $this->actingAs($other)->getJson('/notifications/bell')->assertJsonCount(0, 'items');
    }

    public function test_friendly_error_pages_and_security_headers(): void
    {
        $this->makeOrg();
        $res = $this->actingAs($this->makeUser('super_admin'))->get('/no-such-page');
        $res->assertNotFound()->assertSee('404');
        $res->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $this->assertStringNotContainsString('vendor/laravel', $res->getContent());
    }

    private function assertThrows422(callable $fn): void
    {
        try {
            $fn();
            $this->fail('upload must be rejected');
        } catch (\Illuminate\Validation\ValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_second_organization_can_be_provisioned_and_is_isolated(): void
    {
        $this->makeOrg('Center A');
        $this->makeStudent(['first_name' => 'OnlyInA']);
        \App\Support\Tenant::clear();

        $this->artisan('erp:organization', ['name' => 'Center B', 'email' => 'b@center.uz', 'password' => 'Password12345'])->assertSuccessful();
        $b = User::where('email', 'b@center.uz')->first();
        $this->assertNotSame(\App\Models\Organization::orderBy('id')->first()->id, $b->organization_id);
        $this->assertTrue($b->isSuperAdmin());
        $this->actingAs($b)->get('/students')->assertOk()->assertDontSee('OnlyInA');
        $this->artisan('erp:organization', ['name' => 'Weak', 'email' => 'w@x.uz', 'password' => 'short'])->assertFailed();
    }

    public function test_csv_export_neutralises_formulas_but_keeps_phone_numbers(): void
    {
        $this->makeOrg();
        $admin = $this->makeUser('super_admin');
        $this->makeStudent(['first_name' => '=HYPERLINK("http://evil")', 'last_name' => '@cmd', 'phone' => '+998901234567']);
        $csv = $this->actingAs($admin)->get('/students?export=csv')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString('+998901234567', $csv);
        $this->assertStringNotContainsString("'+998901234567", $csv);
    }

    public function test_emails_are_stored_lowercase_so_login_is_case_insensitive(): void
    {
        $this->makeOrg();
        $u = $this->makeUser('admin', ['email' => 'Mixed.Case@Test.UZ']);
        $this->assertSame('mixed.case@test.uz', $u->email);
        $this->post('/login', ['login' => 'MIXED.case@test.uz', 'password' => 'Password12345'])->assertRedirect('/');
    }
}
