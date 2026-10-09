<?php

namespace Tests\Feature\Core;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Default deny: каждый маршрут после входа обязан явно объявить требование доступа
 * (perm:… или auth.only). Забытая проверка прав иначе стала бы дырой.
 */
class RouteAccessTest extends TestCase
{
    public function test_every_authenticated_route_declares_access(): void
    {
        $missing = [];
        foreach (Route::getRoutes() as $route) {
            $mw = $route->gatherMiddleware();
            if (! in_array('fluggi.auth', $mw, true)) {
                continue;
            }
            $declared = collect($mw)->contains(fn ($m) => is_string($m) && ($m === 'auth.only' || str_starts_with($m, 'perm:')));
            if (! $declared) {
                $missing[] = implode('|', $route->methods()).' '.$route->uri();
            }
        }
        $this->assertSame([], $missing, 'Маршруты без perm:/auth.only');
    }

    public function test_public_routes_are_only_the_expected_ones(): void
    {
        $public = [];
        foreach (Route::getRoutes() as $route) {
            if (! in_array('fluggi.auth', $route->gatherMiddleware(), true)) {
                $public[] = $route->uri();
            }
        }
        foreach ($public as $uri) {
            $this->assertMatchesRegularExpression('#^(/|login|offline|up|hooks/.*|f/.*|storage/.*)$#', $uri, "Публичный маршрут: $uri");
        }
    }
}
