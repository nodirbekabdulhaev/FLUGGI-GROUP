<?php

namespace App\Services\Crm;

use App\Domain\Forecast;
use App\Domain\Money;
use App\Models\Deal;
use App\Models\Lead;
use App\Support\Format;

/** Единая доска воронки: этапы лида, затем этапы сделки (ТЗ §7). */
final class PipelineService
{
    public const CARDS_PER_COLUMN = 100;

    /** Этапы, в которых можно сразу создать лид (без «ворот» — встреча назначена/проведена требуют встречу). */
    public const LEAD_CREATE_STAGES = ['NEW', 'CONTACTED', 'QUALIFICATION'];

    /** Новая сделка всегда начинается с первого этапа — остальные этапы сделки за «воротами». */
    public const DEAL_CREATE_STAGES = ['NEED_DEFINED'];

    /**
     * Доска: этапы лидов, затем этапы сделок. $only — только лиды (LEAD) или только сделки (DEAL) —
     * для Kanban на страницах «Лиды» и «Сделки»; тогда $f — фильтры этих списков (статус всегда OPEN).
     *
     * @param  array{owner_id?:?string, team_id?:?string, service_id?:?string, source_id?:?string}  $f
     */
    public static function board(array $f, ?string $only = null): array
    {
        $access = access();
        $canLeads = $access->can('lead.read') && $only !== 'DEAL';
        $canDeals = $access->can('deal.read') && $only !== 'LEAD';
        if (! $canLeads && ! $canDeals) {
            abort(403);
        }
        $open = array_merge($f, ['status' => 'OPEN']);
        $stages = Crm::stages();
        $leads = $canLeads
            ? LeadService::filtered(CrmAccess::leads()->with('owner'), $open)->orderByDesc('updated_at')->get()
            : collect();
        $deals = $canDeals
            ? DealService::filtered(CrmAccess::deals()->with(['owner', 'client']), $open)->orderByDesc('updated_at')->get()
            : collect();

        $columns = [];
        foreach ($stages as $stage) {
            if (($stage->entity === 'LEAD' && ! $canLeads) || ($stage->entity === 'DEAL' && ! $canDeals)) {
                continue;
            }
            if ($stage->entity === 'LEAD') {
                $rows = $leads->where('stage_id', $stage->id)->values();
                $amounts = $rows->map(fn (Lead $l) => (string) ($l->budget_uzs ?? '0'))->all();
                $cards = $rows->map(fn (Lead $l) => [
                    'id' => $l->id, 'kind' => 'lead', 'number' => Format::code('L', $l->number), 'title' => $l->title,
                    'subtitle' => $l->company_name ?? $l->contact_name, 'href' => route('crm.leads.show', $l->id),
                    'amount' => $l->budget !== null ? Format::moneyShort((string) $l->budget, $l->currency) : '—',
                    'owner' => explode(' ', (string) $l->owner?->full_name)[0], 'initials' => self::initials($l->owner?->full_name),
                    'scoreLevel' => $l->score_level, 'scoreLabel' => t('crm.score.'.$l->score_level),
                    'nextContactAt' => $l->next_contact_at ? Format::dateTime($l->next_contact_at) : null,
                    'priority' => $l->priority, 'priorityLabel' => $l->priority ? t('crm.priority.'.$l->priority) : null,
                ]);
            } else {
                $rows = $deals->where('stage_id', $stage->id)->values();
                $amounts = $rows->map(fn (Deal $d) => (string) $d->amount_uzs)->all();
                $cards = $rows->map(fn (Deal $d) => [
                    'id' => $d->id, 'kind' => 'deal', 'number' => Format::code('D', $d->number), 'title' => $d->title,
                    'subtitle' => $d->client?->name, 'href' => route('crm.deals.show', $d->id),
                    'amount' => Format::moneyShort((string) $d->amount, $d->currency),
                    'owner' => explode(' ', (string) $d->owner?->full_name)[0], 'initials' => self::initials($d->owner?->full_name),
                    'scoreLevel' => null, 'scoreLabel' => null,
                    'nextContactAt' => $d->expected_close_date ? Format::date($d->expected_close_date) : null,
                    'priority' => null, 'priorityLabel' => null,
                ]);
            }
            $columns[] = [
                'code' => $stage->code, 'entity' => $stage->entity, 'name' => $stage->label(), 'color' => $stage->color,
                'probability' => $stage->probability,
                'items' => $cards->take(self::CARDS_PER_COLUMN)->values()->all(),
                'count' => $cards->count(),
                'canCreate' => $stage->entity === 'LEAD'
                    ? in_array($stage->code, self::LEAD_CREATE_STAGES, true) && $access->can('lead.create')
                    : in_array($stage->code, self::DEAL_CREATE_STAGES, true) && $access->can('deal.create') && $access->can('client.read'),
                'total' => Format::moneyShort((string) Money::sum($amounts)->toScale(2)),
            ];
        }
        $prob = $stages->pluck('probability', 'id');
        $forecast = Forecast::forecast($deals->map(fn (Deal $d) => [
            'amountUzs' => (string) $d->amount_uzs,
            'probability' => $d->probability_override ?? $prob[$d->stage_id] ?? 0,
        ])->values()->all());

        return [
            'columns' => $columns,
            'pipeline' => Format::moneyShort((string) $forecast['pipeline']),
            'weighted' => Format::moneyShort((string) $forecast['weighted']),
            'pipelineUzs' => (string) $forecast['pipeline'],
            'weightedUzs' => (string) $forecast['weighted'],
        ];
    }

    /** Инициалы для аватара на карточке: «Иван Петров» → «ИП». */
    private static function initials(?string $name): string
    {
        $parts = preg_split('/\s+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return mb_strtoupper(implode('', array_map(fn ($p) => mb_substr($p, 0, 1), array_slice($parts, 0, 2))));
    }
}
