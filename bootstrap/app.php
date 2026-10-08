<?php

use App\Http\Middleware\SetTenant;
use App\Http\Middleware\WebhookAuth;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);     // global: also covers error pages
        $middleware->web(append: [SetTenant::class, \App\Http\Middleware\DemoGuard::class]);
        // tenant must be bound before route-model binding, otherwise global scopes are not applied
        $middleware->prependToPriorityList(before: \Illuminate\Routing\Middleware\SubstituteBindings::class, prepend: SetTenant::class);
        $middleware->alias(['tenant' => SetTenant::class, 'webhook' => WebhookAuth::class, 'canany' => \App\Http\Middleware\CanAny::class]);
        $middleware->validateCsrfTokens(except: ['telegram/webhook/*']);
        $middleware->redirectGuestsTo(fn (Request $r) => route('login'));
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // technical errors are logged, never shown to users (spec §63)
        $exceptions->shouldRenderJsonWhen(fn (Request $r, Throwable $e) => $r->is('api/*') || $r->expectsJson());
    })->create();
