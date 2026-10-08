<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Services\OrganizationSetup;
use App\Support\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

class Install extends Command
{
    protected $signature = 'erp:install
        {--name= : Название учебного центра}
        {--email= : Email супер-администратора}
        {--password= : Пароль}
        {--branch=Главный филиал : Название первого филиала}
        {--demo : Загрузить demo-данные}';
    protected $description = 'Первичная установка: миграции, роли, справочники, организация и Super Admin';

    public function handle(OrganizationSetup $setup): int
    {
        Artisan::call('migrate', ['--force' => true]);
        $this->info('Миграции выполнены.');
        OrganizationSetup::syncRoles();

        $name = $this->option('name') ?: $this->ask('Название организации', 'FLUGGI EDU');
        $email = $this->option('email') ?: $this->ask('Email администратора');
        $password = $this->option('password') ?: $this->secret('Пароль (мин. 10 символов)');
        if (strlen((string) $password) < 10) {
            $this->error('Пароль слишком короткий.');

            return self::FAILURE;
        }

        $org = $setup->createOrganization($name);
        Tenant::run($org, function () use ($org, $email, $password) {
            $branch = Branch::create(['name' => $this->option('branch'), 'status' => 'active']);
            $user = User::create(['name' => 'Super Admin', 'email' => $email, 'password' => $password, 'is_active' => true]);
            $user->roles()->attach(Role::where('slug', 'super_admin')->whereNull('organization_id')->value('id'));
            $this->info("Организация «{$org->name}», филиал «{$branch->name}», администратор {$user->email} созданы.");
        });
        $this->line('Webhook-ключ лидов: '.$org->webhook_key);

        if ($this->option('demo')) {
            Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);
            $this->info('Demo-данные загружены.');
        }

        return self::SUCCESS;
    }
}
