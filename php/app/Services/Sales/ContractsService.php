<?php

namespace App\Services\Sales;

use App\Exceptions\BusinessRule;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Proposal;
use App\Services\Crm\Activities;
use App\Services\Crm\CrmAccess;
use App\Services\Crm\DealStages;
use App\Services\References\ExchangeRates;
use App\Support\Audit;
use App\Support\Outbox;
use Brick\Math\BigDecimal;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Договоры (ТЗ §17): по сделке, из принятого КП. Не удаляются — только отменяются.
 * Создание двигает сделку в «Договор», подпись — в «Ожидаем оплату».
 */
final class ContractsService
{
    public const STATUSES = ['DRAFT', 'SENT', 'IN_APPROVAL', 'SIGNED', 'CANCELLED'];

    /** Переходы статуса: действие → [из каких статусов, в какой]. */
    public const TRANSITIONS = [
        'send' => [['DRAFT'], 'SENT'],
        'submit-approval' => [['DRAFT', 'SENT'], 'IN_APPROVAL'],
        'sign' => [['DRAFT', 'SENT', 'IN_APPROVAL'], 'SIGNED'],
        'cancel' => [['DRAFT', 'SENT', 'IN_APPROVAL', 'SIGNED'], 'CANCELLED'],
    ];

    public const WITH = ['deal:id,number,title,status', 'client:id,name', 'proposal:id,number,title', 'createdBy:id,full_name', 'files.uploadedBy:id,full_name'];

    public static function query(string $code = 'contract.read'): Builder
    {
        return CrmAccess::byDeal(Contract::query(), $code);
    }

    /** @param  array{status?:?string, deal_id?:?string, q?:?string}  $f */
    public static function list(array $f, int $perPage = 25): LengthAwarePaginator
    {
        $page = self::query()
            ->with(self::WITH)
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['deal_id'] ?? null, fn ($q, $v) => $q->where('deal_id', $v))
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w
                ->whereIn('client_id', fn ($c) => $c->select('id')->from('clients')->where('name', 'like', "%$v%"))
                ->orWhereIn('deal_id', fn ($c) => $c->select('id')->from('deals')->where('title', 'like', "%$v%"))))
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();
        self::withPaid($page->getCollection());

        return $page;
    }

    /** Оплачено по договору (UZS): подтверждённые оплаты минус возвраты. */
    public static function withPaid($contracts): void
    {
        $ids = collect($contracts)->pluck('id')->all();
        $rows = $ids ? Payment::whereIn('contract_id', $ids)->where('status', 'PAID')->get(['contract_id', 'type', 'amount_uzs'])->groupBy('contract_id') : collect();
        foreach ($contracts as $c) {
            $sum = BigDecimal::zero();
            foreach ($rows[$c->id] ?? [] as $p) {
                $sum = $p->type === 'REFUND' ? $sum->minus((string) $p->amount_uzs) : $sum->plus((string) $p->amount_uzs);
            }
            $c->setAttribute('paid_uzs', (string) $sum->toScale(2));
        }
    }

    public static function find(string $id, string $code = 'contract.read'): Contract
    {
        return self::query($code)->whereKey($id)->firstOrFail();
    }

    /** Договор по сделке; из принятого КП сумма берётся из КП. Сделка → «Договор». */
    public static function create(array $input): Contract
    {
        $deal = CrmAccess::deal($input['deal_id'], 'contract.create');
        if ($deal->status !== 'OPEN') {
            throw new BusinessRule(t('sales.errors.dealClosed'));
        }
        if (! empty($input['proposal_id'])) {
            $p = Proposal::where('id', $input['proposal_id'])->where('deal_id', $deal->id)->first();
            if (! $p) {
                throw new BusinessRule(t('sales.errors.proposalOtherDeal'));
            }
            if ($p->status !== 'ACCEPTED') {
                throw new BusinessRule(t('sales.errors.contractFromAccepted'));
            }
        }
        ['rate' => $rate, 'amountUzs' => $amountUzs] = ExchangeRates::convert($input['amount'], $input['currency']);
        $actor = access()->id();

        return DB::transaction(function () use ($deal, $input, $rate, $amountUzs, $actor) {
            $c = Contract::create([
                'deal_id' => $deal->id,
                'client_id' => $deal->client_id,
                'proposal_id' => $input['proposal_id'] ?? null,
                'contract_date' => $input['contract_date'],
                'amount' => $input['amount'],
                'currency' => $input['currency'],
                'exchange_rate' => $rate,
                'amount_uzs' => $amountUzs,
                'comment' => $input['comment'] ?? null,
                'created_by_id' => $actor,
            ])->refresh();
            Activities::log('contract.created', ['deal_id' => $deal->id], [
                'number' => Numbers::contract($c->number), 'amount' => $input['amount'], 'currency' => $input['currency'],
            ], $actor);
            DealStages::advanceTo($deal->id, 'CONTRACT', $actor);
            Audit::log('contract.create', 'contract', $c->id, ['amount' => ['old' => null, 'new' => $input['amount']]], $actor);

            return $c;
        });
    }

    /** @param  array{contract_date?:string, amount?:string, currency?:string, comment?:?string}  $input */
    public static function update(string $id, array $input): Contract
    {
        $before = self::find($id, 'contract.update');
        if (in_array($before->status, ['SIGNED', 'CANCELLED'], true)) {
            throw new BusinessRule(t('sales.errors.contractLocked'));
        }
        $data = array_intersect_key($input, array_flip(['contract_date', 'amount', 'currency', 'comment']));
        if (isset($input['amount']) || isset($input['currency'])) {
            $conv = ExchangeRates::convert($input['amount'] ?? (string) $before->amount, $input['currency'] ?? $before->currency);
            $data['exchange_rate'] = $conv['rate'];
            $data['amount_uzs'] = $conv['amountUzs'];
        }
        $actor = access()->id();

        return DB::transaction(function () use ($before, $data, $actor) {
            $old = [
                'contract_date' => $before->contract_date?->format('Y-m-d'),
                'amount' => (string) $before->amount,
                'currency' => $before->currency,
                'comment' => $before->comment,
            ];
            $before->update($data);
            $after = $before->refresh();
            $changes = Audit::diff($old, [
                'contract_date' => $after->contract_date?->format('Y-m-d'),
                'amount' => (string) $after->amount,
                'currency' => $after->currency,
                'comment' => $after->comment,
            ], ['contract_date', 'amount', 'currency', 'comment']);
            if ($changes) {
                Audit::log('contract.update', 'contract', $after->id, $changes, $actor);
            }

            return $after;
        });
    }

    public static function transition(string $id, string $action): Contract
    {
        $c = self::find($id, 'contract.update');
        [$from, $to] = self::TRANSITIONS[$action] ?? abort(404);
        if (! in_array($c->status, $from, true)) {
            throw new BusinessRule(t('sales.errors.contractWrongStatus'));
        }
        if ($action === 'cancel' && Payment::where('contract_id', $id)->where('status', 'PAID')->exists()) {
            throw new BusinessRule(t('sales.errors.contractHasPayments'));
        }
        $actor = access()->id();

        return DB::transaction(function () use ($c, $action, $to, $actor) {
            $old = $c->status;
            $c->update(['status' => $to] + ($to === 'SIGNED' ? ['signed_at' => now()] : []));
            Activities::log('contract.'.($to === 'SIGNED' ? 'signed' : strtolower($to)), ['deal_id' => $c->deal_id], ['number' => Numbers::contract($c->number)], $actor);
            Audit::log("contract.$action", 'contract', $c->id, ['status' => ['old' => $old, 'new' => $to]], $actor);
            if ($to === 'SIGNED') {
                $deal = Deal::withTrashed()->findOrFail($c->deal_id);
                DealStages::advanceTo($c->deal_id, 'AWAITING_PAYMENT', $actor);
                Outbox::publish('contract.signed', ['contractId' => $c->id, 'dealId' => $c->deal_id, 'managerId' => $deal->owner_id, 'teamId' => $deal->team_id], $actor);
            }

            return $c->refresh();
        });
    }
}
