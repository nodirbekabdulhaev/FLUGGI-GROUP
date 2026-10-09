<?php

namespace App\Services\Crm;

use App\Exceptions\BusinessRule;
use App\Models\Client;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Единая точка разграничения данных CRM (ТЗ §65): каждая выборка лидов, сделок, клиентов
 * и встреч проходит через эти фильтры. Записи принадлежат менеджеру (owner_id) и отделу (team_id).
 * Чужая запись — 404. Используется и модулями продаж: КП, договоры, оплаты видны по сделке.
 */
final class CrmAccess
{
    public const OWNER_ROLES = ['MANAGER', 'ROP'];

    public static function leads(string $code = 'lead.read'): Builder
    {
        return access()->restrictOwned(Lead::query(), $code);
    }

    public static function deals(string $code = 'deal.read'): Builder
    {
        return access()->restrictOwned(Deal::query(), $code);
    }

    public static function clients(string $code = 'client.read'): Builder
    {
        return access()->restrictOwned(Client::query(), $code);
    }

    public static function meetings(string $code = 'meeting.read'): Builder
    {
        return access()->restrict(
            Meeting::query(),
            $code,
            fn ($q, $uid) => $q->where('manager_id', $uid),
            fn ($q, $teamIds, $uid) => $q->whereIn('team_id', $teamIds ?: ['-'])->orWhere('manager_id', $uid)->orWhere('rop_id', $uid),
        );
    }

    /** Ограничение записей, привязанных к сделке (КП, договоры, оплаты): видны по видимости сделки. */
    public static function byDeal(Builder $query, string $code, string $column = 'deal_id'): Builder
    {
        return $query->whereIn($column, self::deals($code)->select('deals.id'));
    }

    public static function lead(string $id, string $code = 'lead.read'): Lead
    {
        return self::leads($code)->whereKey($id)->firstOrFail();
    }

    public static function deal(string $id, string $code = 'deal.read'): Deal
    {
        return self::deals($code)->whereKey($id)->firstOrFail();
    }

    public static function client(string $id, string $code = 'client.read'): Client
    {
        return self::clients($code)->whereKey($id)->firstOrFail();
    }

    /**
     * Ответственный за лид, сделку, клиента — только менеджер или РОП, и в зоне видимости
     * назначающего (менеджер — себя, РОП — свой отдел, CEO — любого). Не указан, а создаёт
     * не менеджер/РОП (CEO, HR) — менеджер с наименьшим числом открытых лидов.
     */
    public static function assignableOwner(?string $ownerId, string $code): User
    {
        $access = access();
        $targetId = $ownerId ?: (in_array($access->roleCode, self::OWNER_ROLES, true) ? $access->id() : self::leastLoadedOwner());
        $user = User::with('role')->where('status', 'ACTIVE')->find($targetId);
        if ($user && ! in_array($user->role->code, self::OWNER_ROLES, true)) {
            throw new BusinessRule(t('crm.errors.ownerRole'), ['owner_id' => t('crm.errors.ownerRoleField')]);
        }
        $scope = $access->permissions[$code] ?? null;
        $inScope = $scope === 'ALL' || $targetId === $access->id()
            || ($scope === 'TEAM' && $user?->team_id && in_array($user->team_id, $access->teamIds(), true));
        if (! $user || ! $inScope) {
            abort(404);
        }

        return $user;
    }

    /** Менеджер с наименьшим числом открытых лидов; если менеджеров нет — РОП. */
    public static function leastLoadedOwner(?string $teamId = null): string
    {
        foreach (self::OWNER_ROLES as $role) {
            $id = DB::table('users as u')
                ->join('roles as r', 'r.id', '=', 'u.role_id')
                ->where('r.code', $role)->where('u.status', 'ACTIVE')->whereNull('u.deleted_at')
                ->when($teamId, fn ($q) => $q->where('u.team_id', $teamId))
                ->selectRaw('u.id, (SELECT COUNT(*) FROM leads l WHERE l.owner_id = u.id AND l.status = ? AND l.deleted_at IS NULL) AS open_leads', ['OPEN'])
                ->orderBy('open_leads')->orderBy('u.id')
                ->value('u.id');
            if ($id) {
                return $id;
            }
        }
        if ($teamId) {
            return self::leastLoadedOwner();
        }
        throw new BusinessRule(t('crm.errors.noOwners'));
    }
}
