<?php

namespace Tests\Feature\Core;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Каждая страница без параметров открывается у каждой роли на обоих языках:
 * 200 (или редирект) при наличии права, 403 без него, 422 для JSON без параметров — и никогда 500.
 */
class PagesSmokeTest extends TestCase
{
    public function test_every_page_renders_for_every_role_in_both_languages(): void
    {
        $uris = [];
        foreach (Route::getRoutes() as $route) {
            if (in_array('GET', $route->methods(), true) && in_array('fluggi.auth', $route->gatherMiddleware(), true)
                && ! str_contains($route->uri(), '{')) {
                $uris[] = '/'.ltrim($route->uri(), '/');
            }
        }
        $this->assertNotEmpty($uris);

        $failures = [];
        $rop = $this->user('ROP');
        $this->team($rop);
        foreach (['CEO', 'ROP', 'MANAGER', 'EXECUTOR', 'HR_ADMIN', 'PROJECT_MANAGER'] as $role) {
            $user = $role === 'ROP' ? $rop : $this->user($role);
            foreach (['ru', 'uz'] as $locale) {
                $user->update(['locale' => $locale]);
                $this->actingAsUser($user);
                foreach ($uris as $uri) {
                    $status = $this->get($uri)->getStatusCode();
                    if ($status >= 500 || ! in_array($status, [200, 204, 302, 403, 404, 422], true)) {
                        $failures[] = "$role/$locale $uri → $status";
                    }
                }
            }
        }
        $this->assertSame([], $failures);
    }
}
