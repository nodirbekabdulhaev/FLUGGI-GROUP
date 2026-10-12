<?php

namespace App\Services\Automation;

use App\Auth\Access;
use App\Domain\Tasks;
use App\Exceptions\BusinessRule;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\FollowUp;
use App\Models\Project;
use App\Services\Crm\Activities;
use App\Services\Crm\CrmAccess;
use App\Services\References\ExchangeRates;
use App\Support\Audit;
use App\Support\Outbox;
use App\Support\Settings;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Повторные продажи (ТЗ §38, Rule 8): follow-up после завершения проекта. */
final class FollowUpService
{
    public const KINDS = ['CONTACT', 'NEW_PROJECT', 'REPEAT_SALE'];

    /** Создаёт follow-up по интервалам из настроек; повторный вызов для проекта ничего не делает. */
    public static function createForProject(string $projectId): int
    {
        return DB::transaction(function () use ($projectId) {
            $project = Project::lockForUpdate()->find($projectId);
            if (! $project || $project->status !== 'COMPLETED') {
                return 0;
            }
            if (FollowUp::where('project_id', $projectId)->exists()) {
                return 0;
            }
            $intervals = Settings::automation()['followUps'] ?? [];
            $start = Tasks::companyDate($project->completed_at ?? now());
            foreach ($intervals as $f) {
                FollowUp::create([
                    'client_id' => $project->client_id,
                    'project_id' => $projectId,
                    'owner_id' => $project->manager_id,
                    'kind' => $f['kind'],
                    'due_date' => Tasks::addDays($start, (int) $f['days']),
                ]);
            }

            return count($intervals);
        });
    }

    /** Свои follow-up и follow-up клиентов в области client.read. */
    public static function scope(Access $a): Builder
    {
        $a->scope('client.read');

        return FollowUp::query()->where(fn ($q) => $q->where('owner_id', $a->id())
            ->orWhereIn('client_id', CrmAccess::clients()->select('clients.id')));
    }

    public static function list(Access $a, string $view, int $perPage = 25): LengthAwarePaginator
    {
        $today = Tasks::companyDate(now());

        return self::scope($a)
            ->with(['client:id,name', 'project:id,number,name', 'owner:id,full_name', 'resultDeal:id,number,title'])
            ->when($view === 'due', fn ($q) => $q->where('status', 'PENDING')->where('due_date', '<=', $today))
            ->when($view === 'pending', fn ($q) => $q->where('status', 'PENDING'))
            ->when($view === 'closed', fn ($q) => $q->whereIn('status', ['DONE', 'SKIPPED']))
            ->orderBy('status')->orderBy('due_date')
            ->paginate($perPage)->withQueryString();
    }

    public static function overdueDays(FollowUp $f): int
    {
        return Tasks::projectOverdueDays($f->due_date?->format('Y-m-d'), $f->status === 'PENDING', now());
    }

    /** Отметить выполненным / пропустить; можно сразу создать сделку «Повторная продажа». */
    public static function complete(Access $a, string $id, string $status, ?string $result, bool $createDeal): FollowUp
    {
        $f = self::scope($a)->with(['project.deal'])->whereKey($id)->firstOrFail();
        if ($f->status !== 'PENDING') {
            throw new BusinessRule(t('followUps.errors.closed'));
        }
        if ($createDeal && $status !== 'DONE') {
            throw new BusinessRule(t('followUps.errors.dealOnlyDone'));
        }

        return DB::transaction(function () use ($a, $f, $status, $result, $createDeal) {
            $dealId = $createDeal ? self::createRepeatDeal($a, $f) : null;
            $f->update([
                'status' => $status,
                'result' => $result ?: null,
                'result_deal_id' => $dealId,
                'completed_at' => now(),
                'completed_by_id' => $a->id(),
            ]);
            Audit::log('followup.complete', 'follow_up', $f->id, [
                'status' => ['old' => 'PENDING', 'new' => $status],
                'dealId' => ['old' => null, 'new' => $dealId],
            ]);

            return $f->fresh(['client', 'project', 'owner', 'resultDeal']);
        });
    }

    /** Сделка «Повторная продажа» у клиента — по правилам создания сделки (как в CRM). */
    private static function createRepeatDeal(Access $a, FollowUp $f): string
    {
        $client = CrmAccess::client($f->client_id);
        $owner = CrmAccess::assignableOwner($client->owner_id, 'deal.create');
        $project = $f->project;
        $amount = $project ? (string) $project->price_uzs : '0';
        $conv = ExchangeRates::convert($amount, 'UZS');
        $stage = DealStage::where('code', 'NEED_DEFINED')->firstOrFail();
        $deal = Deal::create([
            'title' => t('followUps.dealTitle').($project ? ' — '.$project->name : ''),
            'client_id' => $client->id,
            'owner_id' => $owner->id,
            'team_id' => $owner->team_id,
            'service_id' => $project?->deal?->service_id,
            'amount' => $amount,
            'currency' => 'UZS',
            'exchange_rate' => $conv['rate'],
            'amount_uzs' => $conv['amountUzs'],
            'stage_id' => $stage->id,
            'is_repeat' => true,
            'created_by_id' => $a->id(),
        ]);
        Activities::stageChange(null, $deal->id, null, $stage->id, $a->id());
        Activities::log('deal.created', ['deal_id' => $deal->id, 'client_id' => $client->id], ['amount' => $amount, 'currency' => 'UZS', 'client' => $client->name]);
        Audit::log('deal.create', 'deal', $deal->id, ['amount' => ['old' => null, 'new' => $amount], 'clientId' => ['old' => null, 'new' => $client->id]]);
        Outbox::publish('deal.created', ['dealId' => $deal->id, 'ownerId' => $owner->id, 'teamId' => $owner->team_id, 'amountUzs' => $conv['amountUzs']]);

        return $deal->id;
    }

    /** Задача планировщика: пришёл срок — уведомление менеджеру (один раз). */
    public static function notifyDue(?\DateTimeInterface $now = null): array
    {
        $now ??= now();
        $today = Tasks::companyDate($now);
        $due = FollowUp::with('client')->where('status', 'PENDING')->whereNull('notified_at')->where('due_date', '<=', $today)->get();
        foreach ($due as $f) {
            \App\Services\Notifications\Notifier::notify([$f->owner_id], 'followup.due', fn () => [t('followUps.kinds.'.$f->kind), $f->client?->name], '/sales/follow-ups');
            $f->update(['notified_at' => $now]);
        }

        return ['notified' => $due->count()];
    }
}
