<?php

namespace Tests\Feature\People;

use App\Auth\Passwords;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\DealStage;
use App\Models\OutboxEvent;
use App\Models\Role;
use App\Models\Session;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Projects\ProjectFixtures;
use Tests\TestCase;

/** Сотрудники, роли и права, журнал аудита, настройки (users/roles.int.test.ts). */
class UsersTest extends TestCase
{
    use ProjectFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPeople();
    }

    public function test_ceo_creates_manager_who_logs_in_with_temporary_password(): void
    {
        $r = $this->actingAsUser($this->ceo)->post('/team/employees', ['email' => 'Asror@Fluggi.uz', 'full_name' => 'Asror', 'role_code' => 'MANAGER', 'team_id' => $this->team1->id]);
        $r->assertSessionHasNoErrors()->assertSessionHas('tempPassword');
        $password = session('tempPassword')['password'];
        $this->assertGreaterThanOrEqual(14, strlen($password));
        $u = User::where('email', 'asror@fluggi.uz')->firstOrFail();
        $this->assertSame($this->team1->id, $u->team_id);
        $this->assertTrue(Passwords::verify($u->password_hash, $password));
        $this->assertSame($this->ceo->id, AuditLog::where('action', 'user.create')->first()->actor_id);
        $this->assertSame(1, OutboxEvent::where('type', 'user.created')->count());
        $this->get('/team/employees')->assertOk()->assertSee($password);
        // Дубль email
        $this->post('/team/employees', ['email' => 'asror@fluggi.uz', 'full_name' => 'Dup', 'role_code' => 'MANAGER'])->assertSessionHas('error');
    }

    public function test_visibility_and_manage_permissions(): void
    {
        $this->actingAsUser($this->manager)->get('/team/employees')->assertForbidden();
        $this->post('/team/employees', ['email' => 'x@test.uz', 'full_name' => 'X', 'role_code' => 'MANAGER'])->assertForbidden();
        $this->actingAsUser($this->executor)->get('/team/employees')->assertForbidden();
        // РОП видит только свой отдел
        $this->actingAsUser($this->rop)->get('/team/employees')->assertOk()->assertSee('Manager One')->assertDontSee('Manager Two');
        $this->get('/team/managers')->assertOk()->assertDontSee('Executor One');
        // HR создаёт сотрудников, но не CEO и не меняет CEO
        $hr = $this->user('HR_ADMIN');
        $this->actingAsUser($hr)->post('/team/employees', ['email' => 'd@test.uz', 'full_name' => 'Designer', 'role_code' => 'EXECUTOR', 'specialty' => 'DESIGNER'])->assertSessionHasNoErrors();
        $this->assertSame('DESIGNER', User::where('email', 'd@test.uz')->first()->employee->specialty);
        $this->post('/team/employees', ['email' => 'c@test.uz', 'full_name' => 'CEO 2', 'role_code' => 'CEO'])->assertForbidden();
        $this->patch("/team/employees/{$this->ceo->id}", ['full_name' => 'Hacked', 'email' => $this->ceo->email, 'role_code' => 'CEO'])->assertForbidden();
        // Роли и аудит — только своим ролям
        $this->actingAsUser($this->rop)->get('/settings/roles')->assertForbidden();
        $this->get('/settings/audit')->assertForbidden();
        $this->actingAsUser($hr)->get('/settings/audit')->assertOk();
        $this->get('/settings/roles')->assertForbidden();
        foreach (['ru', 'uz'] as $locale) {
            $this->ceo->update(['locale' => $locale]);
            $c = $this->actingAsUser($this->ceo);
            foreach (['/team/employees', '/team/managers', '/team/rop', '/team/executors', '/settings', '/settings/roles', '/settings/audit', '/settings/schedules', '/settings/templates', '/settings/finance',
                "/team/employees?edit={$this->manager->id}", "/team/employees?delete={$this->manager->id}"] as $url) {
                $c->get($url)->assertOk();
            }
        }
        $this->get('/team')->assertRedirect('/team/employees');
        $this->get('/settings')->assertSee('/settings/roles');
        $this->actingAsUser($this->executor)->get('/settings')->assertForbidden();
    }

    public function test_block_rules_role_change_and_audit_diff(): void
    {
        $c = $this->actingAsUser($this->ceo);
        $c->post("/team/employees/{$this->manager->id}/block")->assertSessionHasNoErrors();
        $this->assertSame('BLOCKED', $this->manager->fresh()->status);
        $this->assertTrue(OutboxEvent::where('type', 'user.blocked')->exists());
        $c->post("/team/employees/{$this->ceo->id}/block")->assertSessionHas('error');
        $c->post("/team/employees/{$this->manager->id}/unblock");
        $this->assertSame('ACTIVE', $this->manager->fresh()->status);
        // РОП руководит отделом — роль не сменить
        $c->patch("/team/employees/{$this->rop->id}", ['email' => $this->rop->email, 'full_name' => $this->rop->full_name, 'role_code' => 'MANAGER'])->assertSessionHas('error');
        $c->patch("/team/employees/{$this->manager->id}", ['email' => $this->manager->email, 'full_name' => $this->manager->full_name, 'role_code' => 'MANAGER', 'team_id' => $this->team2->id])->assertSessionHasNoErrors();
        $this->assertEquals(['teamId' => ['old' => $this->team1->id, 'new' => $this->team2->id]], AuditLog::where('action', 'user.update')->first()->changes);
        // Смена роли закрывает сессии
        $s = \App\Auth\Sessions::create($this->otherManager, '1.1.1.1', 'x')['session'];
        $c->patch("/team/employees/{$this->otherManager->id}", ['email' => $this->otherManager->email, 'full_name' => 'Manager Two', 'role_code' => 'EXECUTOR', 'specialty' => 'SMM'])->assertSessionHasNoErrors();
        $this->assertNotNull($s->fresh()->revoked_at);
        $this->assertTrue(OutboxEvent::where('type', 'user.role_changed')->exists());
        // Сброс пароля
        $c->post("/team/employees/{$this->manager->id}/reset-password")->assertSessionHas('tempPassword');
        $this->assertTrue(Passwords::verify($this->manager->fresh()->password_hash, session('tempPassword')['password']));
        $this->assertTrue(AuditLog::where('action', 'user.reset_password')->exists());
    }

    public function test_delete_transfers_open_work_and_frees_email(): void
    {
        Lead::create(['title' => 'Уходящий лид', 'source_id' => LeadSource::first()->id, 'owner_id' => $this->manager->id, 'team_id' => $this->team1->id,
            'stage_id' => DealStage::where('code', 'NEW')->value('id'), 'created_by_id' => $this->manager->id]);
        $c = $this->actingAsUser($this->ceo);
        $c->get("/team/employees?delete={$this->manager->id}")->assertOk()->assertSee(t('employees.work.leads'));
        $c->delete("/team/employees/{$this->manager->id}")->assertSessionHasErrors('transfer_to_id');
        $c->delete("/team/employees/{$this->ceo->id}")->assertSessionHas('error');
        $c->delete("/team/employees/{$this->rop->id}", ['transfer_to_id' => $this->ceo->id])->assertSessionHas('error');
        $this->actingAsUser($this->rop)->delete("/team/employees/{$this->otherManager->id}")->assertForbidden();
        $this->actingAsUser($this->ceo)->delete("/team/employees/{$this->manager->id}", ['transfer_to_id' => $this->otherManager->id])->assertSessionHasNoErrors()->assertSessionMissing('error')->assertRedirect();
        $this->assertSame(1, Lead::where('owner_id', $this->otherManager->id)->count());
        $this->assertNull(User::find($this->manager->id));
        $this->post('/login', ['email' => $this->manager->email, 'password' => self::PASSWORD])->assertSessionHasErrors('email');
        $this->actingAsUser($this->ceo)->post('/team/employees', ['email' => $this->manager->email, 'full_name' => 'Новый менеджер', 'role_code' => 'MANAGER'])->assertSessionHasNoErrors();
        $this->assertSame(1, AuditLog::where('action', 'user.delete')->count());
    }

    public function test_role_permissions_apply_immediately_and_ceo_is_locked(): void
    {
        $this->actingAsUser($this->manager)->get('/team/employees')->assertForbidden();
        $managerRole = Role::where('code', 'MANAGER')->first();
        $granted = Permissions::defaults()['MANAGER'] + ['employee.read' => 'OWN'];
        $this->actingAsUser($this->ceo)->put("/settings/roles/{$managerRole->id}", ['permissions' => $granted])->assertSessionHasNoErrors();
        $this->actingAsUser($this->manager)->get('/team/employees')->assertOk()->assertSee('Manager One')->assertDontSee('Rop One');
        $this->actingAsUser($this->ceo)->put("/settings/roles/{$managerRole->id}", ['permissions' => Permissions::defaults()['MANAGER']]);
        $this->actingAsUser($this->manager)->get('/team/employees')->assertForbidden();
        $this->assertEquals([['employee.read' => ['old' => null, 'new' => 'OWN']], ['employee.read' => ['old' => 'OWN', 'new' => null]]],
            AuditLog::where('action', 'role.permissions_update')->orderBy('created_at')->get()->pluck('changes')->all());
        $ceoRole = Role::where('code', 'CEO')->first();
        $this->actingAsUser($this->ceo)->put("/settings/roles/{$ceoRole->id}", ['permissions' => ['lead.read' => 'ALL']])->assertSessionHas('error');
        $this->assertSame('ALL', DB::table('role_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')->where('rp.role_id', $ceoRole->id)->where('p.code', 'role.manage')->value('scope'));
    }

    public function test_audit_log_is_append_only_and_listed(): void
    {
        $this->actingAsUser($this->ceo)->post("/team/employees/{$this->manager->id}/block");
        $this->get('/settings/audit')->assertOk()->assertSee(t('audit.user.block'));
        // Записи журнала только добавляются: страница не даёт их менять (методов изменения нет)
        $this->assertSame(['GET', 'HEAD'], collect(\Illuminate\Support\Facades\Route::getRoutes())->first(fn ($r) => $r->uri() === 'settings/audit')->methods());
        $this->actingAsUser($this->rop)->get('/settings/audit')->assertForbidden();
    }
}
