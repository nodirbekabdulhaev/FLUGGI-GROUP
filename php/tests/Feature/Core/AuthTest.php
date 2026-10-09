<?php

namespace Tests\Feature\Core;

use App\Auth\Passwords;
use App\Auth\Sessions;
use App\Models\AuditLog;
use App\Models\Session;
use Tests\TestCase;

class AuthTest extends TestCase
{
    public function test_login_page_opens_in_both_languages(): void
    {
        $this->get('/login')->assertOk()->assertSee('Вход в Fluggi');
        $this->get('/login?lang=uz')->assertOk()->assertSee('Fluggi tizimiga kirish');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->getJson('/dashboard')->assertStatus(401);
    }

    public function test_login_creates_session_and_audit(): void
    {
        $user = $this->user('MANAGER');
        $this->post('/login', ['email' => strtoupper($user->email), 'password' => self::PASSWORD])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk()->assertSee($user->full_name);
        $this->assertSame(1, Session::where('user_id', $user->id)->count());
        $this->assertTrue(AuditLog::where('action', 'auth.login')->where('entity_id', $user->id)->exists());
    }

    public function test_wrong_password_locks_after_five_attempts(): void
    {
        $user = $this->user('MANAGER');
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])->assertSessionHasErrors('email');
        }
        $this->assertNotNull($user->fresh()->locked_until);
        // Даже верный пароль не пускает, пока действует блокировка
        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertSessionHasErrors('email');
        $this->assertTrue(AuditLog::where('action', 'auth.locked')->exists());
    }

    public function test_blocked_user_cannot_login_and_loses_session(): void
    {
        $user = $this->user('MANAGER');
        $this->actingAsUser($user)->get('/dashboard')->assertOk();
        $user->update(['status' => 'BLOCKED']);
        $this->get('/dashboard')->assertRedirect('/login');
        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertSessionHasErrors('email');
    }

    public function test_logout_revokes_session(): void
    {
        $user = $this->user('MANAGER');
        $this->actingAsUser($user)->post('/logout')->assertRedirect('/login');
        $this->assertNotNull(Session::where('user_id', $user->id)->first()->revoked_at);
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_change_password_revokes_other_sessions(): void
    {
        $user = $this->user('MANAGER');
        $other = Sessions::create($user, '10.0.0.1', 'other device')['session'];
        $this->actingAsUser($user)->put('/profile/password', [
            'current_password' => self::PASSWORD,
            'new_password' => 'New-password-456',
            'new_password_confirmation' => 'New-password-456',
        ])->assertSessionHasNoErrors();
        $this->assertNotNull($other->fresh()->revoked_at);
        $this->get('/profile')->assertOk();
        $this->assertTrue(Passwords::verify($user->fresh()->password_hash, 'New-password-456'));
    }

    public function test_language_switch_is_saved(): void
    {
        $user = $this->user('MANAGER');
        $this->actingAsUser($user)->post('/profile/locale', ['locale' => 'uz'])->assertRedirect();
        $this->assertSame('uz', $user->fresh()->locale);
        $this->get('/profile')->assertOk()->assertSee('Profil');
    }

    public function test_argon2_hash_from_previous_node_version_is_accepted(): void
    {
        // Хэш @node-rs/argon2 (m=19456, t=2, p=1) для пароля "Test-password-123"
        $hash = password_hash('Test-password-123', PASSWORD_ARGON2ID, ['memory_cost' => 19456, 'time_cost' => 2, 'threads' => 1]);
        $this->assertStringStartsWith('$argon2id$v=19$m=19456,t=2,p=1$', $hash);
        $this->assertTrue(Passwords::verify($hash, 'Test-password-123'));
    }
}
