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

    /** @param  array{owner_id?:?string, team_id?:?string, service_id?:?string, source_id?:?string}  $f */
    public static function board(array $f): array
    {
        $access = access();
        $canLeads = $access->can('lead.read');
        $canDeals = $access->can('deal.read');
        if (! $canLeads && ! $canDeals) {
            abort(403);
        }
        $common = fn ($q) => $q->where('status', 'OPEN')
            ->when($f['owner_id'] ?? null, fn ($q, $v) => $q->where('owner_id', $v))
            ->when($f['team_id'] ?? null, fn ($q, $v) => $q->where('team_id', $v))
            ->when($f['service_id'] ?? null, fn ($q, $v) => $q->where('service_id', $v));
        $stages = Crm::stages();
        $leads = $canLeads
            ? $common(CrmAccess::leads()->with('owner'))->when($f['source_id'] ?? null, fn ($q, $v) => $q->where('source_id', $v))
                ->orderByDesc('updated_at')->get()
            : collect();
        $deals = $canDeals
            ? $common(CrmAccess::deals()->with(['owner', 'client']))
                ->when($f['source_id'] ?? null, fn ($q, $v) => $q->whereHas('client', fn ($c) => $c->where('source_id', $v)))
                ->orderByDesc('updated_at')->get()
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
                    'owner' => explode(' ', (string) $l->owner?->full_name)[0], 'scoreLevel' => $l->score_level,
                    'scoreLabel' => t('crm.score.'.$l->score_level), 'nextContactAt' => $l->next_contact_at ? Format::dateTime($l->next_contact_at) : null,
                ]);
            } else {
                $rows = $deals->where('stage_id', $stage->id)->values();
                $amounts = $rows->map(fn (Deal $d) => (string) $d->amount_uzs)->all();
                $cards = $rows->map(fn (Deal $d) => [
                    'id' => $d->id, 'kind' => 'deal', 'number' => Format::code('D', $d->number), 'title' => $d->title,
                    'subtitle' => $d->client?->name, 'href' => route('crm.deals.show', $d->id),
                    'amount' => Format::moneyShort((string) $d->amount, $d->currency),
                    'owner' => explode(' ', (string) $d->owner?->full_name)[0], 'scoreLevel' => null, 'scoreLabel' => null, 'nextContactAt' => null,
                ]);
            }
            $columns[] = [
                'code' => $stage->code, 'entity' => $stage->entity, 'name' => $stage->label(), 'color' => $stage->color,
                'probability' => $stage->probability,
                'items' => $cards->take(self::CARDS_PER_COLUMN)->values()->all(),
                'count' => $cards->count(),
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
}
