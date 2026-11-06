<?php

use App\Exceptions\BusinessRule;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\AuthenticatedOnly;
use App\Http\Middleware\GuestLocale;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        using: function () {
            // Публичное: вход, вебхуки, формы сайта, cron по HTTP (routes/public/*.php)
            Route::middleware(['web', GuestLocale::class])->group(base_path('routes/web.php'));
            foreach (glob(base_path('routes/public/*.php')) as $file) {
                Route::middleware('web')->group($file);
            }
            // После входа: модули (routes/modules/*.php); каждый маршрут объявляет perm:… или auth.only
            Route::middleware(['web', 'fluggi.auth'])->group(function () {
                require base_path('routes/app.php');
                foreach (glob(base_path('routes/modules/*.php')) as $file) {
                    require $file;
                }
            });
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Beget и другие хостинги стоят за прокси nginx — доверяем X-Forwarded-* (HTTPS, IP клиента)
        $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO);
        $middleware->append(SecurityHeaders::class);
        // Вебхуки (Telegram, Meta, формы сайта, cron) защищены своими секретами, не CSRF
        $middleware->validateCsrfTokens(except: ['hooks/*']);
        $middleware->alias([
            'fluggi.auth' => Authenticate::class,
            'perm' => RequirePermission::class,
            'auth.only' => AuthenticatedOnly::class,
        ]);
        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (BusinessRule $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage(), 'errors' => array_map(fn ($m) => [$m], $e->fields)], 422);
            }

            return back()->withInput()->withErrors($e->fields)->with('error', $e->getMessage());
        });
    })->create();
