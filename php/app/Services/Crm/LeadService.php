<?php

namespace App\Services\Crm;

use App\Domain\LeadScore;
use App\Domain\Money;
use App\Exceptions\BusinessRule;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\ExchangeRate;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LossReason;
use App\Models\Meeting;
use App\Models\Service;
use App\Services\References\ExchangeRates;
use App\Support\Audit;
use App\Support\Outbox;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Лиды (ТЗ §8): создание, изменение, распределение, этапы, закрытие с причиной,
 * квалификация в клиента и сделку (BUSINESS_RULES §2), пересчёт lead score (ТЗ §10).
 */
final class LeadService
{
    /** Поля, изменения которых видны в таймлайне и аудите. */
    public const TRACKED = [
        'title', 'contact_name', 'company_name', 'phone', 'telegram', 'email', 'source_id', 'service_id',
        'budget', 'currency', 'priority', 'desired_date', 'next_contact_at', 'company_size', 'interest',
    ];

    /** Поля, которые можно изменить формой редактирования (ответственный — через assign). */
    public const EDITABLE = [
        'title', 'contact_name', 'company_name', 'phone', 'telegram', 'whatsapp', 'instagram', 'email', 'website',
        'city', 'country', 'source_id', 'service_id', 'budget', 'currency', 'desired_date', 'priority',
        'company_size', 'interest', 'next_contact_at', 'comment',
    ];

    public function create(array $input): Lead
    {
        $access = access();
        $owner = CrmAccess::assignableOwner($input['owner_id'] ?? null, 'lead.create');
        $service = Service::where('id', $input['service_id'] ?? null)->where('is_active', true)->first();
        if (! $service) {
            throw new BusinessRule(t('leads.errors.serviceNotFound'), ['service_id' => t('leads.errors.selectService')]);
        }
        $source = LeadSource::where('id', $input['source_id'] ?? null)->where('is_active', true)->first();
        if (! $source) {
            throw new BusinessRule(t('leads.errors.sourceNotFound'), ['source_id' => t('leads.errors.selectSource')]);
        }
        $stage = Crm::stage('NEW');
        $currency = $input['currency'] ?? 'UZS';
        $budget = $input['budget'] ?? null;
        $budgetUzs = $budget !== null ? ExchangeRates::convert($budget, $currency)['amountUzs'] : null;
        $title = ($input['title'] ?? null) ?: (($input['company_name'] ?? null) ?: $input['contact_name']).' — '.$service->name_ru;

        return DB::transaction(function () use ($input, $access, $owner, $service, $source, $stage, $currency, $budget, $budgetUzs, $title) {
            $lead = Lead::create([
                'title' => $title,
                'contact_name' => $input['contact_name'] ?? null,
                'company_name' => $input['company_name'] ?? null,
                'phone' => $input['phone'] ?? null,
                'telegram' => $input['telegram'] ?? null,
                'whatsapp' => $input['whatsapp'] ?? null,
                'instagram' => $input['instagram'] ?? null,
                'email' => $input['email'] ?? null,
                'website' => $input['website'] ?? null,
                'city' => $input['city'] ?? null,
                'country' => $input['country'] ?? null,
                'source_id' => $source->id,
                'service_id' => $service->id,
                'owner_id' => $owner->id,
                'team_id' => $owner->team_id,
                'budget' => $budget,
                'currency' => $currency,
                'budget_uzs' => $budgetUzs,
                'desired_date' => $input['desired_date'] ?? null,
                'priority' => $input['priority'] ?? 'MEDIUM',
                'company_size' => $input['company_size'] ?? null,
                'interest' => $input['interest'] ?? null,
                'next_contact_at' => $input['next_contact_at'] ?? null,
                'comment' => $input['comment'] ?? null,
                'stage_id' => $stage->id,
                'created_by_id' => $access->id(),
            ]);
            self::rescore($lead->id);
            Activities::stageChange($lead->id, null, null, $stage->id, $access->id());
            Activities::log('lead.created', ['lead_id' => $lead->id], ['owner' => $owner->full_name, 'source' => $source->name_ru, 'service' => $service->name_ru]);
            Audit::log('lead.create', 'lead', $lead->id, ['title' => ['old' => null, 'new' => $title], 'owner_id' => ['old' => null, 'new' => $owner->id]]);
            Outbox::publish('lead.created', [
                'leadId' => $lead->id, 'ownerId' => $owner->id, 'teamId' => $owner->team_id,
                'createdById' => $access->id(), 'budgetUzs' => $budgetUzs,
            ]);

            return $lead->refresh();
        });
    }

    public function update(Lead $before, array $input): Lead
    {
        $data = array_intersect_key($input, array_flip(self::EDITABLE));
        if (array_key_exists('title', $data) && ! filled($data['title'])) {
            unset($data['title']);
        }
        if (array_key_exists('budget', $data) || array_key_exists('currency', $data)) {
            $budget = array_key_exists('budget', $data) ? $data['budget'] : $before->budget;
            $currency = $data['currency'] ?? $before->currency;
            $data['budget_uzs'] = $budget !== null && $budget !== '' ? ExchangeRates::convert((string) $budget, $currency)['amountUzs'] : null;
        }
        if (! empty($data['source_id']) && ! LeadSource::whereKey($data['source_id'])->exists()) {
            throw new BusinessRule(t('leads.errors.sourceNotFound'), ['source_id' => t('leads.errors.selectSource')]);
        }
        if (! empty($data['service_id']) && ! Service::whereKey($data['service_id'])->exists()) {
            throw new BusinessRule(t('leads.errors.serviceNotFound'), ['service_id' => t('leads.errors.selectService')]);
        }

        return DB::transaction(function () use ($before, $data) {
            $old = $before->getAttributes();
            $before->update($data);
            $after = $before->fresh();
            self::rescore($after->id);
            $changes = Audit::diff($old, self::comparable($after), self::TRACKED);
            if ($changes) {
                Activities::log('lead.updated', ['lead_id' => $after->id], ['changes' => $changes]);
                Audit::log('lead.update', 'lead', $after->id, $changes);
            }

            return $after->refresh();
        });
    }

    public function assign(Lead $lead, string $ownerId): Lead
    {
        $owner = CrmAccess::assignableOwner($ownerId, 'lead.assign');
        if ($lead->owner_id === $owner->id) {
            return $lead;
        }

        return DB::transaction(function () use ($lead, $owner) {
            $previous = $lead->owner_id;
            $lead->update(['owner_id' => $owner->id, 'team_id' => $owner->team_id]);
            Activities::log('lead.assigned', ['lead_id' => $lead->id], ['to' => $owner->full_name]);
            Audit::log('lead.assign', 'lead', $lead->id, ['owner_id' => ['old' => $previous, 'new' => $owner->id]]);
            Outbox::publish('lead.assigned', ['leadId' => $lead->id, 'ownerId' => $owner->id, 'previousOwnerId' => $previous]);

            return $lead->refresh();
        });
    }

    public function changeStage(Lead $lead, string $code): Lead
    {
        return DB::transaction(function () use ($lead, $code) {
            self::moveStage($lead, $code, access()->id(), audit: true);

            return $lead->refresh();
        });
    }

    /**
     * Переход лида по этапам с проверкой обязательных данных (BUSINESS_RULES §3).
     * Используется и встречами: назначение/проведение встречи двигает лид автоматически.
     * Вызывать внутри транзакции.
     */
    public static function moveStage(Lead $lead, string $code, string $actorId, bool $audit = false, bool $auto = false): void
    {
        if ($lead->status !== 'OPEN') {
            throw new BusinessRule(t('leads.errors.closed'));
        }
        if (! in_array($code, Crm::LEAD_STAGES, true)) {
            abort(404);
        }
        $target = Crm::stage($code);
        if ($target->id === $lead->stage_id) {
            return;
        }
        if ($code === 'MEETING_SCHEDULED' && ! Meeting::where('lead_id', $lead->id)->whereIn('status', Crm::OPEN_MEETING)->exists()) {
            throw new BusinessRule(t('leads.errors.meetingFirst'));
        }
        if ($code === 'MEETING_DONE' && ! Meeting::where('lead_id', $lead->id)->where('status', 'DONE')->exists()) {
            throw new BusinessRule(t('leads.errors.meetingDoneFirst'));
        }
        $from = DealStage::findOrFail($lead->stage_id);
        $lead->update(['stage_id' => $target->id] + ($code === 'CONTACTED' ? ['last_contact_at' => now()] : []));
        self::rescore($lead->id);
        Activities::stageChange($lead->id, null, $from->id, $target->id, $actorId);
        Activities::log('lead.stage_changed', ['lead_id' => $lead->id], ['from' => $from->name_ru, 'to' => $target->name_ru, 'auto' => $auto], $actorId);
        if ($audit) {
            Audit::log('lead.stage_change', 'lead', $lead->id, ['stage' => ['old' => $from->code, 'new' => $target->code]], $actorId);
        }
    }

    /** Двигает лид вперёд (не назад) — для автоматических переходов от встреч. */
    public static function advanceTo(string $leadId, string $code, string $actorId): void
    {
        $lead = Lead::with('stage')->findOrFail($leadId);
        if ($lead->status !== 'OPEN') {
            return;
        }
        $current = array_search($lead->stage->code, Crm::LEAD_STAGES, true);
        if ($current !== false && $current >= array_search($code, Crm::LEAD_STAGES, true)) {
            return;
        }
        self::moveStage($lead, $code, $actorId, auto: true);
    }

    /** Закрытие (ТЗ §7, §39): для «Потеряно» и «Отказ» причина обязательна, для некоторых причин — комментарий. */
    public function close(Lead $lead, array $input): Lead
    {
        if ($lead->status !== 'OPEN') {
            throw new BusinessRule(t('leads.errors.alreadyClosed'));
        }
        $reason = self::checkReason($input);

        return DB::transaction(function () use ($lead, $input, $reason) {
            $status = $input['status'];
            $lead->update([
                'status' => $status,
                'loss_reason_id' => $reason?->id,
                'loss_comment' => $input['comment'] ?? null,
                'closed_at' => now(),
            ]);
            Activities::log('lead.closed', ['lead_id' => $lead->id], ['status' => $status, 'reason' => $reason?->name_ru, 'comment' => $input['comment'] ?? null]);
            Audit::log('lead.close', 'lead', $lead->id, [
                'status' => ['old' => 'OPEN', 'new' => $status],
                'loss_reason' => ['old' => null, 'new' => $reason?->code],
            ]);
            Outbox::publish('lead.closed', ['leadId' => $lead->id, 'status' => $status]);

            return $lead->refresh();
        });
    }

    public function reopen(Lead $lead): Lead
    {
        if ($lead->status === 'OPEN') {
            return $lead;
        }
        if ($lead->status === 'CONVERTED') {
            throw new BusinessRule(t('leads.errors.alreadyConverted'));
        }

        return DB::transaction(function () use ($lead) {
            $from = $lead->status;
            $lead->update(['status' => 'OPEN', 'loss_reason_id' => null, 'loss_comment' => null, 'closed_at' => null]);
            Activities::log('lead.reopened', ['lead_id' => $lead->id], ['from' => $from]);
            Audit::log('lead.reopen', 'lead', $lead->id, ['status' => ['old' => $from, 'new' => 'OPEN']]);

            return $lead->refresh();
        });
    }

    /**
     * Квалификация: лид → клиент (новый или существующий) + сделка (BUSINESS_RULES §2).
     *
     * @return array{lead_id:string, client_id:string, deal_id:string}
     */
    public function convert(Lead $lead, array $input): array
    {
        $access = access();
        if (! $access->can('deal.create')) {
            throw new BusinessRule(t('leads.errors.noDealRight'));
        }
        if ($lead->status !== 'OPEN') {
            throw new BusinessRule(t('leads.errors.convertOnlyOpen'));
        }
        $existing = ! empty($input['client_id']) ? CrmAccess::client($input['client_id']) : null;
        if (! $existing && ! filled($input['client_name'] ?? null)) {
            throw new BusinessRule(t('leads.errors.clientRequired'), ['client_name' => t('leads.errors.clientRequired')]);
        }
        $currency = $input['currency'] ?? 'UZS';
        ['rate' => $rate, 'amountUzs' => $amountUzs] = ExchangeRates::convert($input['amount'], $currency);
        $dealStage = Crm::stage('NEED_DEFINED');
        $service = $lead->service_id ? Service::find($lead->service_id) : null;

        return DB::transaction(function () use ($lead, $input, $access, $existing, $currency, $rate, $amountUzs, $dealStage, $service) {
            $contactId = null;
            if ($existing) {
                $client = $existing;
            } else {
                $client = Client::create([
                    'name' => $input['client_name'],
                    'type' => $input['client_type'] ?? 'COMPANY',
                    'phone' => $lead->phone,
                    'email' => $lead->email,
                    'telegram' => $lead->telegram,
                    'website' => $lead->website,
                    'city' => $lead->city,
                    'country' => $lead->country,
                    'owner_id' => $lead->owner_id,
                    'team_id' => $lead->team_id,
                    'source_id' => $lead->source_id,
                ]);
                if ($lead->contact_name) {
                    $contactId = Contact::create([
                        'client_id' => $client->id,
                        'full_name' => $lead->contact_name,
                        'phone' => $lead->phone,
                        'telegram' => $lead->telegram,
                        'whatsapp' => $lead->whatsapp,
                        'instagram' => $lead->instagram,
                        'email' => $lead->email,
                        'is_primary' => true,
                    ])->id;
                }
            }
            $isRepeat = $existing ? Deal::withTrashed()->where('client_id', $client->id)->exists() : false;
            $deal = Deal::create([
                'title' => ($input['title'] ?? null) ?: ($service?->name_ru ?? $lead->title).' — '.$client->name,
                'client_id' => $client->id,
                'contact_id' => $contactId,
                'owner_id' => $lead->owner_id,
                'team_id' => $lead->team_id,
                'service_id' => $lead->service_id,
                'amount' => $input['amount'],
                'currency' => $currency,
                'exchange_rate' => $rate,
                'amount_uzs' => $amountUzs,
                'stage_id' => $dealStage->id,
                'expected_close_date' => $input['expected_close_date'] ?? null,
                'is_repeat' => $isRepeat,
                'created_by_id' => $access->id(),
            ]);
            $lead->update([
                'status' => 'CONVERTED', 'client_id' => $client->id, 'deal_id' => $deal->id,
                'converted_at' => now(), 'closed_at' => now(),
            ]);
            Activities::stageChange(null, $deal->id, null, $dealStage->id, $access->id());
            Activities::log('lead.converted', ['lead_id' => $lead->id, 'deal_id' => $deal->id, 'client_id' => $client->id], [
                'client' => $client->name, 'amount' => $input['amount'], 'currency' => $currency, 'newClient' => ! $existing,
            ]);
            Audit::log('lead.convert', 'lead', $lead->id, [
                'status' => ['old' => 'OPEN', 'new' => 'CONVERTED'],
                'deal_id' => ['old' => null, 'new' => $deal->id],
                'client_id' => ['old' => null, 'new' => $client->id],
            ]);
            Outbox::publish('lead.converted', ['leadId' => $lead->id, 'dealId' => $deal->id, 'clientId' => $client->id]);
            Outbox::publish('deal.created', ['dealId' => $deal->id, 'ownerId' => $deal->owner_id, 'teamId' => $deal->team_id, 'amountUzs' => $amountUzs]);

            return ['lead_id' => $lead->id, 'client_id' => $client->id, 'deal_id' => $deal->id];
        });
    }

    /** Удаление — мягкое; квалифицированный лид удалить нельзя. */
    public function remove(Lead $lead): void
    {
        if ($lead->status === 'CONVERTED') {
            throw new BusinessRule(t('leads.errors.cannotDeleteConverted'));
        }
        DB::transaction(function () use ($lead) {
            $lead->delete();
            Audit::log('lead.delete', 'lead', $lead->id, ['title' => ['old' => $lead->title, 'new' => null]]);
        });
    }

    /**
     * Возможные дубли (телефон — по последним 9 цифрам, Instagram — без @ и ссылки, email — без регистра)
     * среди лидов и клиентов, видимых пользователю. Предупреждение, а не запрет.
     *
     * @return array{leads: Collection<int, Lead>, clients: Collection<int, Client>}
     */
    public static function duplicates(?string $phone, ?string $instagram, ?string $email, ?string $exceptLeadId = null): array
    {
        $p = Crm::phoneKey($phone);
        $ig = Crm::igKey($instagram);
        $em = Crm::emailKey($email);
        $empty = ['leads' => collect(), 'clients' => collect()];
        if (! $p && ! $ig && ! $em) {
            return $empty;
        }
        // Цифры номера: убираем типичные разделители (+, пробел, дефис, скобки, точка)
        $digits = fn (string $col) => "RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE($col, ''), '+', ''), ' ', ''), '-', ''), '(', ''), ')', ''), '.', ''), 9)";
        $access = access();
        $leads = $access->can('lead.read')
            ? CrmAccess::leads()->with(['owner', 'stage'])
                ->when($exceptLeadId, fn ($q) => $q->where('id', '<>', $exceptLeadId))
                ->where(function ($q) use ($p, $ig, $em, $digits) {
                    if ($p) {
                        $q->orWhereRaw($digits('phone').' = ?', [$p])->orWhereRaw($digits('whatsapp').' = ?', [$p]);
                    }
                    if ($ig) {
                        $q->orWhereRaw("LOWER(TRIM(LEADING '@' FROM COALESCE(instagram, ''))) = ?", [$ig]);
                    }
                    if ($em) {
                        $q->orWhereRaw('LOWER(email) = ?', [$em]);
                    }
                })
                ->orderByDesc('created_at')->limit(20)->get()
                ->filter(fn (Lead $l) => ($p && (Crm::phoneKey($l->phone) === $p || Crm::phoneKey($l->whatsapp) === $p))
                    || ($ig && Crm::igKey($l->instagram) === $ig) || ($em && Crm::emailKey($l->email) === $em))
                ->take(5)->values()
            : collect();
        $clients = $access->can('client.read')
            ? CrmAccess::clients()->with('owner')
                ->where(function ($q) use ($p, $ig, $em, $digits) {
                    if ($p) {
                        $q->orWhereRaw($digits('phone').' = ?', [$p])
                            ->orWhereHas('contacts', fn ($c) => $c->whereRaw($digits('phone').' = ?', [$p])->orWhereRaw($digits('whatsapp').' = ?', [$p]));
                    }
                    if ($ig) {
                        $q->orWhereHas('contacts', fn ($c) => $c->whereRaw("LOWER(TRIM(LEADING '@' FROM COALESCE(instagram, ''))) = ?", [$ig]));
                    }
                    if ($em) {
                        $q->orWhereRaw('LOWER(email) = ?', [$em])->orWhereHas('contacts', fn ($c) => $c->whereRaw('LOWER(email) = ?', [$em]));
                    }
                })
                ->orderByDesc('created_at')->limit(5)->get()
            : collect();

        return ['leads' => $leads, 'clients' => $clients];
    }

    /** Пересчёт lead score (ТЗ §10) после любых изменений, влияющих на факторы. */
    public static function rescore(string $id): void
    {
        $lead = Lead::with(['stage', 'service'])->findOrFail($id);
        $minPriceUzs = null;
        if ($lead->service?->min_price) {
            $rate = $lead->service->currency === 'USD'
                ? ExchangeRate::where('currency', 'USD')->orderByDesc('date')->value('rate_to_uzs')
                : '1';
            if ($rate) {
                $minPriceUzs = (float) (string) Money::toUzs((string) $lead->service->min_price, $lead->service->currency, (string) $rate);
            }
        }
        $days = null;
        if ($lead->desired_date) {
            $date = CarbonImmutable::parse($lead->desired_date->format('Y-m-d'), 'UTC');
            $days = (int) round(($date->getTimestampMs() - now()->getTimestampMs()) / 86_400_000);
        }
        $result = LeadScore::compute([
            'budgetUzs' => $lead->budget_uzs !== null ? (float) $lead->budget_uzs : null,
            'serviceMinPriceUzs' => $minPriceUzs,
            'hasService' => (bool) $lead->service_id,
            'priority' => $lead->priority,
            'daysToDesiredDate' => $days,
            'companySize' => $lead->company_size,
            'interest' => $lead->interest,
            'stageIndex' => max(0, (int) array_search($lead->stage->code, Crm::LEAD_STAGES, true)),
            'stageCount' => count(Crm::LEAD_STAGES),
        ]);
        if ($result['score'] !== $lead->score || $result['level'] !== $lead->score_level) {
            $lead->update(['score' => $result['score'], 'score_level' => $result['level']]);
        }
    }

    private static function checkReason(array $input): ?LossReason
    {
        if (in_array($input['status'], Crm::REASON_REQUIRED, true) && empty($input['loss_reason_id'])) {
            throw new BusinessRule(t('crm.errors.reasonRequired'), ['loss_reason_id' => t('crm.errors.reasonRequired')]);
        }
        if (empty($input['loss_reason_id'])) {
            return null;
        }
        $reason = LossReason::where('id', $input['loss_reason_id'])->where('is_active', true)->first();
        if (! $reason) {
            throw new BusinessRule(t('crm.errors.reasonNotFound'), ['loss_reason_id' => t('crm.errors.selectReason')]);
        }
        if ($reason->requires_comment && ! filled($input['comment'] ?? null)) {
            throw new BusinessRule(t('crm.errors.reasonComment'), ['comment' => t('crm.errors.describeReason')]);
        }

        return $reason;
    }

    /** Значения модели в виде, сравнимом с «сырыми» атрибутами до изменения. */
    private static function comparable(Lead $lead): array
    {
        return $lead->getAttributes();
    }

    /** Публичная обёртка проверки причины — для сделок. */
    public static function reason(array $input): ?LossReason
    {
        return self::checkReason($input);
    }
}
