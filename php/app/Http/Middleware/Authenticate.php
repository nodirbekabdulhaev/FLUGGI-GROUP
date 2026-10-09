<?php

namespace App\Http\Middleware;

use App\Auth\Access;
use App\Auth\Sessions;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Вход обязателен: проверяет сессию в базе и кладёт права пользователя в контейнер. */
class Authenticate
{
    public const TOKEN_KEY = 'fluggi_token';

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->session()->get(self::TOKEN_KEY);
        $access = is_string($token) ? Sessions::resolve($token) : null;
        if ($access === null) {
            $request->session()->forget(self::TOKEN_KEY);
            if ($request->expectsJson()) {
                return response()->json(['message' => t('auth.sessionExpired')], 401);
            }

            return redirect()->guest(route('login'));
        }
        app()->instance(Access::class, $access);
        Auth::setUser($access->user);
        app()->setLocale(in_array($access->user->locale, ['uz', 'ru'], true) ? $access->user->locale : 'ru');

        return $next($request);
    }
}
