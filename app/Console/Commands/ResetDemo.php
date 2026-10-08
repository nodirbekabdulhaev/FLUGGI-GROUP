<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ResetDemo extends Command
{
    protected $signature = 'demo:reset';
    protected $description = 'Сбросить демо-базу к исходным demo-данным (только при DEMO_MODE=true)';

    public function handle(): int
    {
        if (! config('app.demo')) {
            $this->error('DEMO_MODE выключен — сброс запрещён, чтобы не стереть боевые данные.');

            return self::FAILURE;
        }
        $this->call('migrate:fresh', ['--seed' => true, '--seeder' => 'DemoSeeder', '--force' => true]);

        return self::SUCCESS;
    }
}
