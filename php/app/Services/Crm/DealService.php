<?php

namespace App\Services\Crm;

use App\Exceptions\BusinessRule;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Deal;
use App\Services\References\ExchangeRates;
use App\Support\Audit;
use App\Support\Outbox;
use Illuminate\Support\Facades\DB;

/**
 * Сделки (ТЗ §7): создание у существующего клиента (повторная продажа, ТЗ §63), изменение суммы
 * с фиксацией курса, смена этапа через ворота (DealStages), закрытие, возврат в работу, удаление.
 */
final class DealService
{
    public const TRACKED = ['title', 'amount', 'currency', 'service_id', 'contact_id', 'expected_close_date', 'probability_override'];

    public const EDITABLE = ['title', 'contact_id', 'service_id', 'amount', 'currency', 'expected_close_date', 'probability_override'];

    public function create(array $input): Deal
    {
        $access = access();
        $client = CrmAccess::client($input['client_id']);
        $owner = CrmAccess::assignableOwner(($input['owner_id'] ?? null) ?: $client->owner_id, 'deal.create');
        if (! empty($input['contact_id'])) {
            self::assertContact($client->id, $input['contact_id']);
        }
        $currency = $input['currency'] ?? 'UZS';
        ['rate' => $rate, 'amountUzs' => $amountUzs] = ExchangeRates::convert($input['amount'], $currency);
        $stage = Crm::stage('NEED_DEFINED');
        $previous = Deal::where('client_id', $client->id)->exists();

        return DB::transaction(function () use ($input, $access, $client, $owner, $currency, $rate, $amountUzs, $stage, $previous) {
            $deal = Deal::create([
                'title' => $input['title'],
                'client_id' => $client->id,
                'contact_id' => $input['contact_id'] ?? null,
                'owner_id' => $owner->id,
                'team_id' => $owner->team_id,
                'service_id' => $input['service_id'] ?? null,
                'amount' => $input['amount'],
                'currency' => $currency,
                'exchange_rate' => $rate,
                'amount_uzs' => $amountUzs,
                'stage_id' => $stage->id,
                'expected_close_date' => $input['expected_close_date'] ?? null,
                'is_repeat' => $input['is_repeat'] ?? $previous,
                'created_by_id' => $access->id(),
            ]);
            Activities::stageChange(null, $deal->id, null, $stage->id, $access->id());
            Activities::log('deal.created', ['deal_id' => $deal->id, 'client_id' => $client->id], [
                'amount' => $input['amount'], 'currency' => $currency, 'client' => $client->name,
            ]);
            Audit::log('deal.create', 'deal', $deal->id, [
                'amount' => ['old' => null, 'new' => $input['amount']],
                'client_id' => ['old' => null, 'new' => $client->id],
            ]);
            Outbox::publish('deal.created', ['dealId' => $deal->id, 'ownerId' => $owner->id, 'teamId' => $owner->team_id, 'amountUzs' => $amountUzs]);

            return $deal->refresh();
        });
    }

    public function update(Deal $before, array $input): Deal
    {
        $data = array_intersect_key($input, array_flip(self::EDITABLE));
        if (! empty($data['contact_id'])) {
            self::assertContact($before->client_id, $data['contact_id']);
        }
        if (array_key_exists('amount', $data) || array_key_exists('currency', $data)) {
            ['rate' => $data['exchange_rate'], 'amountUzs' => $data['amount_uzs']] = ExchangeRates::convert(
                (string) ($data['amount'] ?? $before->amount),
                $data['currency'] ?? $before->currency,
            );
        }

        return DB::transaction(function () use ($before, $data) {
            $old = $before->getAttributes();
            $before->update($data);
            $after = $before->fresh();
            $changes = Audit::diff($old, $after->getAttributes(), self::TRACKED);
            if ($changes) {
                // «Изменена сумма» — отдельное событие таймлайна (ТЗ §12)
                $type = isset($changes['amount']) || isset($changes['currency']) ? 'deal.amount_changed' : 'deal.updated';
                Activities::log($type, ['deal_id' => $after->id], ['changes' => $changes]);
                Audit::log('deal.update', 'deal', $after->id, $changes);
            }

            return $after;
        });
    }

    /** Передать сделку другому менеджеру может тот, кто распределяет лиды (РОП/CEO). */
    public function assign(Deal $deal, string $ownerId): Deal
    {
        $owner = CrmAccess::assignableOwner($ownerId, 'lead.assign');

        return DB::transaction(function () use ($deal, $owner) {
            $previous = $deal->owner_id;
            $deal->update(['owner_id' => $owner->id, 'team_id' => $owner->team_id]);
            Activities::log('deal.assigned', ['deal_id' => $deal->id], ['to' => $owner->full_name]);
            Audit::log('deal.assign', 'deal', $deal->id, ['owner_id' => ['old' => $previous, 'new' => $owner->id]]);

            return $deal->refresh();
        });
    }

    /**
     * Переход по этапам с проверкой документов (BUSINESS_RULES §3). «Оплачено» ставится только
     * подтверждением оплаты; назад — без проверок.
     */
    public function changeStage(Deal $deal, string $code): Deal
    {
        if (! in_array($code, DealStages::CODES, true)) {
            abort(404);
        }

        return DB::transaction(function () use ($deal, $code) {
            DealStages::move($deal, $code, access()->id());

            return $deal->refresh();
        });
    }

    public function close(Deal $deal, array $input): Deal
    {
        if ($deal->status !== 'OPEN') {
            throw new BusinessRule(t('deals.errors.alreadyClosed'));
        }
        $reason = LeadService::reason($input);

        return DB::transaction(function () use ($deal, $input, $reason) {
            $status = $input['status'];
            $deal->update([
                'status' => $status,
                'loss_reason_id' => $reason?->id,
                'loss_comment' => $input['comment'] ?? null,
                'closed_at' => now(),
            ]);
            Activities::log('deal.closed', ['deal_id' => $deal->id], ['status' => $status, 'reason' => $reason?->name_ru, 'comment' => $input['comment'] ?? null]);
            Audit::log('deal.close', 'deal', $deal->id, [
                'status' => ['old' => 'OPEN', 'new' => $status],
                'loss_reason' => ['old' => null, 'new' => $reason?->code],
            ]);
            if (in_array($status, Crm::REASON_REQUIRED, true)) {
                Outbox::publish('deal.lost', [
                    'dealId' => $deal->id, 'ownerId' => $deal->owner_id, 'teamId' => $deal->team_id,
                    'amountUzs' => (string) $deal->amount_uzs, 'reason' => $reason?->name_ru,
                ]);
            }

            return $deal->refresh();
        });
    }

    public function reopen(Deal $deal): Deal
    {
        if ($deal->status === 'OPEN') {
            return $deal;
        }
        if ($deal->status === 'WON') {
            throw new BusinessRule(t('deals.errors.wonCannotReopen'));
        }

        return DB::transaction(function () use ($deal) {
            $from = $deal->status;
            $deal->update(['status' => 'OPEN', 'loss_reason_id' => null, 'loss_comment' => null, 'closed_at' => null]);
            Activities::log('deal.reopened', ['deal_id' => $deal->id], ['from' => $from]);
            Audit::log('deal.reopen', 'deal', $deal->id, ['status' => ['old' => $from, 'new' => 'OPEN']]);

            return $deal->refresh();
        });
    }

    /** Сделки — критичные данные (ТЗ §66): только мягкое удаление и только без оплаты. */
    public function remove(Deal $deal): void
    {
        if ($deal->status === 'WON') {
            throw new BusinessRule(t('deals.errors.wonCannotDelete'));
        }
        DB::transaction(function () use ($deal) {
            $deal->delete();
            Audit::log('deal.delete', 'deal', $deal->id, ['title' => ['old' => $deal->title, 'new' => null]]);
        });
    }

    private static function assertContact(string $clientId, string $contactId): void
    {
        if (! Contact::where('id', $contactId)->where('client_id', $clientId)->exists()) {
            throw new BusinessRule(t('deals.errors.contactNotOfClient'), ['contact_id' => t('deals.errors.selectClientContact')]);
        }
    }

    /** Сумма по договорам, оплачено, возвраты и проект сделки — только чтение (раздел «Оплаты» ведёт модуль продаж). */
    public static function money(Deal $deal): array
    {
        $contract = (string) (DB::table('contracts')->where('deal_id', $deal->id)->where('status', 'SIGNED')->sum('amount_uzs') ?: '0');
        $paid = (string) (DB::table('payments')->where('deal_id', $deal->id)->where('status', 'PAID')->where('type', '<>', 'REFUND')->sum('amount_uzs') ?: '0');
        $refunded = (string) (DB::table('payments')->where('deal_id', $deal->id)->where('status', 'PAID')->where('type', 'REFUND')->sum('amount_uzs') ?: '0');
        $receivable = \Brick\Math\BigDecimal::of($contract)->minus($paid)->plus($refunded);
        if ($receivable->isNegative()) {
            $receivable = \Brick\Math\BigDecimal::zero();
        }
        $project = DB::table('projects')->where('deal_id', $deal->id)->first(['id', 'name', 'number', 'status']);

        return [
            'contract' => $contract,
            'paid' => $paid,
            'refunded' => $refunded,
            'receivable' => (string) $receivable->toScale(2),
            'project' => $project,
        ];
    }

    /** Клиент, если он виден пользователю (для формы новой сделки). */
    public static function visibleClient(?string $id): ?Client
    {
        return $id ? CrmAccess::clients()->whereKey($id)->first() : null;
    }
}
