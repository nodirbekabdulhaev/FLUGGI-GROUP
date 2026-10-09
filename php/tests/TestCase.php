<?php

namespace Tests;

use App\Auth\Access;
use App\Auth\Passwords;
use App\Auth\Sessions;
use App\Http\Middleware\Authenticate;
use App\Models\Employee;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Support\Outbox;
use App\Support\Settings;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;

/**
 * База для тестов модулей: MySQL, транзакция на каждый тест, роли/права/справочники уже засеяны.
 * Создать сотрудника: $this->user('MANAGER', team: $team); войти: $this->actingAsUser($user).
 */
abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = DatabaseSeeder::class;

    public const PASSWORD = 'Test-password-123';

    protected function setUp(): void
    {
        parent::setUp();
        Settings::forget();
    }

    protected function tearDown(): void
    {
        Outbox::forget();
        app()->forgetInstance(Access::class);
        parent::tearDown();
    }

    /** Сотрудник с ролью; профиль HR создаётся сразу. */
    protected function user(string $role = 'MANAGER', ?Team $team = null, array $attrs = [], array $employee = []): User
    {
        $user = User::create([
            'email' => $attrs['email'] ?? Str::lower(Str::random(10)).'@fluggi.test',
            'full_name' => $attrs['full_name'] ?? ucfirst(strtolower($role)).' '.Str::random(4),
            'password_hash' => Passwords::hash(self::PASSWORD),
            'role_id' => Role::where('code', $role)->value('id'),
            'team_id' => $team?->id,
        ] + $attrs);
        Employee::create(['user_id' => $user->id] + $employee);

        return $user;
    }

    /** Отдел продаж с РОП во главе. */
    protected function team(?User $head = null, string $name = ''): Team
    {
        return Team::create(['name' => $name ?: 'Отдел '.Str::random(6), 'head_id' => $head?->id]);
    }

    /** Вход под пользователем (настоящая сессия в таблице sessions). */
    protected function actingAsUser(User $user): static
    {
        $token = Sessions::create($user, '127.0.0.1', 'phpunit')['token'];
        app()->forgetInstance(Access::class);

        return $this->withSession([Authenticate::TOKEN_KEY => $token]);
    }

    /** Права пользователя без HTTP-запроса (для тестов сервисов). */
    protected function as(User $user): Access
    {
        $token = Sessions::create($user, '127.0.0.1', 'phpunit')['token'];
        $access = Sessions::resolve($token);
        app()->instance(Access::class, $access);

        return $access;
    }
}
