<?php

namespace App\Http\Controllers;

use App\Auth\Passwords;
use App\Auth\Sessions;
use App\Http\Middleware\Authenticate;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    public const MAX_FAILED_LOGINS = 5;

    public const LOCK_MINUTES = 15;

    public function form(Request $request): View|RedirectResponse
    {
        if (is_string($token = $request->session()->get(Authenticate::TOKEN_KEY)) && Sessions::resolve($token)) {
            return redirect()->route('dashboard');
        }

        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:191'],
            'password' => ['required', 'string', 'max:200'],
        ]);
        $email = mb_strtolower(trim($data['email']));

        // Ограничение по IP: 20 попыток в 5 минут
        $ipKey = 'login:'.$request->ip();
        if (RateLimiter::tooManyAttempts($ipKey, 20)) {
            throw ValidationException::withMessages(['email' => t('auth.tooManyAttempts', ['minutes' => 5])]);
        }
        RateLimiter::hit($ipKey, 300);

        $user = User::where('email', $email)->first();
        if ($user?->locked_until && $user->locked_until->isFuture()) {
            throw ValidationException::withMessages(['email' => t('auth.locked', ['minutes' => self::LOCK_MINUTES])]);
        }

        if (! $user || ! Passwords::verify($user?->password_hash, $data['password'])) {
            if ($user) {
                DB::transaction(function () use ($user) {
                    $failed = $user->failed_login_count + 1;
                    $lock = $failed >= self::MAX_FAILED_LOGINS;
                    $user->update([
                        'failed_login_count' => $lock ? 0 : $failed,
                        'locked_until' => $lock ? now()->addMinutes(self::LOCK_MINUTES) : $user->locked_until,
                    ]);
                    Audit::log($lock ? 'auth.locked' : 'auth.login_failed', 'user', $user->id, actorId: $user->id);
                });
            }
            throw ValidationException::withMessages(['email' => t('auth.invalidCredentials')]);
        }
        if ($user->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['email' => t('auth.blocked')]);
        }

        $created = DB::transaction(function () use ($user, $request) {
            $user->update(['failed_login_count' => 0, 'locked_until' => null, 'last_login_at' => now()]);
            $created = Sessions::create($user, $request->ip(), $request->userAgent());
            Audit::log('auth.login', 'user', $user->id, ['sessionId' => ['old' => null, 'new' => $created['session']->id]], $user->id);

            return $created;
        });
        RateLimiter::clear($ipKey);
        // Защита от фиксации сессии
        $request->session()->regenerate();
        $request->session()->put(Authenticate::TOKEN_KEY, $created['token']);

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        $access = access();
        DB::transaction(function () use ($access) {
            Sessions::revoke($access->sessionId);
            Audit::log('auth.logout', 'user', $access->id());
        });
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
