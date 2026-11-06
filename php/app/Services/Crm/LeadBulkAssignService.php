<?php

namespace App\Services\Crm;

use App\Exceptions\BusinessRule;
use App\Models\Client;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Meeting;
use App\Models\Team;
use App\Models\Todo;
use App\Models\User;
use App\Support\Audit;
use App\Support\Outbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Массовая передача лидов другому менеджеру (по выбранным, по фильтру списка или «все лиды менеджера»).
 * Правило выбора нового ответственного — как у одиночной передачи (CrmAccess::assignableOwner).
 * Область lead.assign проверяется для каждого лида: чужие пропускаются и попадают в отчёт.
 * История не меняется: таймлайн, история этапов, комментарии остаются; к ним добавляется
 * событие «Передан от A к B (массовая передача)». Уведомление — одно на передачу (lead.bulk_assigned),
 * поштучные lead.assigned помечены bulk и уведомлений не создают.
 */
final class LeadBulkAssignService
{
    /** Не больше лидов за одну передачу (время запроса на виртуальном хостинге). */
    public const MAX = 2000;

    private const CHUNK = 200;

    /**
     * Лиды-кандидаты. ids — как прислано (область проверяется позже), filter — видимые пользователю по фильтру списка.
     *
     * @param  array{mode:string, ids?:array, filter?:array}  $sel
     * @return list<string>
     */
    public static function candidateIds(array $sel): array
    {
        if ($sel['mode'] === 'ids') {
            return array_values(array_unique(array_map('strval', $sel['ids'] ?? [])));
        }
        $q = LeadService::filtered(CrmAccess::leads(), $sel['filter'] ?? []);
        $count = (clone $q)->count();
        if ($count > self::MAX) {
            throw new BusinessRule(t('leads.bulk.tooMany', ['max' => self::MAX, 'count' => $count]), ['owner_id' => t('leads.bulk.narrow')]);
        }

        return $q->orderBy('leads.created_at')->pluck('leads.id')->all();
    }

    /**
     * Сколько будет передано: всего, нет доступа (вне области lead.assign), уже у выбранного менеджера.
     *
     * @return array{total:int, transferable:int, noAccess:int, same:int}
     */
    public static function preview(array $sel, ?string $targetId): array
    {
        $ids = self::candidateIds($sel);
        $owners = self::assignable($ids);
        $same = $targetId ? count(array_filter($owners, fn ($o) => $o === $targetId)) : 0;

        return [
            'total' => count($ids),
            'transferable' => count($owners) - $same,
            'noAccess' => count($ids) - count($owners),
            'same' => $same,
        ];
    }

    /**
     * Передача. $opts: deals — открытые сделки этих лидов, clients — клиенты, tasks — будущие встречи и открытые дела.
     * Переносится только то, что принадлежит прежнему ответственному лида и доступно действующему по его правам.
     *
     * @return array{batchId:string, moved:int, noAccess:int, same:int, deals:int, clients:int, meetings:int, todos:int, ownerId:string}
     */
    public function run(array $sel, string $targetId, ?string $comment, array $opts): array
    {
        $owner = CrmAccess::assignableOwner($targetId, 'lead.assign');
        $ids = self::candidateIds($sel);
        if (count($ids) > self::MAX) {
            throw new BusinessRule(t('leads.bulk.tooMany', ['max' => self::MAX, 'count' => count($ids)]), ['ids' => t('leads.bulk.narrow')]);
        }
        $assignable = self::assignable($ids);
        $comment = filled($comment) ? trim($comment) : null;
        $opts = ['deals' => ! empty($opts['deals']), 'clients' => ! empty($opts['clients']), 'tasks' => ! empty($opts['tasks'])];
        $r = ['batchId' => (string) Str::uuid(), 'moved' => 0, 'noAccess' => count($ids) - count($assignable), 'same' => 0,
            'deals' => 0, 'clients' => 0, 'meetings' => 0, 'todos' => 0, 'ownerId' => $owner->id];
        $fromCounts = [];
        $team = $owner->team_id ? Team::find($owner->team_id) : null;
        $ctx = ['owner' => $owner, 'ropId' => $team?->head_id, 'comment' => $comment, 'opts' => $opts, 'batchId' => $r['batchId']];

        try {
            foreach (array_chunk(array_keys($assignable), self::CHUNK) as $chunk) {
                DB::transaction(function () use ($chunk, $ctx, &$r, &$fromCounts) {
                    $this->chunk($chunk, $ctx, $r, $fromCounts);
                });
            }
        } finally {
            if ($r['moved'] > 0) {
                DB::transaction(fn () => $this->summary($r, $fromCounts, $opts, $comment));
            }
        }

        return $r;
    }

    /**
     * Лиды из списка, которые пользователь вправе передавать (область lead.assign): id → текущий ответственный.
     *
     * @param  list<string>  $ids
     * @return array<string,string>
     */
    private static function assignable(array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            $out += CrmAccess::leads('lead.assign')->whereIn('leads.id', $chunk)->pluck('owner_id', 'leads.id')->all();
        }

        return $out;
    }

    /** Одна пачка в транзакции: всё связанное собирается и проверяется до изменения лидов. */
    private function chunk(array $ids, array $ctx, array &$r, array &$fromCounts): void
    {
        /** @var User $owner */
        $owner = $ctx['owner'];
        $access = access();
        $leads = Lead::whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');
        $prev = [];
        foreach ($leads as $lead) {
            if ($lead->owner_id === $owner->id) {
                $r['same']++;
                $leads->forget($lead->id);

                continue;
            }
            $prev[$lead->id] = $lead->owner_id;
        }
        if ($leads->isEmpty()) {
            return;
        }
        $names = User::withTrashed()->whereIn('id', array_unique($prev))->pluck('full_name', 'id')->all();
        $batch = ['old' => null, 'new' => $ctx['batchId']];

        // Сделки этих лидов: только открытые, у прежнего ответственного, в области изменения сделок
        $deals = collect();
        if ($ctx['opts']['deals'] && $access->can('deal.update')) {
            $dealLead = $leads->filter(fn ($l) => $l->deal_id)->mapWithKeys(fn ($l) => [$l->deal_id => $l->id])->all();
            $deals = $dealLead ? CrmAccess::deals('deal.update')->whereIn('deals.id', array_keys($dealLead))->where('status', 'OPEN')
                ->lockForUpdate()->get()->filter(fn (Deal $d) => $d->owner_id === $prev[$dealLead[$d->id]])->keyBy('id') : collect();
        }
        $clients = collect();
        if ($ctx['opts']['clients'] && $access->can('client.update')) {
            $clientPrev = [];
            foreach ($leads as $l) {
                if ($l->client_id) {
                    $clientPrev[$l->client_id] ??= $prev[$l->id];
                }
            }
            $clients = $clientPrev ? CrmAccess::clients('client.update')->whereIn('clients.id', array_keys($clientPrev))
                ->lockForUpdate()->get()->filter(fn (Client $c) => $c->owner_id === $clientPrev[$c->id]) : collect();
        }
        $meetings = collect();
        $todos = collect();
        if ($ctx['opts']['tasks']) {
            $dealPrev = $deals->mapWithKeys(fn (Deal $d) => [$d->id => $d->owner_id])->all();
            $prevOf = fn ($row) => $row->lead_id && isset($prev[$row->lead_id]) ? $prev[$row->lead_id] : ($dealPrev[$row->deal_id] ?? null);
            $linked = fn ($q) => $q->where(fn ($w) => $w->whereIn('lead_id', array_keys($prev))
                ->when($dealPrev, fn ($w) => $w->orWhereIn('deal_id', array_keys($dealPrev))));
            if ($access->can('meeting.update')) {
                $meetings = $linked(Meeting::query())->whereIn('status', Crm::OPEN_MEETING)->where('starts_at', '>=', now())
                    ->lockForUpdate()->get()
                    ->filter(fn (Meeting $m) => $m->manager_id === $prevOf($m) && MeetingService::canEdit($m));
            }
            $todos = $linked(Todo::query())->where('status', 'OPEN')->lockForUpdate()->get()
                ->filter(fn (Todo $t) => $t->owner_id === $prevOf($t));
        }

        foreach ($leads as $lead) {
            $from = $prev[$lead->id];
            $lead->update(['owner_id' => $owner->id, 'team_id' => $owner->team_id]);
            Activities::log('lead.assigned', ['lead_id' => $lead->id], [
                'to' => $owner->full_name, 'from' => $names[$from] ?? null, 'comment' => $ctx['comment'],
                'bulk' => true, 'batchId' => $ctx['batchId'],
            ]);
            Audit::log('lead.assign', 'lead', $lead->id, array_filter([
                'owner_id' => ['old' => $from, 'new' => $owner->id],
                'bulk_batch' => $batch,
                'comment' => $ctx['comment'] !== null ? ['old' => null, 'new' => $ctx['comment']] : null,
            ]));
            Outbox::publish('lead.assigned', [
                'leadId' => $lead->id, 'ownerId' => $owner->id, 'previousOwnerId' => $from, 'bulk' => true, 'batchId' => $ctx['batchId'],
            ]);
            $fromCounts[$from] = ($fromCounts[$from] ?? 0) + 1;
            $r['moved']++;
        }
        foreach ($deals as $deal) {
            $from = $deal->owner_id;
            $deal->update(['owner_id' => $owner->id, 'team_id' => $owner->team_id]);
            Activities::log('deal.assigned', ['deal_id' => $deal->id], [
                'to' => $owner->full_name, 'from' => $names[$from] ?? null, 'comment' => $ctx['comment'], 'bulk' => true, 'batchId' => $ctx['batchId'],
            ]);
            Audit::log('deal.assign', 'deal', $deal->id, ['owner_id' => ['old' => $from, 'new' => $owner->id], 'bulk_batch' => $batch]);
            $r['deals']++;
        }
        foreach ($clients as $client) {
            $from = $client->owner_id;
            $client->update(['owner_id' => $owner->id, 'team_id' => $owner->team_id]);
            Activities::log('client.assigned', ['client_id' => $client->id], [
                'to' => $owner->full_name, 'from' => $names[$from] ?? null, 'comment' => $ctx['comment'], 'bulk' => true, 'batchId' => $ctx['batchId'],
            ]);
            Audit::log('client.assign', 'client', $client->id, ['owner_id' => ['old' => $from, 'new' => $owner->id], 'bulk_batch' => $batch]);
            $r['clients']++;
        }
        foreach ($meetings as $m) {
            $from = $m->manager_id;
            $m->update(['manager_id' => $owner->id, 'team_id' => $owner->team_id, 'rop_id' => $ctx['ropId']]);
            // Новый ответственный — участник вместо прежнего (ответственный всегда среди участников)
            DB::table('meeting_participants')->where('meeting_id', $m->id)->where('user_id', $from)->delete();
            if (! DB::table('meeting_participants')->where('meeting_id', $m->id)->where('user_id', $owner->id)->exists()) {
                DB::table('meeting_participants')->insert(['id' => (string) Str::uuid(), 'meeting_id' => $m->id, 'user_id' => $owner->id, 'created_at' => now()->format('Y-m-d H:i:s.v')]);
            }
            Activities::log('meeting.updated', ['lead_id' => $m->lead_id, 'deal_id' => $m->deal_id, 'meeting_id' => $m->id], [
                'changes' => ['manager_id' => ['old' => $names[$from] ?? null, 'new' => $owner->full_name]], 'bulk' => true, 'batchId' => $ctx['batchId'],
            ]);
            Audit::log('meeting.update', 'meeting', $m->id, ['manager_id' => ['old' => $from, 'new' => $owner->id], 'bulk_batch' => $batch]);
            $r['meetings']++;
        }
        foreach ($todos as $t) {
            $from = $t->owner_id;
            $t->update(['owner_id' => $owner->id]);
            Audit::log('todo.update', 'todo', $t->id, ['owner_id' => ['old' => $from, 'new' => $owner->id], 'bulk_batch' => $batch]);
            $r['todos']++;
        }
    }

    /** Итоговая запись аудита и одно событие для уведомлений. */
    private function summary(array $r, array $fromCounts, array $opts, ?string $comment): void
    {
        $single = count($fromCounts) === 1 ? array_key_first($fromCounts) : null;
        Audit::log('lead.bulk_assign', 'lead', null, [
            'batch' => ['old' => null, 'new' => $r['batchId']],
            'from' => ['old' => null, 'new' => $single ?? array_keys($fromCounts)],
            'to' => ['old' => null, 'new' => $r['ownerId']],
            'count' => ['old' => null, 'new' => $r['moved']],
            'skipped' => ['old' => null, 'new' => ['noAccess' => $r['noAccess'], 'same' => $r['same']]],
            'options' => ['old' => null, 'new' => $opts],
            'moved' => ['old' => null, 'new' => ['deals' => $r['deals'], 'clients' => $r['clients'], 'meetings' => $r['meetings'], 'todos' => $r['todos']]],
            'comment' => ['old' => null, 'new' => $comment],
        ]);
        Outbox::publish('lead.bulk_assigned', [
            'batchId' => $r['batchId'], 'fromOwnerId' => $single, 'toOwnerId' => $r['ownerId'], 'count' => $r['moved'],
            'byUserId' => access()->id(), 'fromOwners' => $fromCounts, 'comment' => $comment,
            'deals' => $r['deals'], 'clients' => $r['clients'], 'meetings' => $r['meetings'], 'todos' => $r['todos'],
        ]);
    }

    /**
     * Менеджеры, чьи лиды пользователь может передавать (в т.ч. заблокированные и уволенные — их лиды и передают).
     *
     * @return array<string,string>
     */
    public static function fromOwners(): array
    {
        return User::withTrashed()->whereIn('id', CrmAccess::leads('lead.assign')->select('owner_id'))
            ->orderBy('full_name')->get(['id', 'full_name', 'status', 'deleted_at'])
            ->mapWithKeys(fn (User $u) => [$u->id => $u->full_name.($u->status !== 'ACTIVE' || $u->deleted_at ? ' ('.t('leads.bulk.inactive').')' : '')])
            ->all();
    }

    /** Ссылка на список: лиды этой передачи у нового ответственного. */
    public static function link(string $ownerId, string $batchId): string
    {
        return '/sales/leads?'.http_build_query(['owner_id' => $ownerId, 'batch' => $batchId, 'status' => 'any']);
    }

    /** Выборка «все лиды менеджера»: открытые или все, включая закрытые. */
    public static function ownerFilter(string $fromOwnerId, string $status): array
    {
        return ['owner_id' => $fromOwnerId, 'status' => $status === 'all' ? 'any' : 'OPEN'];
    }
}
