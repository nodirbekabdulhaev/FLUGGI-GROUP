<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Menu;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const MAX_ATTEMPTS = 5;
    private const LOCK_SECONDS = 900;

    public function showLogin()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $data = $request->validate(['login' => 'required|string|max:120', 'password' => 'required|string|max:200']);
        $key = 'login:'.Str::lower($data['login']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $mins = ceil(RateLimiter::availableIn($key) / 60);
            Log::channel('auth')->warning('login locked', ['login' => $data['login'], 'ip' => $request->ip()]);
            throw ValidationException::withMessages(['login' => "Слишком много попыток входа. Повторите через {$mins} мин."]);
        }

        $login = trim($data['login']);
        $user = str_contains($login, '@')
            ? User::where('email', Str::lower($login))->first()
            : User::where('phone', User::normalizePhone($login))->first();

        // constant-ish time: always run a hash check
        $ok = Hash::check($data['password'], $user->password ?? '$2y$12$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');

        if (! $user || ! $ok || ! $user->is_active) {
            RateLimiter::hit($key, self::LOCK_SECONDS);
            Log::channel('auth')->warning('login failed', ['login' => $login, 'ip' => $request->ip()]);
            if ($user) {
                AuditLog::record('login_failed', $user, [], [], 'User');
            }
            throw ValidationException::withMessages(['login' => 'Неверный логин или пароль.']);
        }

        RateLimiter::clear($key);
        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->saveQuietly();
        AuditLog::record('login', $user);
        Log::channel('auth')->info('login', ['user' => $user->id, 'ip' => $request->ip()]);

        return redirect()->intended(route('home'));
    }

    public function logout(Request $request)
    {
        if ($user = Auth::user()) {
            AuditLog::record('logout', $user);
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function forgotForm()
    {
        return view('auth.forgot');
    }

    public function sendReset(Request $request)
    {
        $request->validate(['email' => 'required|email']);
        try {
            Password::sendResetLink($request->only('email'));
        } catch (\Throwable $e) {
            Log::error('reset mail failed: '.$e->getMessage());
        }

        // identical answer whether or not the address exists (no user enumeration)
        return back()->with('ok', 'Если такой email зарегистрирован, мы отправили ссылку для сброса пароля.');
    }

    public function resetForm(Request $request, string $token)
    {
        return view('auth.reset', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function reset(Request $request)
    {
        $data = $request->validate([
            'token' => 'required', 'email' => 'required|email',
            'password' => ['required', 'confirmed', PasswordRule::min(10)->letters()->numbers()],
        ]);

        $status = Password::reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            AuditLog::record('password_reset', $user);
        });

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('login')->with('ok', 'Пароль изменён. Войдите с новым паролем.')
            : back()->withErrors(['email' => 'Ссылка недействительна или устарела.']);
    }

    public function profile()
    {
        return view('auth.profile', ['user' => Auth::user()]);
    }

    public function updateProfile(Request $request)
    {
        $user = Auth::user();
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'locale' => 'nullable|in:ru,uz',
            'current_password' => 'nullable|required_with:password|current_password',
            'password' => ['nullable', 'confirmed', PasswordRule::min(10)->letters()->numbers()],
        ]);

        $user->name = $data['name'];
        $user->locale = $data['locale'] ?? $user->locale;
        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }
        $user->save();

        return back()->with('ok', __('Сохранено'));
    }
}
