<?php

namespace App\Services\Sales;

use App\Domain\Money;
use App\Domain\Proposal as ProposalTotals;
use App\Exceptions\BusinessRule;
use App\Models\Deal;
use App\Models\Proposal;
use App\Models\ProposalItem;
use App\Models\ProposalVersion;
use App\Models\Tariff;
use App\Services\Crm\Activities;
use App\Services\Crm\CrmAccess;
use App\Services\Crm\DealStages;
use App\Services\References\ExchangeRates;
use App\Support\Audit;
use App\Support\Outbox;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Коммерческие предложения (ТЗ §15–16). КП видно тем, кто видит сделку (и имеет proposal.read).
 * Любое изменение — новая версия-снимок; отправленное КП после правки возвращается в черновик.
 */
final class ProposalsService
{
    public const STATUSES = ['DRAFT', 'SENT', 'VIEWED', 'IN_APPROVAL', 'ACCEPTED', 'REJECTED', 'EXPIRED'];

    /** Статусы, в которых КП можно изменить. */
    public const EDITABLE = ['DRAFT', 'IN_APPROVAL', 'SENT', 'VIEWED', 'REJECTED'];

    public const ACTIONS = ['submit-approval', 'approve', 'send', 'mark-viewed', 'accept', 'reject'];

    public const WITH = ['deal:id,number,title,status', 'client:id,name', 'manager:id,full_name', 'approvedBy:id,full_name', 'items.service:id,name_ru,name_uz', 'items.tariff:id,name'];

    public static function query(string $code = 'proposal.read'): Builder
    {
        return CrmAccess::byDeal(Proposal::query(), $code);
    }

    /** @param  array{status?:?string, deal_id?:?string, manager_id?:?string, q?:?string}  $f */
    public static function list(array $f, int $perPage = 25): LengthAwarePaginator
    {
        return self::query()
            ->with(self::WITH)
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['deal_id'] ?? null, fn ($q, $v) => $q->where('deal_id', $v))
            ->when($f['manager_id'] ?? null, fn ($q, $v) => $q->where('manager_id', $v))
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('title', 'like', "%$v%")
                ->orWhereIn('client_id', fn ($c) => $c->select('id')->from('clients')->where('name', 'like', "%$v%"))))
            ->orderByDesc('updated_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    public static function find(string $id, string $code = 'proposal.read'): Proposal
    {
        return self::query($code)->whereKey($id)->firstOrFail();
    }

    /** Версии КП, новые сверху. */
    public static function versions(Proposal $p)
    {
        return ProposalVersion::with('author:id,full_name')->where('proposal_id', $p->id)->orderByDesc('version')->get();
    }

    /**
     * Итоги и курс. Тариф определяет услугу позиции; архивный тариф в КП не добавить.
     * Цену и скидку по тарифу меняет только РОП/CEO (право утверждать КП).
     *
     * @return array{items: array, totals: array, rate: string, totalUzs: string}
     */
    private static function compute(array $input): array
    {
        $items = array_values($input['items']);
        $tariffIds = array_values(array_unique(array_filter(array_column($items, 'tariff_id'))));
        if ($tariffIds) {
            $tariffs = Tariff::whereIn('id', $tariffIds)->get()->keyBy('id');
            foreach ($items as $idx => $item) {
                if (empty($item['tariff_id'])) {
                    continue;
                }
                $t = $tariffs[$item['tariff_id']] ?? null;
                if (! $t || ! $t->is_active) {
                    throw new BusinessRule(t('sales.errors.tariffNotFound'), ["items.$idx.tariff_id" => t('sales.errors.tariffChoose')]);
                }
                $items[$idx]['service_id'] = $t->service_id;
                if (! access()->can('proposal.approve')) {
                    // Сравнение как в прежней версии: допуск 0,01 в своей валюте или 1% при пересчёте по курсу
                    $rate = (float) ExchangeRates::rateFor('USD');
                    $expected = $t->currency === $input['currency']
                        ? (float) $t->price
                        : ($t->currency === 'USD' ? (float) $t->price * $rate : (float) $t->price / $rate);
                    $price = (float) $item['unit_price'];
                    $tolerance = $t->currency === $input['currency'] ? 0.01 : $expected * 0.01;
                    if (abs($price - $expected) > $tolerance || (float) ($item['discount_pct'] ?? 0) > 0) {
                        throw new BusinessRule(t('sales.errors.tariffPriceLocked'), ["items.$idx.unit_price" => t('sales.errors.tariffPriceLockedField')]);
                    }
                }
            }
        }
        $totals = ProposalTotals::totals(array_map(fn ($i) => [
            'quantity' => (string) $i['quantity'],
            'unitPrice' => (string) $i['unit_price'],
            'discountPct' => (string) ($i['discount_pct'] ?? 0),
        ], $items));
        $rate = ExchangeRates::rateFor($input['currency']);

        return [
            'items' => $items,
            'totals' => $totals,
            'rate' => $rate,
            'totalUzs' => (string) Money::toUzs((string) $totals['total'], $input['currency'], $rate)->toScale(2),
        ];
    }

    private static function header(array $input, array $c): array
    {
        return [
            'title' => $input['title'],
            'description' => $input['description'] ?? null,
            'currency' => $input['currency'],
            'subtotal' => (string) $c['totals']['subtotal']->toScale(2),
            'discount_amount' => (string) $c['totals']['discountAmount']->toScale(2),
            'total' => (string) $c['totals']['total']->toScale(2),
            'exchange_rate' => $c['rate'],
            'total_uzs' => $c['totalUzs'],
            'implementation_term' => $input['implementation_term'] ?? null,
            'payment_terms' => $input['payment_terms'] ?? null,
            'valid_until' => $input['valid_until'] ?? null,
        ];
    }

    private static function createItems(string $proposalId, array $c): void
    {
        foreach ($c['items'] as $idx => $i) {
            ProposalItem::create([
                'proposal_id' => $proposalId,
                'service_id' => $i['service_id'] ?? null,
                'tariff_id' => $i['tariff_id'] ?? null,
                'description' => $i['description'],
                'quantity' => (string) $i['quantity'],
                'unit_price' => (string) $i['unit_price'],
                'discount_pct' => (string) ($i['discount_pct'] ?? 0),
                'total' => (string) $c['totals']['lines'][$idx]['total']->toScale(2),
                'sort' => $idx,
            ]);
        }
    }

    public static function create(array $input): Proposal
    {
        $deal = CrmAccess::deal($input['deal_id'], 'proposal.create');
        if ($deal->status !== 'OPEN') {
            throw new BusinessRule(t('sales.errors.dealClosed'));
        }
        $c = self::compute($input);
        $actor = access()->id();

        return DB::transaction(function () use ($deal, $input, $c, $actor) {
            $p = Proposal::create(self::header($input, $c) + [
                'deal_id' => $deal->id,
                'client_id' => $deal->client_id,
                'manager_id' => $deal->owner_id,
            ]);
            self::createItems($p->id, $c);
            $dto = self::snapshot($p, 1, $actor, $input['version_comment'] ?? 'Создано');
            Activities::log('proposal.created', ['deal_id' => $deal->id], ['number' => $dto['number'], 'total' => $dto['total'], 'currency' => $dto['currency']], $actor);
            Audit::log('proposal.create', 'proposal', $p->id, ['total' => ['old' => null, 'new' => $dto['total']]], $actor);

            return $p->refresh();
        });
    }

    /** Любое изменение → новая версия; отправленное КП возвращается в черновик и требует повторной отправки. */
    public static function update(string $id, array $input): Proposal
    {
        $before = self::find($id, 'proposal.update');
        if (! in_array($before->status, self::EDITABLE, true)) {
            throw new BusinessRule(t('sales.errors.proposalAccepted'));
        }
        $c = self::compute($input);
        $actor = access()->id();

        return DB::transaction(function () use ($before, $input, $c, $actor) {
            $version = $before->current_version + 1;
            $oldTotal = (string) $before->total;
            $oldVersion = $before->current_version;
            ProposalItem::where('proposal_id', $before->id)->delete();
            $before->update(self::header($input, $c) + [
                'current_version' => $version,
                'status' => 'DRAFT',
                'approved_by_id' => null,
                'approved_at' => null,
            ]);
            self::createItems($before->id, $c);
            $dto = self::snapshot($before, $version, $actor, $input['version_comment'] ?? null);
            Activities::log('proposal.updated', ['deal_id' => $before->deal_id], [
                'number' => $dto['number'], 'version' => $version, 'old' => $oldTotal, 'new' => $dto['total'], 'currency' => $dto['currency'],
            ], $actor);
            Audit::log('proposal.update', 'proposal', $before->id, [
                'total' => ['old' => $oldTotal, 'new' => $dto['total']],
                'version' => ['old' => $oldVersion, 'new' => $version],
            ], $actor);

            return $before->refresh();
        });
    }

    /** Новая версия = снимок шапки и позиций (ТЗ §16). */
    private static function snapshot(Proposal $p, int $version, string $authorId, ?string $comment): array
    {
        $p = Proposal::with(self::WITH)->findOrFail($p->id);
        $dto = self::dto($p);
        ProposalVersion::create([
            'proposal_id' => $p->id,
            'version' => $version,
            'snapshot' => $dto,
            'total' => (string) $p->total,
            'currency' => $p->currency,
            'author_id' => $authorId,
            'comment' => $comment,
        ]);

        return $dto;
    }

    /** Снимок КП в формате прежней версии (proposal_versions.snapshot). */
    public static function dto(Proposal $p): array
    {
        $iso = fn ($v) => $v?->format('Y-m-d\TH:i:s.v\Z');

        return [
            'id' => $p->id,
            'number' => Numbers::proposal($p->number),
            'title' => $p->title,
            'description' => $p->description,
            'deal' => ['id' => $p->deal->id, 'name' => $p->deal->title, 'number' => Numbers::deal($p->deal->number)],
            'client' => ['id' => $p->client->id, 'name' => $p->client->name],
            'manager' => ['id' => $p->manager->id, 'name' => $p->manager->full_name],
            'status' => $p->status,
            'currency' => $p->currency,
            'subtotal' => (string) $p->subtotal,
            'discountAmount' => (string) $p->discount_amount,
            'total' => (string) $p->total,
            'totalUzs' => (string) $p->total_uzs,
            'implementationTerm' => $p->implementation_term,
            'paymentTerms' => $p->payment_terms,
            'validUntil' => $p->valid_until?->format('Y-m-d'),
            'currentVersion' => $p->current_version,
            'approvedBy' => $p->approvedBy ? ['id' => $p->approvedBy->id, 'name' => $p->approvedBy->full_name] : null,
            'approvedAt' => $iso($p->approved_at),
            'sentAt' => $iso($p->sent_at),
            'viewedAt' => $iso($p->viewed_at),
            'acceptedAt' => $iso($p->accepted_at),
            'rejectedAt' => $iso($p->rejected_at),
            'createdAt' => $iso($p->created_at),
            'updatedAt' => $iso($p->updated_at),
            'items' => $p->items->sortBy('sort')->values()->map(fn (ProposalItem $i) => [
                'id' => $i->id,
                'service' => $i->service ? ['id' => $i->service->id, 'name' => $i->service->name_ru] : null,
                'tariff' => $i->tariff ? ['id' => $i->tariff->id, 'name' => $i->tariff->name] : null,
                'description' => $i->description,
                'quantity' => Numbers::rate($i->quantity),
                'unitPrice' => (string) $i->unit_price,
                'discountPct' => Numbers::rate($i->discount_pct),
                'total' => (string) $i->total,
            ])->all(),
        ];
    }

    public static function action(string $id, string $action): Proposal
    {
        return match ($action) {
            'submit-approval' => self::submitApproval($id),
            'approve' => self::approve($id),
            'send' => self::send($id),
            'mark-viewed' => self::markViewed($id),
            'accept' => self::accept($id),
            'reject' => self::reject($id),
            default => abort(404),
        };
    }

    /** Право на действие проверяется на сервере: каждому действию — своё право прежней версии. */
    public static function permissionFor(string $action): string
    {
        return match ($action) {
            'approve' => 'proposal.approve',
            'send' => 'proposal.send',
            default => 'proposal.update',
        };
    }

    private static function transition(Proposal $p, array $data, string $action, ?callable $after = null): Proposal
    {
        $actor = access()->id();

        return DB::transaction(function () use ($p, $data, $action, $after, $actor) {
            $old = $p->status;
            $p->update($data);
            Activities::log("proposal.$action", ['deal_id' => $p->deal_id], ['proposalId' => $p->id, 'title' => $p->title], $actor);
            Audit::log("proposal.$action", 'proposal', $p->id, isset($data['status']) ? ['status' => ['old' => $old, 'new' => $data['status']]] : null, $actor);
            if ($after) {
                $after($actor);
            }

            return $p->refresh();
        });
    }

    public static function submitApproval(string $id): Proposal
    {
        $p = self::find($id, 'proposal.update');
        if ($p->status !== 'DRAFT') {
            throw new BusinessRule(t('sales.errors.approvalDraftOnly'));
        }
        $deal = Deal::withTrashed()->findOrFail($p->deal_id);

        return self::transition($p, ['status' => 'IN_APPROVAL'], 'approval_requested', fn ($actor) => Outbox::publish(
            'proposal.approval_requested',
            ['proposalId' => $p->id, 'dealId' => $p->deal_id, 'teamId' => $deal->team_id],
            $actor,
        ));
    }

    /** РОП утверждает КП (ТЗ §3.2). */
    public static function approve(string $id): Proposal
    {
        $p = self::find($id, 'proposal.approve');
        if (! in_array($p->status, ['DRAFT', 'IN_APPROVAL'], true)) {
            throw new BusinessRule(t('sales.errors.approveWrongStatus'));
        }

        return self::transition($p, ['status' => 'DRAFT', 'approved_by_id' => access()->id(), 'approved_at' => now()], 'approved');
    }

    public static function send(string $id): Proposal
    {
        $p = self::find($id, 'proposal.send');
        if ($p->status === 'IN_APPROVAL') {
            throw new BusinessRule(t('sales.errors.inApproval'));
        }
        if (! in_array($p->status, ['DRAFT', 'REJECTED'], true)) {
            throw new BusinessRule(t('sales.errors.alreadySent'));
        }

        return self::transition($p, ['status' => 'SENT', 'sent_at' => now()], 'sent', function ($actor) use ($p) {
            DealStages::advanceTo($p->deal_id, 'PROPOSAL_SENT', $actor);
            Outbox::publish('proposal.sent', ['proposalId' => $p->id, 'dealId' => $p->deal_id], $actor);
        });
    }

    public static function markViewed(string $id): Proposal
    {
        $p = self::find($id, 'proposal.update');
        if ($p->status !== 'SENT') {
            throw new BusinessRule(t('sales.errors.viewedSentOnly'));
        }

        return self::transition($p, ['status' => 'VIEWED', 'viewed_at' => now()], 'viewed');
    }

    /** Клиент принял КП: сделка → «Переговоры», сумма сделки = итог КП. */
    public static function accept(string $id): Proposal
    {
        $p = self::find($id, 'proposal.update');
        if (! in_array($p->status, ['SENT', 'VIEWED'], true)) {
            throw new BusinessRule(t('sales.errors.acceptSentOnly'));
        }

        return self::transition($p, ['status' => 'ACCEPTED', 'accepted_at' => now()], 'accepted', function ($actor) use ($p) {
            $deal = Deal::findOrFail($p->deal_id);
            if ((string) $deal->amount !== (string) $p->total || $deal->currency !== $p->currency) {
                $old = (string) $deal->amount;
                $deal->update([
                    'amount' => (string) $p->total,
                    'currency' => $p->currency,
                    'exchange_rate' => (string) $p->exchange_rate,
                    'amount_uzs' => (string) $p->total_uzs,
                ]);
                Activities::log('deal.amount_changed', ['deal_id' => $deal->id], [
                    'changes' => ['amount' => ['old' => $old, 'new' => (string) $p->total]],
                    'reason' => 'КП принято',
                ], $actor);
            }
            DealStages::advanceTo($p->deal_id, 'NEGOTIATION', $actor);
            Outbox::publish('proposal.accepted', ['proposalId' => $p->id, 'dealId' => $p->deal_id], $actor);
        });
    }

    public static function reject(string $id): Proposal
    {
        $p = self::find($id, 'proposal.update');
        if (! in_array($p->status, ['SENT', 'VIEWED'], true)) {
            throw new BusinessRule(t('sales.errors.rejectSentOnly'));
        }

        return self::transition($p, ['status' => 'REJECTED', 'rejected_at' => now()], 'rejected');
    }
}
