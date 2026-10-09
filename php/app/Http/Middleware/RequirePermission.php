<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * perm:lead.read — нужно право; perm:lead.read|deal.read — любое из; perm:finance.read,ALL — не ниже области.
 * Каждый маршрут после входа обязан объявить perm:… или auth.only (default deny, см. RouteAccessTest).
 */
class RequirePermission
{
    public function handle(Request $request, Closure $next, string $codes, string $minScope = 'OWN'): Response
    {
        $access = access();
        foreach (explode('|', $codes) as $code) {
            if ($access->can($code, $minScope)) {
                return $next($request);
            }
        }
        abort(403);
    }
}
