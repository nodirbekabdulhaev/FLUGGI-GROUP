<?php

namespace App\Services\Crm;

use App\Models\DealStage;
use App\Models\LeadSource;
use App\Models\LossReason;
use App\Models\Service;
use App\Models\User;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Общее для экранов и сервисов CRM: коды этапов и статусов, справочники для форм,
 * список ответственных, ключи для поиска дублей.
 */
final class Crm
{
    public const LEAD_STAGES = ['NEW', 'CONTACTED', 'QUALIFICATION', 'MEETING_SCHEDULED', 'MEETING_DONE'];

    public const LEAD_STATUSES = ['OPEN', 'CONVERTED', 'LOST', 'REJECTED', 'PAUSED', 'NO_RESPONSE'];

    public const DEAL_STATUSES = ['OPEN', 'WON', 'LOST', 'REJECTED', 'PAUSED', 'NO_RESPONSE'];

    /** Финальные статусы, в которые запись переводится вручную (ТЗ §7). */
    public const CLOSE_STATUSES = ['LOST', 'REJECTED', 'PAUSED', 'NO_RESPONSE'];

    /** Для этих статусов причина обязательна (ТЗ §39). */
    public const REASON_REQUIRED = ['LOST', 'REJECTED'];

    public const PRIORITIES = ['LOW', 'MEDIUM', 'HIGH', 'URGENT'];

    public const COMPANY_SIZES = ['SOLO', 'SMALL', 'MEDIUM', 'LARGE'];

    public const SCORE_LEVELS = ['LOW', 'MEDIUM', 'HIGH', 'HOT'];

    public const CURRENCIES = ['UZS', 'USD'];

    public const CLIENT_TYPES = ['COMPANY', 'PERSON'];

    public const MEETING_TYPES = ['ONLINE', 'OFFLINE', 'PHONE', 'TELEGRAM', 'GOOGLE_MEET', 'ZOOM'];

    public const MEETING_STATUSES = ['SCHEDULED', 'CONFIRMED', 'DONE', 'RESCHEDULED', 'CANCELLED', 'NO_SHOW'];

    /** Статусы, которые можно поставить при изменении встречи («Проведена» — только с результатом). */
    public const MEETING_EDIT_STATUSES = ['SCHEDULED', 'CONFIRMED', 'RESCHEDULED', 'CANCELLED', 'NO_SHOW'];

    /** Встреча ещё впереди. */
    public const OPEN_MEETING = ['SCHEDULED', 'CONFIRMED', 'RESCHEDULED'];

    public const PRICING_TYPES = ['FIXED', 'MONTHLY', 'HOURLY', 'CUSTOM'];

    public static function stage(string $code): DealStage
    {
        return DealStage::where('code', $code)->first() ?? throw new NotFoundHttpException;
    }

    /** @return Collection<int, DealStage> */
    public static function stages(?string $entity = null): Collection
    {
        return DealStage::query()->when($entity, fn ($q) => $q->where('entity', $entity))->orderBy('sort')->get();
    }

    /** Справочник для <select>: [id => название]; неактивные — только если уже выбраны. */
    public static function options(string $model, ?string $keep = null): array
    {
        /** @var class-string<Service|LeadSource|LossReason> $model */
        return $model::query()->where(fn ($q) => $q->where('is_active', true)->when($keep, fn ($q) => $q->orWhere('id', $keep)))
            ->orderBy('sort')->orderBy('name_ru')->get()
            ->mapWithKeys(fn ($r) => [$r->id => $r->label()])->all();
    }

    /** Все записи справочника (для фильтров). */
    public static function allOptions(string $model): array
    {
        return $model::query()->orderBy('sort')->orderBy('name_ru')->get()->mapWithKeys(fn ($r) => [$r->id => $r->label()])->all();
    }

    /** [код => подпись] из группы переводов. */
    public static function enum(array $codes, string $prefix): array
    {
        return collect($codes)->mapWithKeys(fn ($c) => [$c => t($prefix.'.'.$c)])->all();
    }

    /**
     * Сотрудники для списков «Ответственный»: активные менеджеры и РОП в зоне видимости
     * (право employee.read). В фильтрах ($withCeo) — ещё и CEO: старые записи могли быть на нём.
     */
    public static function assignableUsers(bool $withCeo = false): array
    {
        $access = access();
        if (! $access->can('employee.read')) {
            return [];
        }
        $roles = $withCeo ? [...CrmAccess::OWNER_ROLES, 'CEO'] : CrmAccess::OWNER_ROLES;
        $q = User::query()->with('team')->where('status', 'ACTIVE')
            ->whereHas('role', fn ($q) => $q->whereIn('code', $roles))->orderBy('full_name');
        $scope = $access->scope('employee.read');
        if ($scope === 'TEAM') {
            $q->where(fn ($q) => $q->whereIn('team_id', $access->teamIds() ?: ['-'])->orWhere('id', $access->id()));
        } elseif ($scope === 'OWN') {
            $q->where('id', $access->id());
        }

        return $q->get()->mapWithKeys(fn (User $u) => [$u->id => $u->full_name.($u->team ? ' · '.$u->team->name : '')])->all();
    }

    /** Телефон для сравнения: последние 9 цифр (+998 90 123-45-67 → 901234567). */
    public static function phoneKey(?string $v): ?string
    {
        $d = preg_replace('/\D/', '', (string) $v);

        return strlen($d) >= 7 ? substr($d, -9) : null;
    }

    /** Instagram для сравнения: без @, ссылки и регистра. */
    public static function igKey(?string $v): ?string
    {
        $s = trim((string) $v);
        $s = preg_replace('#^https?://(www\.)?instagram\.com/#i', '', $s);
        $s = preg_replace('#/.*$#', '', ltrim($s, '@'));
        $s = mb_strtolower($s);

        return $s !== '' ? $s : null;
    }

    public static function emailKey(?string $v): ?string
    {
        $s = mb_strtolower(trim((string) $v));

        return $s !== '' ? $s : null;
    }

    /** Начало дня по Ташкенту (YYYY-MM-DD) в UTC. */
    public static function dayStart(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date.' 00:00:00', Format::tz())->utc();
    }

    /** Дата без времени для DATE-колонки. */
    public static function dateOnly(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }

        return $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : substr((string) $v, 0, 10);
    }
}
