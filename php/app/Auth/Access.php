<?php

namespace App\Auth;

use App\Models\User;
use App\Support\Permissions;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Права текущего пользователя (на один запрос). Все проверки доступа — на сервере:
 * интерфейс только скрывает недоступное, но решение принимается здесь.
 */
final class Access
{
    /**
     * @param  array<string,string>  $permissions  код права → OWN|TEAM|ALL
     * @param  string[]  $headedTeamIds  отделы, которыми руководит пользователь
     * @param  string[]  $directionIds  направления (IT/Медиа/Маркетинг) проект-менеджера
     */
    public function __construct(
        public readonly User $user,
        public readonly string $sessionId,
        public readonly string $roleCode,
        public readonly string $roleName,
        public readonly array $permissions,
        public readonly ?string $teamId,
        public readonly array $headedTeamIds,
        public readonly array $directionIds,
    ) {}

    public function id(): string
    {
        return $this->user->id;
    }

    public function can(string $code, string $minScope = 'OWN'): bool
    {
        return Permissions::has($this->permissions, $code, $minScope);
    }

    public function canAny(array $codes): bool
    {
        foreach ($codes as $code) {
            if ($this->can($code)) {
                return true;
            }
        }

        return false;
    }

    /** Область права или 403. */
    public function scope(string $code): string
    {
        return $this->permissions[$code] ?? throw new HttpException(403);
    }

    public function authorize(string $code, string $minScope = 'OWN'): void
    {
        if (! $this->can($code, $minScope)) {
            throw new HttpException(403);
        }
    }

    /** Отделы для области TEAM: свой отдел + отделы, которыми руководит. */
    public function teamIds(): array
    {
        return array_values(array_unique(array_filter([...$this->headedTeamIds, $this->teamId])));
    }

    /**
     * Ограничивает выборку областью видимости права. Каждый модуль описывает, как запись
     * принадлежит пользователю ($own) и отделу ($team) — фильтр строится единообразно.
     *  ALL  → без ограничений; TEAM → $team($q, teamIds, userId, directionIds); OWN → $own($q, userId)
     *
     * @template T of Builder|QueryBuilder
     *
     * @param  T  $query
     * @return T
     */
    public function restrict($query, string $code, callable $own, callable $team)
    {
        $scope = $this->scope($code);
        if ($scope === 'ALL') {
            return $query;
        }
        if ($scope === 'TEAM') {
            return $query->where(fn ($q) => $team($q, $this->teamIds(), $this->id(), $this->directionIds));
        }

        return $query->where(fn ($q) => $own($q, $this->id()));
    }

    /**
     * Стандартный случай: запись принадлежит владельцу (owner_id) и отделу (team_id).
     * TEAM видит записи своих отделов и свои собственные.
     */
    public function restrictOwned($query, string $code, string $ownerColumn = 'owner_id', string $teamColumn = 'team_id')
    {
        return $this->restrict(
            $query,
            $code,
            fn ($q, $uid) => $q->where($ownerColumn, $uid),
            fn ($q, $teamIds, $uid) => $q->whereIn($teamColumn, $teamIds ?: ['-'])->orWhere($ownerColumn, $uid),
        );
    }
}
