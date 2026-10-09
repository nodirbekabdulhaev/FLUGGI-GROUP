<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\Permissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Роли и права. CEO всегда получает новые права; остальным ролям права по умолчанию
 * ставятся только при первой установке — настройки из интерфейса не затираются.
 */
class RolesSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Permissions::ALL as $code => $description) {
            Permission::updateOrCreate(['code' => $code], ['description' => $description, 'module' => explode('.', $code)[0]]);
        }
        $permissionIds = Permission::pluck('id', 'code');

        foreach (Permissions::defaults() as $roleCode => $map) {
            $role = Role::firstOrCreate(['code' => $roleCode], ['name' => Permissions::ROLE_NAMES[$roleCode], 'is_system' => true]);
            $existing = DB::table('role_permissions')->where('role_id', $role->id)->count();
            if ($existing > 0 && $roleCode !== 'CEO') {
                continue;
            }
            $rows = [];
            foreach ($map as $code => $scope) {
                $rows[] = ['role_id' => $role->id, 'permission_id' => $permissionIds[$code], 'scope' => $scope];
            }
            DB::table('role_permissions')->insertOrIgnore($rows);
        }
    }
}
