<?php

namespace App\Console\Commands;

use App\Auth\Passwords;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Установка и обновление: миграции, роли и справочники, защита данных триггерами,
 * первый CEO. Повторный запуск безопасен — существующие данные не изменяются.
 */
class Install extends Command
{
    protected $signature = 'fluggi:install {--ceo-email=} {--ceo-name=}';

    protected $description = 'Миграции, справочники, защита данных и первый CEO';

    public function handle(): int
    {
        $this->call('migrate', ['--force' => true]);
        $this->call('db:seed', ['--force' => true]);
        $this->call('fluggi:protect');

        $email = mb_strtolower(trim((string) ($this->option('ceo-email') ?: env('SEED_CEO_EMAIL'))));
        if ($email === '') {
            return self::SUCCESS;
        }
        if (User::withTrashed()->where('email', $email)->exists()) {
            $this->line("• CEO $email уже существует");

            return self::SUCCESS;
        }
        $password = (string) (env('SEED_CEO_PASSWORD') ?: Passwords::temporary());
        DB::transaction(function () use ($email, $password) {
            $user = User::create([
                'email' => $email,
                'full_name' => $this->option('ceo-name') ?: (env('SEED_CEO_NAME') ?: 'CEO'),
                'password_hash' => Passwords::hash($password),
                'role_id' => Role::where('code', 'CEO')->value('id'),
            ]);
            Employee::create(['user_id' => $user->id, 'position' => 'CEO', 'hired_at' => now()->toDateString()]);
            Audit::log('user.created', 'user', $user->id, actorId: $user->id);
        });
        $this->info("✓ Создан CEO $email");
        $this->line("  Пароль: $password   ← сохраните и смените после входа, повторно не показывается");

        return self::SUCCESS;
    }
}
