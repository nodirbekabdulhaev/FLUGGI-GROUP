<?php

namespace App\Services\Sales;

use App\Domain\Commission as CommissionFormula;
use App\Domain\People;
use App\Models\Commission;
use App\Models\CommissionRule;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Team;
use App\Support\Format;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Начисление комиссий с подтверждённой оплаты (ТЗ §33–34, Rule 7).
 * Получатели: менеджер сделки и РОП её отдела. Правило выбирается по метрикам получателя
 * за месяц оплаты (заказы, выручка, средний чек), условия — из БД. Вызывать в транзакции.
 */
final class CommissionEngine
{
    /** YYYY-MM по ташкентскому времени. */
    public static function periodOf(DateTimeInterface $at): string
    {
        return CarbonImmutable::instance($at)->setTimezone(Format::tz())->format('Y-m');
    }

    /**
     * Метрики за месяц: менеджер — его сделки, РОП — сделки отдела.
     *
     * @return array<string, int|float>
     */
    public static function metrics(string $role, string $userId, ?string $teamId, string $period, float $usdRate): array
    {
        ['from' => $from, 'to' => $to] = People::monthRange($period);
        $dealFilter = fn ($q) => $role === 'MANAGER' ? $q->where('owner_id', $userId) : $q->where('team_id', $teamId ?? '__none__');
        $orders = $dealFilter(DB::table('deals'))->where('won_at', '>=', $from)->where('won_at', '<', $to)->count();
        $revenue = DB::table('payments as p')
            ->where('p.status', 'PAID')->where('p.paid_at', '>=', $from)->where('p.paid_at', '<', $to)
            ->whereIn('p.deal_id', fn ($q) => $dealFilter($q->select('id')->from('deals')))
            ->get(['p.amount_uzs', 'p.type']);
        $revenueUzs = 0.0;
        foreach ($revenue as $p) {
            $revenueUzs += (float) $p->amount_uzs * ($p->type === 'REFUND' ? -1 : 1);
        }
        $avgUzs = $orders > 0 ? $revenueUzs / $orders : 0;

        return [
            'orders_count' => $orders,
            'revenue_uzs' => $revenueUzs,
            'revenue_usd' => $usdRate ? $revenueUzs / $usdRate : 0,
            'avg_check_uzs' => $avgUzs,
            'avg_check_usd' => $usdRate ? $avgUzs / $usdRate : 0,
        ];
    }

    /**
     * Создаёт комиссии по платежу. Для возврата (REFUND) сторнирует комиссии исходного платежа
     * тем же правилом и ставкой (отрицательная сумма).
     *
     * @param  ?float  $marginPct  маржа проекта на момент оплаты, % — для правил «% от прибыли» (ТЗ §33)
     * @return list<Commission>
     */
    public static function accrue(Payment $payment, Deal $deal, bool $firstPaymentOfDeal, ?float $marginPct = null): array
    {
        $signedUzs = $payment->type === 'REFUND'
            ? BigDecimal::of((string) $payment->amount_uzs)->negated()
            : BigDecimal::of((string) $payment->amount_uzs);
        $period = self::periodOf($payment->paid_at ?? now());

        if ($payment->type === 'REFUND' && $payment->refund_of_id) {
            $created = [];
            foreach (Commission::with('rule')->where('payment_id', $payment->refund_of_id)->get() as $c) {
                $calc = CommissionFormula::calc(
                    ['calcType' => $c->rule->calc_type, 'value' => Numbers::rate($c->rate)],
                    $signedUzs,
                    ['firstPaymentOfDeal' => false, 'marginPct' => null],
                );
                $created[] = Commission::create([
                    'user_id' => $c->user_id,
                    'payment_id' => $payment->id,
                    'deal_id' => $deal->id,
                    'rule_id' => $c->rule_id,
                    'role' => $c->role,
                    'period' => $period,
                    'base_amount_uzs' => (string) $calc['base']->toScale(2),
                    'rate' => (string) $calc['rate'],
                    'amount_uzs' => (string) $calc['amount']->toScale(2),
                    'calc_snapshot' => ['refundOf' => $payment->refund_of_id, 'originalCommission' => $c->id],
                ]);
            }

            return $created;
        }

        $team = $deal->team_id ? Team::find($deal->team_id) : null;
        $recipients = [['role' => 'MANAGER', 'userId' => $deal->owner_id]];
        if ($team?->head_id) {
            $recipients[] = ['role' => 'ROP', 'userId' => $team->head_id];
        }
        $usd = DB::table('exchange_rates')->where('currency', 'USD')->orderByDesc('date')->value('rate_to_uzs');
        $usdRate = $usd ? (float) $usd : 0.0;

        $created = [];
        foreach ($recipients as $r) {
            $rules = CommissionRule::where('applies_to', $r['role'])->where('is_active', true)->get()
                ->map(fn (CommissionRule $x) => [
                    'id' => $x->id,
                    'name' => $x->name,
                    'calcType' => $x->calc_type,
                    'value' => Numbers::rate($x->value),
                    'conditions' => $x->conditions,
                    'priority' => $x->priority,
                    'userId' => $x->user_id,
                ])->all();
            $metrics = self::metrics($r['role'], $r['userId'], $deal->team_id, $period, $usdRate);
            $rule = CommissionFormula::pickRule($rules, $metrics, $r['userId']);
            if (! $rule) {
                continue;
            }
            $calc = CommissionFormula::calc($rule, $signedUzs, ['firstPaymentOfDeal' => $firstPaymentOfDeal, 'marginPct' => $marginPct]);
            if ($calc['amount']->isZero()) {
                continue;
            }
            $created[] = Commission::create([
                'user_id' => $r['userId'],
                'payment_id' => $payment->id,
                'deal_id' => $deal->id,
                'rule_id' => $rule['id'],
                'role' => $r['role'],
                'period' => $period,
                'base_amount_uzs' => (string) $calc['base']->toScale(2),
                'rate' => (string) $calc['rate'],
                'amount_uzs' => (string) $calc['amount']->toScale(2),
                'calc_snapshot' => [
                    'metrics' => $metrics,
                    'rule' => $rule['name'],
                    'conditions' => $rule['conditions'],
                ] + ($rule['calcType'] === CommissionFormula::PERCENT_OF_PROFIT ? ['marginPct' => $marginPct] : []),
            ]);
        }

        return $created;
    }
}
