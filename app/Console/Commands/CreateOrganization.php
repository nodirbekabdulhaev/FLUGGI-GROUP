<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Services\OrganizationSetup;
use App\Support\Tenant;
use Illuminate\Console\Command;

class CreateOrganization extends Command
{
    protected $signature = 'erp:organization {name} {email : email администратора} {password} {--branch=Главный филиал}';
    protected $description = 'Создать ещё одну организацию (tenant) с Super Admin — основа для SaaS-режима';

    public function handle(OrganizationSetup $setup): int
    {
        if (strlen((string) $this->argument('password')) < 10) {
            $this->error('Пароль: минимум 10 символов.');

            return self::FAILURE;
        }
        OrganizationSetup::syncRoles();
        $org = $setup->createOrganization($this->argument('name'));
        Tenant::run($org, function () {
            Branch::create(['name' => $this->option('branch'), 'status' => 'active']);
            $u = User::create(['name' => 'Super Admin', 'email' => $this->argument('email'), 'password' => $this->argument('password'), 'is_active' => true]);
            $u->roles()->attach(Role::where('slug', 'super_admin')->whereNull('organization_id')->value('id'));
        });
        $this->info("Организация #{$org->id} «{$org->name}» создана. Webhook-ключ: {$org->webhook_key}");

        return self::SUCCESS;
    }
}
