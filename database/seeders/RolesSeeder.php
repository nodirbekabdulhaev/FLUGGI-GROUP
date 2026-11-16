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

        // Права «только CEO» снимаются с остальных ролей (в том числе выданные раньше)
        DB::table('role_permissions as rp')->join('roles as r', 'r.id', '=', 'rp.role_id')
            ->where('r.code', '<>', 'CEO')
            ->whereIn('rp.permission_id', $permissionIds->only(Permissions::CEO_ONLY)->values())
            ->delete();
        // Управление КП, договорами и оплатами — только РОП и CEO
        DB::table('role_permissions as rp')->join('roles as r', 'r.id', '=', 'rp.role_id')
            ->whereNotIn('r.code', ['CEO', 'ROP'])
            ->whereIn('rp.permission_id', $permissionIds->only(Permissions::ROP_UP)->values())
            ->delete();

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
