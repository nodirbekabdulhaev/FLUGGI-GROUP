<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** In demo mode, settings that could lock visitors out or leak data are read-only. */
class DemoGuard
{
    private const BLOCKED = ['profile.update', 'backups.*', 'users.*', 'roles.*', 'settings.update', 'telegram.*', 'files.*'];

    public function handle(Request $request, Closure $next)
    {
        if (config('app.demo') && ! $request->isMethodSafe() && $request->routeIs(...self::BLOCKED)) {
            return back()->with('warn', 'В демо-версии это действие отключено.');
        }

        return $next($request);
    }
}
