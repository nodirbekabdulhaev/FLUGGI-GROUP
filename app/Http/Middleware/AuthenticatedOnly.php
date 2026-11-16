<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Маркер «доступно любому вошедшему» (профиль, уведомления, свои дела). */
class AuthenticatedOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        access();

        return $next($request);
    }
}
