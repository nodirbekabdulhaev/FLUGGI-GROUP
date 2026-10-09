<?php

namespace App\Http\Controllers;

use App\Auth\Passwords;
use App\Auth\Sessions;
use App\Models\Session;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function show(): View
    {
        $access = access();
        $sessions = Session::where('user_id', $access->id())->whereNull('revoked_at')
            ->where('expires_at', '>', now())->orderByDesc('last_seen_at')->get();

        return view('profile.show', ['user' => $access->user, 'access' => $access, 'sessions' => $sessions]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'full_name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'locale' => ['required', Rule::in(['uz', 'ru'])],
        ]);
        $user = access()->user;
        DB::transaction(function () use ($user, $data) {
            $changes = Audit::diff($user, $data, ['full_name', 'phone', 'locale']);
            $user->update($data);
            if ($changes) {
                Audit::log('user.profile_updated', 'user', $user->id, $changes);
            }
        });
        app()->setLocale($data['locale']);

        return back()->with('ok', t('profile.saved'));
    }

    /** Быстрое переключение языка из шапки. */
    public function locale(Request $request): RedirectResponse
    {
        $locale = $request->validate(['locale' => ['required', Rule::in(['uz', 'ru'])]])['locale'];
        access()->user->update(['locale' => $locale]);

        return back();
    }

    public function password(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:10', 'max:200', 'regex:/[A-Za-zА-Яа-я]/u', 'regex:/\d/', 'confirmed', 'different:current_password'],
        ]);
        $access = access();
        $user = $access->user;
        if (! Passwords::verify($user->password_hash, $data['current_password'])) {
            throw ValidationException::withMessages(['current_password' => t('profile.wrongPassword')]);
        }
        DB::transaction(function () use ($user, $data, $access) {
            $user->update(['password_hash' => Passwords::hash($data['new_password'])]);
            // Остальные устройства разлогиниваются, текущая сессия сохраняется
            Sessions::revokeAllForUser($user->id, $access->sessionId);
            Audit::log('auth.password_changed', 'user', $user->id);
        });

        return back()->with('ok', t('profile.passwordChanged'));
    }

    public function revokeSession(string $id): RedirectResponse
    {
        $access = access();
        $count = Session::where('id', $id)->where('user_id', $access->id())->whereNull('revoked_at')->update(['revoked_at' => now()]);
        abort_if($count === 0, 404);
        Audit::log('auth.session_revoked', 'session', $id);

        return back()->with('ok', t('profile.sessionRevoked'));
    }
}
