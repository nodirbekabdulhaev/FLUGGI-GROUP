<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_is_refused_when_demo_mode_is_off(): void
    {
        config(['app.demo' => false]);
        $this->artisan('demo:reset')->assertFailed();
        $this->get('/demo/director')->assertNotFound();
    }

    public function test_demo_guard_blocks_dangerous_writes(): void
    {
        $this->seed(DemoSeeder::class);
        config(['app.demo' => true]);
        $admin = User::where('email', 'admin@demo.uz')->first();
        $this->actingAs($admin)->put('/profile', ['name' => 'x', 'current_password' => 'Demo2026pass', 'password' => 'Hacked12345', 'password_confirmation' => 'Hacked12345'])->assertSessionHas('warn');
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('Demo2026pass', $admin->fresh()->password));
        $this->post('/settings/backups')->assertSessionHas('warn');
        $this->get('/students')->assertOk()->assertSee('Демо-версия');
    }
}
