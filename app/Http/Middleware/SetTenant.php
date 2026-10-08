<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;

/**
 * Binds the authenticated user's organization (and chosen branch) for the whole request
 * so every tenant-owned model is scoped automatically. Also sets locale.
 */
class SetTenant
{
    public const LOCALES = ['ru', 'uz'];

    public function handle(Request $request, Closure $next)
    {
        Tenant::clear();
        $user = Auth::guard('web')->user() ?? Auth::guard('sanctum')->user();

        $locale = $request->hasSession() ? $request->session()->get('locale') : null;

        if ($user) {
            if (! $user->is_active || $user->trashed()) {
                Auth::guard('web')->logout();
                if ($request->hasSession()) {
                    $request->session()->invalidate();
                }

                return redirect()->route('login')->withErrors(['login' => 'Учётная запись отключена.']);
            }

            // Restricted users are pinned to their branch; others may pick "all" or one branch.
            $branchId = $user->branch_id;
            if (! $branchId && $request->hasSession()) {
                $picked = (int) $request->session()->get('branch_id', 0);
                $branchId = $picked ?: null;
            }
            Tenant::set($user->organization_id, $branchId ?: null);
            $locale = $user->locale ?: $locale ?: Tenant::organization()?->locale;

            if ($request->hasSession()) {
                View::share('tenantBranches', Branch::active()->orderBy('name')->get(['id', 'name']));
                View::share('currentBranchId', Tenant::branchId());
                View::share('currentOrg', Tenant::organization());
            }
        }

        App::setLocale(in_array($locale, self::LOCALES, true) ? $locale : 'ru');

        return $next($request);
    }
}
