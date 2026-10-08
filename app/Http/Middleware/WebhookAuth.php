<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;

/** Public lead webhook: the organization is identified by its secret key (X-Webhook-Key header). */
class WebhookAuth
{
    public function handle(Request $request, Closure $next)
    {
        $key = (string) ($request->header('X-Webhook-Key') ?: $request->bearerToken());
        $org = strlen($key) >= 20 ? Organization::where('webhook_key', $key)->where('is_active', true)->first() : null;

        if (! $org) {
            return response()->json(['message' => 'Invalid webhook key'], 401);
        }

        Tenant::clear();
        Tenant::set($org->id);

        return $next($request);
    }
}
