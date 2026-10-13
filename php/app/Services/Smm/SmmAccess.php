<?php

namespace App\Services\Smm;

use App\Models\Direction;
use App\Models\SmmIdea;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Доступ к разделу SMM. Права: smm.read / smm.create / smm.approve. Исполнитель видит раздел,
 * только если его специальность — SMM-видео (SMM, видеограф, монтажёр, мобилограф, брендфейс);
 * с областью OWN — идеи, где он ответственный или автор. Чужая идея — 404.
 */
final class SmmAccess
{
    /** Специальности исполнителей, которые делают ролики. */
    public const SPECIALTIES = ['SMM', 'VIDEOGRAPHER', 'EDITOR', 'MOBILOGRAPHER', 'BRANDFACE'];

    /** Направление, проект-менеджеры которого утверждают идеи. */
    public const DIRECTION = 'MEDIA';

    public static function isSmmExecutor(?User $user): bool
    {
        if (! $user) {
            return false;
        }
        $user->loadMissing(['role', 'employee']);

        return $user->role?->code === 'EXECUTOR' && in_array($user->employee?->specialty, self::SPECIALTIES, true);
    }

    /** Право на раздел: smm.read и (для исполнителя) SMM-специальность — иначе 403. */
    public static function authorize(string $code = 'smm.read'): void
    {
        $access = access();
        $access->authorize($code);
        if ($access->roleCode === 'EXECUTOR' && ! self::isSmmExecutor($access->user)) {
            throw new HttpException(403);
        }
    }

    /** Может ли текущий пользователь открыть раздел (для меню и ссылок). */
    public static function allowed(): bool
    {
        $access = access(false);
        if (! $access || ! $access->can('smm.read')) {
            return false;
        }

        return $access->roleCode !== 'EXECUTOR' || self::isSmmExecutor($access->user);
    }

    /** Идеи в зоне видимости: OWN — свои (ответственный или автор), TEAM — ещё и сотрудников своих отделов. */
    public static function ideas(): Builder
    {
        self::authorize();

        return access()->restrict(
            SmmIdea::query(),
            'smm.read',
            fn ($q, $uid) => $q->where('assignee_id', $uid)->orWhere('author_id', $uid),
            fn ($q, $teamIds, $uid) => $q->where('assignee_id', $uid)->orWhere('author_id', $uid)
                ->orWhereIn('assignee_id', fn ($u) => $u->select('id')->from('users')->whereIn('team_id', $teamIds ?: ['-'])),
        );
    }

    public static function idea(string $id): SmmIdea
    {
        $idea = self::ideas()->whereKey($id)->first();
        abort_if(! $idea, 404);

        return $idea;
    }

    public static function canApprove(): bool
    {
        return access()->can('smm.approve');
    }

    /** Какую подпись ставит утверждающий: CEO — подпись CEO, остальные с правом smm.approve — проект-менеджера. */
    public static function approverSlot(): string
    {
        return access()->roleCode === 'CEO' ? 'ceo' : 'pm';
    }

    /** Исполнители, которых можно назначить ответственными (активные, SMM-специальность). */
    public static function assignees(?string $keepId = null): Collection
    {
        return User::query()->where(fn ($q) => $q
            ->where(fn ($w) => $w->where('status', 'ACTIVE')
                ->whereHas('role', fn ($r) => $r->where('code', 'EXECUTOR'))
                ->whereHas('employee', fn ($e) => $e->whereIn('specialty', self::SPECIALTIES)))
            ->when($keepId, fn ($w) => $w->orWhere('id', $keepId)))
            ->orderBy('full_name')->get(['id', 'full_name']);
    }

    public static function isAssignable(string $userId): bool
    {
        return User::whereKey($userId)->where('status', 'ACTIVE')
            ->whereHas('role', fn ($r) => $r->where('code', 'EXECUTOR'))
            ->whereHas('employee', fn ($e) => $e->whereIn('specialty', self::SPECIALTIES))
            ->exists();
    }

    /**
     * Проект-менеджеры, отвечающие за SMM: активные PROJECT_MANAGER с направлением MEDIA;
     * если таких нет — все активные проект-менеджеры.
     *
     * @return list<string>
     */
    public static function projectManagerIds(): array
    {
        $all = User::where('status', 'ACTIVE')->whereHas('role', fn ($q) => $q->where('code', 'PROJECT_MANAGER'))->pluck('id')->all();
        if (! $all) {
            return [];
        }
        $media = Direction::where('code', self::DIRECTION)->value('id');
        $responsible = $media ? DB::table('user_directions')->where('direction_id', $media)->whereIn('user_id', $all)->pluck('user_id')->all() : [];

        return $responsible ?: $all;
    }
}
