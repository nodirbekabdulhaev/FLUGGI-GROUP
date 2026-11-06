<?php

namespace App\Services\Sales;

use App\Domain\Finance;
use App\Exceptions\BusinessRule;
use App\Models\Commission;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Project;
use App\Models\ProposalItem;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use App\Services\Crm\Activities;
use App\Services\Crm\CrmAccess;
use App\Services\Crm\DealStages;
use App\Services\Projects\Templates;
use App\Services\References\ExchangeRates;
use App\Support\Audit;
use App\Support\Format;
use App\Support\Outbox;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Оплаты (ТЗ §18–19). Не удаляются: ошибочная — отмена, возврат — отдельная запись REFUND.
 * Подтверждение: сделка → «Оплачено»/WON, проект (один на сделку), комиссии.
 */
final class PaymentsService
{
    public const STATUSES = ['PENDING', 'PAID', 'CANCELLED'];

    public const TYPES = ['PREPAYMENT', 'PARTIAL', 'FULL', 'FINAL', 'REFUND'];

    /** Типы, которые выбираются при создании (возврат — отдельным действием). */
    public const CREATE_TYPES = ['PREPAYMENT', 'PARTIAL', 'FULL', 'FINAL'];

    public const METHODS = ['CASH', 'BANK', 'CARD', 'TRANSFER', 'OTHER'];

    public const WITH = ['deal:id,number,title,status', 'client:id,name', 'contract:id,number', 'project:id,number,name', 'refundOf:id,number', 'confirmedBy:id,full_name', 'createdBy:id,full_name'];

    public static function query(string $code = 'payment.read'): Builder
    {
        return CrmAccess::byDeal(Payment::query(), $code);
    }

    /** @param  array{status?:?string, type?:?string, deal_id?:?string, client_id?:?string, date_from?:?string, date_to?:?string}  $f */
    public static function list(array $f, int $perPage = 25): LengthAwarePaginator
    {
        $tz = Format::tz();
        $page = self::query()
            ->with(self::WITH)
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($f['deal_id'] ?? null, fn ($q, $v) => $q->where('deal_id', $v))
            ->when($f['client_id'] ?? null, fn ($q, $v) => $q->where('client_id', $v))
            ->when($f['date_from'] ?? null, function ($q, $v) use ($tz) {
                $from = CarbonImmutable::parse($v, $tz)->startOfDay()->utc();
                $q->where(fn ($w) => $w->where('paid_at', '>=', $from)->orWhere(fn ($x) => $x->whereNull('paid_at')->where('created_at', '>=', $from)));
            })
            ->when($f['date_to'] ?? null, function ($q, $v) use ($tz) {
                $to = CarbonImmutable::parse($v, $tz)->startOfDay()->addDay()->utc();
                $q->where(fn ($w) => $w->where('paid_at', '<', $to)->orWhere(fn ($x) => $x->whereNull('paid_at')->where('created_at', '<', $to)));
            })
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();
        self::withRefundable($page->getCollection());

        return $page;
    }

    /** Сколько ещё можно вернуть по подтверждённой оплате (UZS). */
    public static function withRefundable($payments): void
    {
        $ids = collect($payments)->filter(fn ($p) => $p->status === 'PAID' && $p->type !== 'REFUND')->pluck('id')->all();
        $refunded = $ids ? Payment::whereIn('refund_of_id', $ids)->where('status', 'PAID')
            ->groupBy('refund_of_id')->selectRaw('refund_of_id, SUM(amount_uzs) AS s')->pluck('s', 'refund_of_id') : collect();
        foreach ($payments as $p) {
            $p->setAttribute('refundable_uzs', $p->status === 'PAID' && $p->type !== 'REFUND'
                ? (string) BigDecimal::of((string) $p->amount_uzs)->minus((string) ($refunded[$p->id] ?? '0'))->toScale(2)
                : '0.00');
        }
    }

    public static function find(string $id, string $code = 'payment.read'): Payment
    {
        return self::query($code)->whereKey($id)->firstOrFail();
    }

    private static function assertContract(?string $contractId, string $dealId): void
    {
        if (! $contractId) {
            return;
        }
        $c = Contract::where('id', $contractId)->where('deal_id', $dealId)->first();
        if (! $c) {
            throw new BusinessRule(t('sales.errors.contractOtherDeal'));
        }
        if ($c->status === 'CANCELLED') {
            throw new BusinessRule(t('sales.errors.contractCancelled'));
        }
    }

    /** Блокировка сделки: подтверждение, правка и возврат по одной сделке не пересекаются. */
    private static function lockDeal(string $dealId): void
    {
        DB::table('deals')->where('id', $dealId)->lockForUpdate()->first();
    }

    public static function create(array $input): Payment
    {
        $deal = CrmAccess::deal($input['deal_id'], 'payment.create');
        if (! in_array($deal->status, ['OPEN', 'WON'], true)) {
            throw new BusinessRule(t('sales.errors.dealClosed'));
        }
        self::assertContract($input['contract_id'] ?? null, $deal->id);
        ['rate' => $rate, 'amountUzs' => $amountUzs] = ExchangeRates::convert($input['amount'], $input['currency']);
        $actor = access()->id();

        return DB::transaction(function () use ($deal, $input, $rate, $amountUzs, $actor) {
            $p = Payment::create([
                'deal_id' => $deal->id,
                'client_id' => $deal->client_id,
                'contract_id' => $input['contract_id'] ?? null,
                'amount' => $input['amount'],
                'currency' => $input['currency'],
                'exchange_rate' => $rate,
                'amount_uzs' => $amountUzs,
                'type' => $input['type'],
                'method' => $input['method'],
                'due_date' => $input['due_date'] ?? null,
                'comment' => $input['comment'] ?? null,
                'created_by_id' => $actor,
            ])->refresh();
            Activities::log('payment.created', ['deal_id' => $deal->id], [
                'number' => Numbers::payment($p->number), 'amount' => $input['amount'], 'currency' => $input['currency'], 'type' => $input['type'],
            ], $actor);
            Audit::log('payment.create', 'payment', $p->id, [
                'amount' => ['old' => null, 'new' => $input['amount']],
                'status' => ['old' => null, 'new' => 'PENDING'],
            ], $actor);
            Outbox::publish('payment.created', ['paymentId' => $p->id, 'dealId' => $deal->id], $actor);

            return $p;
        });
    }

    /**
     * Подтверждение оплаты (ТЗ §19, Rule 3, 4, 7). В одной транзакции:
     *  1. платёж → PAID;
     *  2. первый платёж: сделка → WON и этап «Оплачено»;
     *  3. проект создаётся, если его ещё нет (один проект на сделку), РОП назначается;
     *  4. комиссии менеджеру и РОП по правилам.
     * Сделка блокируется FOR UPDATE — два одновременных подтверждения не создадут два проекта.
     *
     * @return array{payment: Payment, project: Project, projectCreated: bool, commissions: list<Commission>}
     */
    public static function confirm(string $id, ?CarbonImmutable $paidAt = null): array
    {
        $p0 = self::find($id, 'payment.confirm');
        if ($p0->status !== 'PENDING') {
            throw new BusinessRule(t('sales.errors.paymentProcessed'));
        }
        if ($p0->type === 'REFUND') {
            throw new BusinessRule(t('sales.errors.refundConfirmedOnCreate'));
        }
        $actor = access()->id();

        return DB::transaction(function () use ($p0, $id, $paidAt, $actor) {
            self::lockDeal($p0->deal_id);
            $payment = Payment::findOrFail($id);
            if ($payment->status !== 'PENDING') {
                throw new BusinessRule(t('sales.errors.paymentProcessed'));
            }
            $deal = Deal::withTrashed()->findOrFail($p0->deal_id);
            $firstPayment = ! Payment::where('deal_id', $deal->id)->where('status', 'PAID')->where('type', '<>', 'REFUND')->exists();

            $payment->update([
                'status' => 'PAID',
                'paid_at' => $paidAt ?? now(),
                'confirmed_by_id' => $actor,
                'confirmed_at' => now(),
            ]);
            $payment->refresh();

            if ($deal->status === 'OPEN') {
                $deal->update(['status' => 'WON', 'won_at' => $payment->paid_at, 'closed_at' => $payment->paid_at]);
                DealStages::advanceTo($deal->id, 'PAID', $actor);
                Outbox::publish('deal.won', [
                    'dealId' => $deal->id, 'ownerId' => $deal->owner_id, 'teamId' => $deal->team_id, 'amountUzs' => (string) $deal->amount_uzs,
                ], $actor);
            }

            ['project' => $project, 'created' => $created] = self::ensureProject($deal, $actor);
            $payment->update(['project_id' => $project->id]);
            // Маржа проекта сейчас (стоимость − уже внесённые расходы) — для правил «% от прибыли».
            $spent = (string) (DB::table('expenses')->where('project_id', $project->id)->whereNull('deleted_at')->sum('amount_uzs') ?: '0');
            $margin = Finance::projectFinance((string) $project->price_uzs, $spent)['marginPct'];
            $commissions = CommissionEngine::accrue($payment, $deal, $firstPayment, $margin === null ? null : (float) $margin);

            Activities::log('payment.paid', ['deal_id' => $deal->id, 'client_id' => $deal->client_id], [
                'number' => Numbers::payment($payment->number),
                'amount' => (string) $payment->amount,
                'currency' => $payment->currency,
                'project' => Numbers::project($project->number),
                'projectCreated' => $created,
            ], $actor);
            Audit::log('payment.confirm', 'payment', $id, [
                'status' => ['old' => 'PENDING', 'new' => 'PAID'],
                'amountUzs' => ['old' => null, 'new' => (string) $payment->amount_uzs],
            ], $actor);
            Outbox::publish('payment.paid', [
                'paymentId' => $id,
                'dealId' => $deal->id,
                'amountUzs' => (string) $payment->amount_uzs,
                'managerId' => $deal->owner_id,
                'teamId' => $deal->team_id,
                'projectId' => $project->id,
                'projectCreated' => $created,
            ], $actor);

            return ['payment' => $payment->refresh(), 'project' => $project, 'projectCreated' => $created, 'commissions' => $commissions];
        });
    }

    /** Направление проекта: услуга сделки, иначе — первая услуга с направлением в принятом КП. */
    private static function directionOf(Deal $deal): ?string
    {
        if ($deal->service_id) {
            $dir = Service::whereKey($deal->service_id)->value('direction_id');
            if ($dir) {
                return $dir;
            }
        }

        return ProposalItem::query()
            ->join('proposals as p', 'p.id', '=', 'proposal_items.proposal_id')
            ->join('services as s', 's.id', '=', 'proposal_items.service_id')
            ->where('p.deal_id', $deal->id)->whereNotNull('s.direction_id')
            ->orderByRaw('p.accepted_at IS NULL, p.accepted_at DESC')->orderBy('proposal_items.sort')
            ->value('s.direction_id');
    }

    /**
     * Rule 1, 3, 4: проект всегда с клиентом и сделкой; РОП — руководитель отдела сделки
     * (нет — подтверждающий РОП/CEO, иначе CEO). Задачи — из шаблона услуги сделки (ТЗ §19 п.6).
     *
     * @return array{project: Project, created: bool}
     */
    private static function ensureProject(Deal $deal, string $actor): array
    {
        $existing = Project::withTrashed()->where('deal_id', $deal->id)->first();
        if ($existing) {
            return ['project' => $existing, 'created' => false];
        }
        $ropId = $deal->team_id ? Team::whereKey($deal->team_id)->value('head_id') : null;
        if (! $ropId && in_array(access()->roleCode, ['ROP', 'CEO'], true)) {
            $ropId = $actor;
        }
        $ropId ??= User::query()->whereHas('role', fn ($q) => $q->where('code', 'CEO'))
            ->where('status', 'ACTIVE')->orderBy('created_at')->value('id');
        if (! $ropId) {
            throw new BusinessRule(t('sales.errors.noRopForProject'));
        }
        $signed = Contract::where('deal_id', $deal->id)->where('status', 'SIGNED')->orderByDesc('signed_at')->first();
        $project = Project::create([
            'direction_id' => self::directionOf($deal),
            'name' => $deal->title,
            'client_id' => $deal->client_id,
            'deal_id' => $deal->id,
            'price' => (string) ($signed?->amount ?? $deal->amount),
            'currency' => $signed?->currency ?? $deal->currency,
            'price_uzs' => (string) ($signed?->amount_uzs ?? $deal->amount_uzs),
            'rop_id' => $ropId,
            'manager_id' => $deal->owner_id,
            'team_id' => $deal->team_id,
            'start_date' => Format::today()->toDateString(),
        ])->refresh();
        Activities::log('project.created', ['deal_id' => $deal->id, 'client_id' => $deal->client_id, 'project_id' => $project->id], [
            'number' => Numbers::project($project->number), 'name' => $project->name,
        ], $actor);
        $template = Templates::forService($deal->service_id);
        if ($template) {
            Templates::apply($project, $template->id, $actor);
        }
        Outbox::publish('project.created', ['projectId' => $project->id, 'dealId' => $deal->id, 'ropId' => $ropId, 'managerId' => $deal->owner_id], $actor);

        return ['project' => $project->refresh(), 'created' => true];
    }

    /** Возврат: отдельная запись REFUND (сразу PAID), сторно комиссий (ТЗ §66, BUSINESS_RULES R13). */
    public static function refund(string $id, array $input): Payment
    {
        $original = self::find($id, 'payment.refund');
        if ($original->status !== 'PAID' || $original->type === 'REFUND') {
            throw new BusinessRule(t('sales.errors.refundPaidOnly'));
        }
        $actor = access()->id();

        return DB::transaction(function () use ($original, $id, $input, $actor) {
            self::lockDeal($original->deal_id);
            $refunded = (string) (Payment::where('refund_of_id', $id)->where('status', 'PAID')->sum('amount_uzs') ?: '0');
            $amountUzs = BigDecimal::of($input['amount'])->multipliedBy((string) $original->exchange_rate)->toScale(2, RoundingMode::HalfUp);
            $available = BigDecimal::of((string) $original->amount_uzs)->minus($refunded);
            if ($amountUzs->isGreaterThan($available)) {
                throw new BusinessRule(t('sales.errors.refundTooMuch', ['amount' => (string) $available->toScale(2)]), ['amount' => t('sales.errors.refundTooMuch', ['amount' => (string) $available->toScale(2)])]);
            }
            $r = Payment::create([
                'deal_id' => $original->deal_id,
                'client_id' => $original->client_id,
                'contract_id' => $original->contract_id,
                'project_id' => $original->project_id,
                'refund_of_id' => $id,
                'amount' => $input['amount'],
                'currency' => $original->currency,
                'exchange_rate' => (string) $original->exchange_rate,
                'amount_uzs' => (string) $amountUzs,
                'type' => 'REFUND',
                'method' => $input['method'],
                'status' => 'PAID',
                'paid_at' => now(),
                'comment' => $input['comment'],
                'confirmed_by_id' => $actor,
                'confirmed_at' => now(),
                'created_by_id' => $actor,
            ])->refresh();
            $deal = Deal::withTrashed()->findOrFail($original->deal_id);
            CommissionEngine::accrue($r, $deal, false);
            Activities::log('payment.refunded', ['deal_id' => $deal->id], [
                'number' => Numbers::payment($r->number), 'amount' => $input['amount'], 'currency' => $original->currency, 'of' => Numbers::payment($original->number),
            ], $actor);
            Audit::log('payment.refund', 'payment', $r->id, [
                'refundOf' => ['old' => null, 'new' => $id],
                'amountUzs' => ['old' => null, 'new' => (string) $amountUzs],
            ], $actor);
            Outbox::publish('payment.refunded', ['paymentId' => $r->id, 'dealId' => $deal->id, 'amountUzs' => (string) $amountUzs], $actor);

            return $r;
        });
    }

    /**
     * Исправление оплаты до подтверждения. Подтверждённую оплату менять нельзя —
     * только возврат (ТЗ §66): по ней уже начислены комиссии и создан проект.
     */
    public static function update(string $id, array $input): Payment
    {
        $before = self::find($id, 'payment.create');
        if ($before->status !== 'PENDING') {
            throw new BusinessRule(t('sales.errors.paymentEditPendingOnly'));
        }
        if (array_key_exists('contract_id', $input)) {
            self::assertContract($input['contract_id'], $before->deal_id);
        }
        $amount = $input['amount'] ?? (string) $before->amount;
        $currency = $input['currency'] ?? $before->currency;
        $money = isset($input['amount']) || isset($input['currency']) ? ExchangeRates::convert($amount, $currency) : null;
        $actor = access()->id();

        return DB::transaction(function () use ($before, $id, $input, $amount, $currency, $money, $actor) {
            self::lockDeal($before->deal_id);
            $current = Payment::findOrFail($id);
            if ($current->status !== 'PENDING') {
                throw new BusinessRule(t('sales.errors.paymentAlreadyDone'));
            }
            $data = array_intersect_key($input, array_flip(['contract_id', 'type', 'method', 'due_date', 'comment']));
            if ($money) {
                $data += ['amount' => $amount, 'currency' => $currency, 'exchange_rate' => $money['rate'], 'amount_uzs' => $money['amountUzs']];
            }
            $fields = ['contract_id', 'amount', 'currency', 'amount_uzs', 'type', 'method', 'due_date', 'comment'];
            $snap = fn (Payment $p) => [
                'contract_id' => $p->contract_id, 'amount' => (string) $p->amount, 'currency' => $p->currency,
                'amount_uzs' => (string) $p->amount_uzs, 'type' => $p->type, 'method' => $p->method,
                'due_date' => $p->due_date?->format('Y-m-d'), 'comment' => $p->comment,
            ];
            $old = $snap($current);
            $current->update($data);
            $after = $current->refresh();
            $changes = Audit::diff($old, array_intersect_key($snap($after), $data), $fields);
            if ($changes) {
                Audit::log('payment.update', 'payment', $id, $changes, $actor);
                Activities::log('payment.updated', ['deal_id' => $before->deal_id], ['number' => Numbers::payment($after->number), 'fields' => array_keys($changes)], $actor);
            }

            return $after;
        });
    }

    public static function cancel(string $id): Payment
    {
        $p = self::find($id, 'payment.create');
        if ($p->status !== 'PENDING') {
            throw new BusinessRule(t('sales.errors.cancelPendingOnly'));
        }
        $actor = access()->id();

        return DB::transaction(function () use ($p, $actor) {
            $p->update(['status' => 'CANCELLED']);
            Audit::log('payment.cancel', 'payment', $p->id, ['status' => ['old' => 'PENDING', 'new' => 'CANCELLED']], $actor);

            return $p->refresh();
        });
    }

    /** Сводка денег по сделке (ТЗ §27): договор, оплачено, возвраты, дебиторка, проект. */
    public static function dealMoney(string $dealId): array
    {
        $deal = CrmAccess::deal($dealId, 'payment.read');
        $sum = fn ($q) => BigDecimal::of((string) ($q->sum('amount_uzs') ?: '0'))->toScale(2);
        $c = $sum(Contract::where('deal_id', $deal->id)->where('status', 'SIGNED'));
        $p = $sum(Payment::where('deal_id', $deal->id)->where('status', 'PAID')->where('type', '<>', 'REFUND'));
        $r = $sum(Payment::where('deal_id', $deal->id)->where('status', 'PAID')->where('type', 'REFUND'));
        $receivable = $c->minus($p)->plus($r);
        $project = Project::where('deal_id', $deal->id)->first();

        return [
            'contractUzs' => (string) $c,
            'paidUzs' => (string) $p,
            'refundedUzs' => (string) $r,
            'receivableUzs' => (string) ($receivable->isNegative() ? BigDecimal::zero()->toScale(2) : $receivable),
            'project' => $project ? ['id' => $project->id, 'name' => $project->name, 'number' => Numbers::project($project->number), 'status' => $project->status] : null,
        ];
    }
}
