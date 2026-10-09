<?php

namespace App\Services\Crm;

use App\Exceptions\BusinessRule;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Proposal;
use App\Support\Audit;
use App\Support\Outbox;
use Brick\Math\BigDecimal;

/**
 * Этапы сделки (ТЗ §7). Вперёд — только через «ворота»: КП отправлено, договор создан,
 * договор подписан. «Оплачено» ставится автоматически при подтверждении оплаты.
 * Вызывать внутри транзакции.
 */
final class DealStages
{
    public const CODES = ['NEED_DEFINED', 'PROPOSAL_SENT', 'NEGOTIATION', 'CONTRACT', 'AWAITING_PAYMENT', 'PAID'];

    public static function move(Deal $deal, string $code, string $actorId, bool $audit = true, bool $auto = false): void
    {
        if ($deal->status !== 'OPEN' && ! ($auto && $code === 'PAID')) {
            throw new BusinessRule(t('deals.errors.closed'));
        }
        if ($code === 'PAID' && ! $auto) {
            throw new BusinessRule(t('deals.errors.paidIsAuto'));
        }
        $target = DealStage::where('code', $code)->firstOrFail();
        if ($target->id === $deal->stage_id) {
            return;
        }
        $from = DealStage::findOrFail($deal->stage_id);
        $forward = array_search($code, self::CODES, true) > array_search($from->code, self::CODES, true);
        if ($forward && ! $auto) {
            self::checkGate($deal, $code);
        }

        $deal->update(['stage_id' => $target->id]);
        Activities::stageChange(null, $deal->id, $from->id, $target->id, $actorId);
        Activities::log('deal.stage_changed', ['deal_id' => $deal->id], ['from' => $from->name_ru, 'to' => $target->name_ru, 'auto' => $auto], $actorId);
        if ($audit) {
            Audit::log('deal.stage_change', 'deal', $deal->id, ['stage' => ['old' => $from->code, 'new' => $target->code]], $actorId);
        }
        Outbox::publish('deal.stage_changed', ['dealId' => $deal->id, 'from' => $from->code, 'to' => $target->code], $actorId);
    }

    /** Двигает сделку вперёд (не назад) — автоматические переходы от КП, договоров и оплат. */
    public static function advanceTo(string $dealId, string $code, string $actorId): void
    {
        $deal = Deal::with('stage')->findOrFail($dealId);
        if ($deal->status !== 'OPEN' && $code !== 'PAID') {
            return;
        }
        if (array_search($deal->stage->code, self::CODES, true) >= array_search($code, self::CODES, true)) {
            return;
        }
        self::move($deal, $code, $actorId, audit: false, auto: true);
    }

    private static function checkGate(Deal $deal, string $code): void
    {
        $idx = array_search($code, self::CODES, true);
        if ($idx >= array_search('PROPOSAL_SENT', self::CODES, true)) {
            if (BigDecimal::of((string) $deal->amount)->isLessThanOrEqualTo(0)) {
                throw new BusinessRule(t('deals.errors.amountRequired'), ['amount' => t('deals.errors.amountPositive')]);
            }
            if (! Proposal::where('deal_id', $deal->id)->whereIn('status', ['SENT', 'VIEWED', 'ACCEPTED'])->exists()) {
                throw new BusinessRule(t('deals.errors.sendProposalFirst'));
            }
        }
        if ($idx >= array_search('CONTRACT', self::CODES, true)
            && ! Contract::where('deal_id', $deal->id)->where('status', '<>', 'CANCELLED')->exists()) {
            throw new BusinessRule(t('deals.errors.createContractFirst'));
        }
        if ($idx >= array_search('AWAITING_PAYMENT', self::CODES, true)
            && ! Contract::where('deal_id', $deal->id)->where('status', 'SIGNED')->exists()) {
            throw new BusinessRule(t('deals.errors.contractNotSigned'));
        }
    }
}
