<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BackupAndApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_backup_roundtrip_restores_deleted_data_and_rotates_old_dumps(): void
    {
        $this->makeOrg();
        $this->makeStudent(['first_name' => "O'Brien \"quoted\"; DROP TABLE x; -- \n multi\nline", 'notes' => "line1\nline2 ;--EOS-less"]);
        $svc = app(BackupService::class);
        File::cleanDirectory($svc->dir());

        $name = $svc->run(2);
        $this->assertFileExists($svc->path($name));

        \Illuminate\Support\Facades\DB::table('students')->delete();
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('students')->count());

        $svc->restore($name);
        $s = \Illuminate\Support\Facades\DB::table('students')->first();
        $this->assertSame("O'Brien \"quoted\"; DROP TABLE x; -- \n multi\nline", $s->first_name);
        $this->assertSame("line1\nline2 ;--EOS-less", $s->notes);
        $this->assertGreaterThan(0, \Illuminate\Support\Facades\DB::table('lead_sources')->count());

        // rotation keeps only the newest N
        foreach (range(1, 3) as $i) {
            touch($svc->dir().'/backup_2020-01-0'.$i.'_000000.sql.gz', 1000 + $i);
        }
        $svc->run(2);
        $this->assertCount(2, $svc->list());
    }

    public function test_backup_names_cannot_traverse_directories(): void
    {
        $this->makeOrg();
        $admin = $this->makeUser('super_admin');
        $this->actingAs($admin)->get('/settings/backups/..%2F..%2F.env')->assertNotFound();
        $this->actingAs($admin)->get('/settings/backups/passwd')->assertNotFound();
        $this->actingAs($admin)->post('/settings/backups/..%2Fx.sql.gz/restore', ['confirm' => 'RESTORE'])->assertNotFound();
    }

    public function test_backup_command_and_ui_permissions(): void
    {
        $this->makeOrg();
        $this->artisan('backup:run')->assertSuccessful();
        $director = $this->makeUser('director');
        $this->actingAs($director)->post('/settings/backups')->assertForbidden();
        $this->actingAs($this->makeUser('super_admin'))->post('/settings/backups')->assertRedirect();
        // restore needs the typed confirmation
        $name = app(BackupService::class)->list()[0]['name'];
        $this->actingAs($this->makeUser('super_admin'))->post("/settings/backups/$name/restore", [])->assertSessionHasErrors('confirm');
    }

    public function test_api_token_flow_and_permission_checks(): void
    {
        $this->makeOrg();
        $this->makeUser('admin', ['email' => 'api@test.uz']);
        $this->makeStudent(['first_name' => 'Api']);

        $this->postJson('/api/v1/auth/token', ['login' => 'api@test.uz', 'password' => 'wrong'])->assertStatus(422);
        $token = $this->postJson('/api/v1/auth/token', ['login' => 'api@test.uz', 'password' => 'Password12345'])->assertOk()->json('token');

        $this->withToken($token)->getJson('/api/v1/students')->assertOk()->assertJsonPath('data.0.first_name', 'Api');
        $id = Student::first()->id;
        $this->withToken($token)->getJson("/api/v1/students/$id")->assertOk()->assertJsonPath('data.balance', 0);
        $this->withToken($token)->postJson('/api/v1/students', ['first_name' => 'New', 'branch_id' => $this->branch()->id])->assertCreated();
        $this->withToken($token)->getJson('/api/v1/groups')->assertOk();
        $this->withToken($token)->getJson('/api/v1/lessons')->assertOk();
        $this->withToken($token)->postJson('/api/v1/students', ['first_name' => ''])->assertStatus(422);
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/students')->assertUnauthorized();
    }

    public function test_api_payment_and_lead_creation_respect_permissions(): void
    {
        $this->makeOrg();
        $student = $this->makeStudent();
        $method = \App\Models\PaymentMethod::first()->id;

        $sales = $this->makeUser('sales_manager');
        Sanctum::actingAs($sales, ['*']);
        $this->postJson('/api/v1/payments', ['student_id' => $student->id, 'amount' => 1000, 'method_id' => $method])->assertForbidden();
        $this->postJson('/api/v1/leads', ['first_name' => 'L', 'phone' => '+998901112233', 'branch_id' => $this->branch()->id])->assertCreated();
        $this->getJson('/api/v1/leads')->assertOk()->assertJsonCount(1, 'data');

        $acc = $this->makeUser('accountant');
        Sanctum::actingAs($acc, ['*']);
        $this->postJson('/api/v1/payments', ['student_id' => $student->id, 'amount' => 1000, 'method_id' => $method])->assertCreated();
        $this->getJson('/api/v1/leads')->assertForbidden();
        $this->postJson('/api/v1/payments', ['student_id' => 99999, 'amount' => 1000, 'method_id' => $method])->assertStatus(422);
    }
}
