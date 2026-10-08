<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_by_email_and_by_phone_then_logout(): void
    {
        $this->makeOrg();
        $u = $this->makeUser('director', ['email' => 'boss@test.uz', 'phone' => '+998 90 123-45-67']);

        $this->post('/login', ['login' => 'boss@test.uz', 'password' => 'Password12345'])->assertRedirect('/');
        $this->assertAuthenticatedAs($u);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();

        // any phone formatting resolves to the same user
        $this->post('/login', ['login' => '90 123 45 67', 'password' => 'Password12345'])->assertRedirect('/');
        $this->assertAuthenticatedAs($u);
        $this->assertTrue(AuditLog::where('event', 'login')->exists());
    }

    public function test_wrong_password_inactive_user_and_lockout(): void
    {
        $this->makeOrg();
        $u = $this->makeUser('admin', ['email' => 'a@test.uz']);

        $this->from('/login')->post('/login', ['login' => 'a@test.uz', 'password' => 'nope'])->assertSessionHasErrors('login');
        $this->assertGuest();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['login' => 'a@test.uz', 'password' => 'bad'.$i]);
        }
        // locked: even the right password is refused now
        $this->post('/login', ['login' => 'a@test.uz', 'password' => 'Password12345'])->assertSessionHasErrors('login');
        $this->assertGuest();
        RateLimiter::clear('login:a@test.uz|127.0.0.1');

        $u->update(['is_active' => false]);
        $this->post('/login', ['login' => 'a@test.uz', 'password' => 'Password12345'])->assertSessionHasErrors('login');
        $this->assertGuest();
    }

    public function test_deactivated_user_with_open_session_is_kicked_out(): void
    {
        $this->makeOrg();
        $u = $this->makeUser('admin');
        $this->actingAs($u)->get('/students')->assertOk();
        $u->update(['is_active' => false]);
        $this->actingAs($u->fresh())->get('/students')->assertRedirect('/login');
    }

    public function test_forgot_password_never_reveals_whether_email_exists(): void
    {
        $this->makeOrg();
        $this->makeUser('admin', ['email' => 'known@test.uz']);
        $a = $this->post('/forgot-password', ['email' => 'known@test.uz'])->assertSessionHas('ok')->getSession()->get('ok');
        $b = $this->post('/forgot-password', ['email' => 'unknown@test.uz'])->assertSessionHas('ok')->getSession()->get('ok');
        $this->assertSame($a, $b);
    }

    public function test_password_reset_with_token(): void
    {
        $this->makeOrg();
        $u = $this->makeUser('admin', ['email' => 'r@test.uz']);
        $token = \Illuminate\Support\Facades\Password::createToken($u);
        $this->post('/reset-password', ['token' => $token, 'email' => 'r@test.uz', 'password' => 'weak', 'password_confirmation' => 'weak'])->assertSessionHasErrors('password');
        $this->post('/reset-password', ['token' => $token, 'email' => 'r@test.uz', 'password' => 'NewStrong12345', 'password_confirmation' => 'NewStrong12345'])->assertRedirect('/login');
        $this->post('/login', ['login' => 'r@test.uz', 'password' => 'NewStrong12345'])->assertRedirect('/');
    }

    public function test_permissions_are_enforced_and_passwords_are_hashed(): void
    {
        $this->makeOrg();
        $sales = $this->makeUser('sales_manager');
        $this->assertNotSame('Password12345', $sales->password);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('Password12345', $sales->password));

        $this->actingAs($sales)->get('/finance/payments')->assertForbidden();
        $this->actingAs($sales)->get('/users')->assertForbidden();
        $this->actingAs($sales)->get('/leads')->assertOk();

        $teacher = $this->makeUser('teacher');
        $this->actingAs($teacher)->get('/students')->assertForbidden();
        $this->actingAs($teacher)->post('/leads', [])->assertForbidden();
    }

    public function test_csrf_is_required_for_state_changing_requests(): void
    {
        $this->makeOrg();
        $u = $this->makeUser('admin');
        $this->app['env'] = 'local';            // Laravel skips CSRF only while running in the "testing" environment
        $this->actingAs($u)->post('/logout')->assertStatus(419);
        $this->post('/login', ['login' => 'a', 'password' => 'b'])->assertStatus(419);
    }

    public function test_user_cannot_hand_out_more_power_than_they_have(): void
    {
        $this->makeOrg();
        $mgr = $this->makeUser('director');      // has no users.manage → blocked entirely
        $this->actingAs($mgr)->get('/users/create')->assertForbidden();

        $custom = \App\Models\Role::create(['organization_id' => \App\Support\Tenant::id(), 'name' => 'HR', 'slug' => 'hr', 'is_system' => false]);
        $custom->permissions()->sync(\App\Models\Permission::whereIn('slug', ['users.manage', 'students.view'])->pluck('id'));
        $hr = $this->makeUser('admin');
        $hr->roles()->sync([$custom->id]);

        $superRole = \App\Models\Role::where('slug', 'super_admin')->value('id');
        $res = $this->actingAs($hr)->post('/users', ['name' => 'X', 'email' => 'x@test.uz', 'password' => 'Password12345', 'roles' => [$superRole], 'is_active' => 1]);
        $res->assertSessionHasErrors('roles');
        $this->assertFalse(User::where('email', 'x@test.uz')->exists());
    }
}
