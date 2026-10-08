<?php

namespace App\Providers;

use App\Models\Permission;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Relation::enforceMorphMap([
            'student' => \App\Models\Student::class,
            'guardian' => \App\Models\Guardian::class,
            'teacher' => \App\Models\Teacher::class,
            'employee' => \App\Models\Employee::class,
            'user' => \App\Models\User::class,
        ]);

        if ($this->app->isProduction() || str_starts_with((string) config('app.url'), 'https://')) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }

        // One Gate per permission slug; super_admin passes everything (see User::hasPermission).
        foreach (array_keys(Permission::ALL) as $slug) {
            Gate::define($slug, fn (User $u) => $u->hasPermission($slug));
        }

        // Surface N+1 problems during development (logged, never fatal).
        if (! $this->app->isProduction()) {
            Model::preventLazyLoading();
            Model::handleLazyLoadingViolationUsing(function ($model, $relation) {
                Log::channel('single')->warning('N+1: '.get_class($model).'::'.$relation);
            });
        }

        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(120)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('webhook', fn (Request $r) => Limit::perMinute(60)->by($r->header('X-Webhook-Key', $r->ip())));
    }
}
