<?php

namespace App\Services\People;

use App\Auth\Passwords;
use App\Auth\Sessions;
use App\Exceptions\BusinessRule;
use App\Models\Direction;
use App\Models\Employee;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Models\UserDirection;
use App\Services\Projects\ProjectAccess;
use App\Support\Audit;
use App\Support\Outbox;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Сотрудники (ТЗ §3, §37): учётные записи, роли, отделы, направления, блокировка, удаление с передачей работы. */
final class UserService
{
    public const ROLES = ['CEO', 'ROP', 'MANAGER', 'EXECUTOR', 'HR_ADMIN', 'PROJECT_MANAGER'];

    public const SALES_ROLES = ['ROP', 'MANAGER'];

    /** Видимость: ALL — все, TEAM — свои отделы и себя, OWN — только себя. */
    public static function visible(): Builder
    {
        return access()->restrict(User::query(), 'employee.read',
            own: fn ($q, $uid) => $q->where('users.id', $uid),
            team: fn ($q, $teamIds, $uid) => $q->whereIn('users.team_id', $teamIds ?: ['-'])->orWhere('users.id', $uid),
        );
    }

    /** @param  array{q?:?string,roleCode?:?string,teamId?:?string,status?:?string}  $f */
    public static function list(array $f, int $perPage = 25)
    {
        $q = self::visible()->with(['role', 'team', 'employee', 'directions.direction']);
        if (! empty($f['q'])) {
            $term = '%'.$f['q'].'%';
            $q->where(fn ($w) => $w->where('full_name', 'like', $term)->orWhere('email', 'like', $term)->orWhere('phone', 'like', $term));
        }
        if (! empty($f['roleCode'])) {
            $q->whereHas('role', fn ($r) => $r->where('code', $f['roleCode']));
        }
        if (! empty($f['teamId'])) {
            $q->where('team_id', $f['teamId']);
        }
        if (! empty($f['status'])) {
            $q->where('status', $f['status']);
        }

        return $q->orderBy('status')->orderBy('full_name')->paginate($perPage)->withQueryString();
    }

    public static function get(string $id): User
    {
        return self::visible()->with(['role', 'team', 'employee', 'directions.direction'])->whereKey($id)->firstOrFail();
    }

    private static function assertDirections(array $ids): void
    {
        if ($ids && Direction::whereIn('id', $ids)->count() !== count(array_unique($ids))) {
            throw new BusinessRule(t('employees.errors.direction'), ['direction_ids' => t('employees.errors.directionField')]);
        }
    }

    private static function assertTeam(?string $teamId): void
    {
        if ($teamId && ! Team::whereKey($teamId)->exists()) {
            throw new BusinessRule(t('employees.errors.team'), ['team_id' => t('employees.errors.team')]);
        }
    }

    private static function assertCanAssignRole(string $role): void
    {
        if ($role === 'CEO' && access()->roleCode !== 'CEO') {
            abort(403, t('employees.errors.ceoRole'));
        }
    }

    private static function assertEmailFree(string $email, ?string $exceptId = null): void
    {
        if (User::withTrashed()->where('email', $email)->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->exists()) {
            throw new BusinessRule(t('employees.errors.emailTaken'));
        }
    }

    /**
     * @param  array{email:string,full_name:string,phone:?string,role_code:string,team_id:?string,position:?string,specialty:?string,locale:string,direction_ids:array,password?:?string}  $input
     * @return array{user:User, temporaryPassword:?string}
     */
    public static function create(array $input): array
    {
        self::assertCanAssignRole($input['role_code']);
        self::assertEmailFree($input['email']);
        $role = Role::where('code', $input['role_code'])->firstOrFail();
        self::assertTeam($input['team_id'] ?? null);
        self::assertDirections($input['direction_ids']);
        $temporary = empty($input['password']) ? Passwords::temporary() : null;
        $hash = Passwords::hash($temporary ?? $input['password']);

        $user = DB::transaction(function () use ($input, $role, $hash) {
            $u = User::create([
                'email' => $input['email'], 'full_name' => $input['full_name'], 'phone' => $input['phone'] ?? null,
                'password_hash' => $hash, 'role_id' => $role->id, 'team_id' => $input['team_id'] ?? null, 'locale' => $input['locale'],
            ]);
            Employee::create(['user_id' => $u->id, 'position' => $input['position'] ?? null, 'specialty' => $input['role_code'] === 'EXECUTOR' ? ($input['specialty'] ?? null) : null]);
            foreach (array_unique($input['direction_ids']) as $d) {
                UserDirection::create(['user_id' => $u->id, 'direction_id' => $d]);
            }
            Audit::log('user.create', 'user', $u->id, [
                'email' => ['old' => null, 'new' => $u->email], 'role' => ['old' => null, 'new' => $input['role_code']], 'teamId' => ['old' => null, 'new' => $u->team_id],
            ]);
            Outbox::publish('user.created', ['userId' => $u->id, 'roleCode' => $input['role_code']]);

            return $u;
        });

        return ['user' => $user, 'temporaryPassword' => $temporary];
    }

    private static function snapshot(User $u): array
    {
        $u->load(['role', 'employee', 'directions.direction']);

        return [
            'email' => $u->email, 'fullName' => $u->full_name, 'phone' => $u->phone, 'locale' => $u->locale, 'teamId' => $u->team_id,
            'role' => $u->role->code, 'position' => $u->employee?->position, 'specialty' => $u->employee?->specialty,
            'directions' => $u->directions->map(fn ($d) => $d->direction?->name)->sort()->implode(', '),
        ];
    }

    /** @param  array<string,mixed>  $input */
    public static function update(string $id, array $input): User
    {
        $access = access();
        $before = self::findManageable($id);
        $oldRole = $before->role->code;
        $newRole = $input['role_code'] ?? $oldRole;
        $roleChanged = $newRole !== $oldRole;
        if ($roleChanged) {
            if ($id === $access->id()) {
                throw new BusinessRule(t('employees.errors.ownRole'));
            }
            self::assertCanAssignRole($newRole);
            if ($oldRole === 'CEO') {
                self::assertNotLastCeo($id);
            }
            if ($oldRole === 'ROP') {
                self::assertNotTeamHead($id);
            }
        }
        if (! empty($input['team_id'])) {
            self::assertTeam($input['team_id']);
        }
        if (isset($input['direction_ids'])) {
            self::assertDirections($input['direction_ids']);
        }
        if (isset($input['email']) && $input['email'] !== $before->email) {
            self::assertEmailFree($input['email'], $id);
        }

        return DB::transaction(function () use ($before, $input, $newRole, $roleChanged, $oldRole, $id) {
            $old = self::snapshot($before);
            $data = array_intersect_key($input, array_flip(['email', 'full_name', 'phone', 'locale', 'team_id']));
            if ($roleChanged) {
                $data['role_id'] = Role::where('code', $newRole)->value('id');
            }
            $before->update($data);
            $specialty = $newRole === 'EXECUTOR' ? (array_key_exists('specialty', $input) ? $input['specialty'] : $before->employee?->specialty) : null;
            $position = array_key_exists('position', $input) ? $input['position'] : $before->employee?->position;
            Employee::updateOrCreate(['user_id' => $id], ['position' => $position, 'specialty' => $specialty]);
            // Направления меняют видимость проектов сразу: права собираются из БД на каждый запрос
            if (isset($input['direction_ids'])) {
                UserDirection::where('user_id', $id)->delete();
                foreach (array_unique($input['direction_ids']) as $d) {
                    UserDirection::create(['user_id' => $id, 'direction_id' => $d]);
                }
            }
            $new = self::snapshot($before->fresh());
            $changes = Audit::diff($old, $new, array_keys($new));
            if ($changes) {
                Audit::log('user.update', 'user', $id, $changes);
            }
            if ($roleChanged) {
                // Права изменились — все сессии пользователя закрываются
                Sessions::revokeAllForUser($id);
                Outbox::publish('user.role_changed', ['userId' => $id, 'from' => $oldRole, 'to' => $newRole]);
            }

            return $before->fresh();
        });
    }

    public static function setBlocked(string $id, bool $blocked): User
    {
        if ($id === access()->id()) {
            throw new BusinessRule(t('employees.errors.blockSelf'));
        }
        $before = self::findManageable($id);
        if ($blocked && $before->role->code === 'CEO') {
            self::assertNotLastCeo($id);
        }
        $status = $blocked ? 'BLOCKED' : 'ACTIVE';
        if ($before->status === $status) {
            return $before;
        }
        DB::transaction(function () use ($before, $status, $blocked, $id) {
            $old = $before->status;
            $before->update(['status' => $status, 'failed_login_count' => 0, 'locked_until' => null]);
            if ($blocked) {
                Sessions::revokeAllForUser($id);
                Outbox::publish('user.blocked', ['userId' => $id]);
            }
            Audit::log($blocked ? 'user.block' : 'user.unblock', 'user', $id, ['status' => ['old' => $old, 'new' => $status]]);
        });

        return $before->fresh();
    }

    /** Открытая работа сотрудника: что нужно передать перед удалением. */
    public static function workload(string $id): array
    {
        self::findManageable($id);
        $w = [
            'leads' => DB::table('leads')->where('owner_id', $id)->where('status', 'OPEN')->whereNull('deleted_at')->count(),
            'deals' => DB::table('deals')->where('owner_id', $id)->where('status', 'OPEN')->whereNull('deleted_at')->count(),
            'clients' => DB::table('clients')->where('owner_id', $id)->whereNull('deleted_at')->count(),
            'projects' => DB::table('projects')->whereNull('deleted_at')->whereIn('status', ProjectAccess::ACTIVE)
                ->where(fn ($q) => $q->where('manager_id', $id)->orWhere('rop_id', $id))->count(),
            'tasks' => DB::table('tasks')->where('assignee_id', $id)->whereNull('deleted_at')->whereIn('status', ProjectAccess::OPEN_TASK)->count(),
            'todos' => DB::table('todos')->where('owner_id', $id)->where('status', 'OPEN')->whereNull('deleted_at')->count(),
            'threads' => DB::table('social_threads')->where('owner_id', $id)->count(),
        ];

        return $w + ['total' => array_sum($w)];
    }

    /**
     * Удаление сотрудника: открытая работа передаётся другому сотруднику, история (оплаты,
     * комиссии, зарплата, аудит) остаётся с его именем. Учётная запись скрывается и не может войти;
     * email освобождается — его можно выдать новому сотруднику.
     */
    public static function remove(string $id, ?string $transferToId): void
    {
        $access = access();
        if ($id === $access->id()) {
            throw new BusinessRule(t('employees.errors.deleteSelf'));
        }
        $before = self::findManageable($id);
        if ($before->role->code === 'CEO') {
            self::assertNotLastCeo($id);
        }
        self::assertNotTeamHead($id);
        $work = self::workload($id);
        $target = null;
        if ($transferToId) {
            if ($transferToId === $id) {
                throw new BusinessRule(t('employees.errors.transferOther'), ['transfer_to_id' => t('employees.errors.transferOther')]);
            }
            $target = User::where('id', $transferToId)->where('status', 'ACTIVE')->first();
            if (! $target) {
                throw new BusinessRule(t('employees.errors.transferNotFound'), ['transfer_to_id' => t('employees.errors.transferActive')]);
            }
        } elseif ($work['total'] > 0) {
            throw new BusinessRule(t('employees.errors.hasWork'), ['transfer_to_id' => t('employees.errors.hasWorkField')]);
        }

        DB::transaction(function () use ($id, $before, $target, $work, $access) {
            $now = now();
            if ($target) {
                $to = ['owner_id' => $target->id, 'team_id' => $target->team_id];
                DB::table('leads')->where('owner_id', $id)->where('status', 'OPEN')->whereNull('deleted_at')->update($to + ['updated_at' => $now]);
                DB::table('deals')->where('owner_id', $id)->where('status', 'OPEN')->whereNull('deleted_at')->update($to + ['updated_at' => $now]);
                DB::table('clients')->where('owner_id', $id)->whereNull('deleted_at')->update($to + ['updated_at' => $now]);
                $active = fn () => DB::table('projects')->whereNull('deleted_at')->whereIn('status', ProjectAccess::ACTIVE);
                $active()->where('manager_id', $id)->update(['manager_id' => $target->id, 'updated_at' => $now]);
                $active()->where('rop_id', $id)->update(['rop_id' => $target->id, 'updated_at' => $now]);
                // Задачи: новый исполнитель становится участником команды проекта
                $tasks = DB::table('tasks')->where('assignee_id', $id)->whereNull('deleted_at')->whereIn('status', ProjectAccess::OPEN_TASK)->get(['id', 'project_id']);
                foreach ($tasks->pluck('project_id')->unique() as $projectId) {
                    $m = \App\Models\ProjectMember::firstOrNew(['project_id' => $projectId, 'user_id' => $target->id]);
                    $m->exists ? $m->update(['status' => 'ACTIVE']) : $m->fill(['assigned_by_id' => $access->id(), 'assigned_at' => $now, 'status' => 'ACTIVE'])->save();
                }
                DB::table('tasks')->whereIn('id', $tasks->pluck('id'))->update(['assignee_id' => $target->id, 'updated_at' => $now]);
                DB::table('todos')->where('owner_id', $id)->where('status', 'OPEN')->whereNull('deleted_at')->update(['owner_id' => $target->id, 'updated_at' => $now]);
                DB::table('social_threads')->where('owner_id', $id)->update($to + ['updated_at' => $now]);
            }
            DB::table('project_members')->where('user_id', $id)->where('status', 'ACTIVE')->update(['status' => 'REMOVED', 'updated_at' => $now]);
            DB::table('recurring_todos')->where('owner_id', $id)->whereNull('deleted_at')->update(['deleted_at' => $now, 'is_active' => false, 'updated_at' => $now]);
            DB::table('lead_forms')->where('owner_id', $id)->update(['owner_id' => null]);
            UserDirection::where('user_id', $id)->delete();
            $email = $before->email;
            // email свободен для нового сотрудника; исходный — в аудите
            $before->update(['status' => 'BLOCKED', 'email' => 'deleted.'.substr($id, 0, 8).'.'.$email, 'telegram_chat_id' => null]);
            $before->delete();
            Sessions::revokeAllForUser($id);
            Outbox::publish('user.blocked', ['userId' => $id]);
            Audit::log('user.delete', 'user', $id, [
                'email' => ['old' => $email, 'new' => null],
                'fullName' => ['old' => $before->full_name, 'new' => null],
                'transferredTo' => ['old' => null, 'new' => $target?->full_name],
                'workload' => ['old' => $work, 'new' => null],
            ]);
        });
    }

    public static function resetPassword(string $id): string
    {
        if ($id === access()->id()) {
            throw new BusinessRule(t('employees.errors.resetSelf'));
        }
        self::findManageable($id);
        $temporary = Passwords::temporary();
        DB::transaction(function () use ($id, $temporary) {
            User::whereKey($id)->update(['password_hash' => Passwords::hash($temporary), 'failed_login_count' => 0, 'locked_until' => null]);
            Sessions::revokeAllForUser($id);
            Audit::log('user.reset_password', 'user', $id);
        });

        return $temporary;
    }

    /** Учётную запись CEO меняет только CEO. */
    public static function findManageable(string $id): User
    {
        $user = User::with(['role', 'employee', 'directions.direction'])->findOrFail($id);
        if ($user->role->code === 'CEO' && access()->roleCode !== 'CEO') {
            abort(403, t('employees.errors.ceoOnly'));
        }

        return $user;
    }

    private static function assertNotLastCeo(string $userId): void
    {
        $others = User::whereKeyNot($userId)->where('status', 'ACTIVE')->whereHas('role', fn ($q) => $q->where('code', 'CEO'))->count();
        if ($others === 0) {
            throw new BusinessRule(t('employees.errors.lastCeo'));
        }
    }

    private static function assertNotTeamHead(string $userId): void
    {
        if (Team::where('head_id', $userId)->exists()) {
            throw new BusinessRule(t('employees.errors.teamHead'));
        }
    }
}
