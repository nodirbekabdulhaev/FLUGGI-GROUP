<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/** Обязательные данные: роли и права, справочники. Повторный запуск безопасен. */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([RolesSeeder::class, ReferenceSeeder::class]);
    }
}
