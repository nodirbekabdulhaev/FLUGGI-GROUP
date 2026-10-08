<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** canany:perm1,perm2 — passes when the user has at least one of the permissions. */
class CanAny
{
    public function handle(Request $request, Closure $next, string ...$permissions)
    {
        abort_unless(Gate::any($permissions), 403);

        return $next($request);
    }
}
