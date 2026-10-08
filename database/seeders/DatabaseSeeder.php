<?php

namespace Database\Seeders;

use App\Services\OrganizationSetup;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Production seed: permissions + system roles only. The organization is created by `php artisan erp:install`. */
    public function run(): void
    {
        OrganizationSetup::syncRoles();
    }
}
