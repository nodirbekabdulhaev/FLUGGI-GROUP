<?php

namespace App\Services\People;

use App\Exceptions\BusinessRule;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Audit;
use App\Support\Permissions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Роли и права (ТЗ §3): CEO меняет области прав ролей; у CEO нельзя отнять управление системой. */
final class RoleService
{
    public static function list(): Collection
    {
        return Role::withCount(['users' => fn ($q) => $q->whereNull('deleted_at')])->orderBy('created_at')->get()->each(function ($r) {
            $r->setAttribute('permission_map', DB::table('role_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')
                ->where('rp.role_id', $r->id)->pluck('rp.scope', 'p.code')->all());
        });
    }

    /** Модули прав в порядке каталога. */
    public static function modules(): array
    {
        $out = [];
        foreach (Permissions::codes() as $code) {
            $out[explode('.', $code)[0]][] = $code;
        }

        return $out;
    }

    /** @param  array<string,string>  $permissions  код → OWN|TEAM|ALL */
    public static function updatePermissions(string $roleId, array $permissions): void
    {
        $role = Role::findOrFail($roleId);
        $permissions = array_filter(array_intersect_key($permissions, Permissions::ALL), fn ($s) => in_array($s, Permissions::SCOPES, true));
        // Удаление лидов, клиентов и проектов — только CEO; управление КП, договорами и оплатами — РОП и CEO
        $permissions = array_filter($permissions, fn ($s, $c) => ! Permissions::forbiddenFor($c, $role->code), ARRAY_FILTER_USE_BOTH);
        if ($role->code === 'CEO') {
            $missing = array_filter(Permissions::CEO_LOCKED, fn ($c) => ($permissions[$c] ?? null) !== 'ALL');
            if ($missing) {
                throw new BusinessRule(t('settings.errors.ceoLocked', ['codes' => implode(', ', $missing)]));
            }
        }
        $ids = Permission::pluck('id', 'code');
        $before = DB::table('role_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')->where('rp.role_id', $roleId)->pluck('rp.scope', 'p.code')->all();
        $changes = [];
        foreach (array_unique([...array_keys($before), ...array_keys($permissions)]) as $code) {
            $old = $before[$code] ?? null;
            $new = $permissions[$code] ?? null;
            if ($old !== $new) {
                $changes[$code] = ['old' => $old, 'new' => $new];
            }
        }
        DB::transaction(function () use ($roleId, $permissions, $ids, $changes) {
            DB::table('role_permissions')->where('role_id', $roleId)->delete();
            DB::table('role_permissions')->insert(array_map(fn ($code, $scope) => ['role_id' => $roleId, 'permission_id' => $ids[$code], 'scope' => $scope], array_keys($permissions), $permissions));
            if ($changes) {
                Audit::log('role.permissions_update', 'role', $roleId, $changes);
            }
        });
    }
}
