<?php

namespace App\Services\Accounting;

use App\Models\AccAccount;
use App\Models\AccPayroll;
use App\Models\AccTransaction;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;

/**
 * Налоги месяца (считаются автоматически):
 *  - НДФЛ к уплате = Σ НДФЛ ведомости − Σ ИНПС (если ИНПС удерживается в составе НДФЛ), ИНПС = Σ ИНПС;
 *  - отчисление IT Park = ставка × поступления на банковские счета за месяц (оплаты клиентов и прочие доходы, без переводов).
 * Оплачено — расходы соответствующей категории TAX_* с этим периодом.
 */
final class TaxesService
{
    /**
     * @return array{period:string,settings:array,taxes:array<string,array{due:?string,paid:string,balance:?string,base:?string,rate:?string}>,
     *   payrollRows:int,drafts:int,bankIncome:string,missingRate:bool,totalDue:string,totalPaid:string}
     */
    public static function forPeriod(string $period): array
    {
        $s = Accounting::settings();
        $payroll = AccPayroll::where('period', $period)->get(['gross', 'ndfl', 'inps', 'status']);
        $ndfl = BigDecimal::zero();
        $inps = BigDecimal::zero();
        $gross = BigDecimal::zero();
        foreach ($payroll as $r) {
            $ndfl = $ndfl->plus((string) $r->ndfl);
            $inps = $inps->plus((string) $r->inps);
            $gross = $gross->plus((string) $r->gross);
        }
        $ndflDue = $s['inpsWithinNdfl'] ? $ndfl->minus($inps) : $ndfl;
        ['total' => $bank, 'missingRate' => $missing] = self::bankIncome($period);
        $itpark = Accounting::pct($bank, $s['itparkRate']);
        $paid = self::paid($period);

        $taxes = [];
        foreach ([
            'TAX_NDFL' => [$ndflDue, $gross, $s['ndflRate']],
            'TAX_INPS' => [$inps, $gross, $s['inpsRate']],
            'TAX_ITPARK' => [$itpark, $bank, $s['itparkRate']],
            'TAX_OTHER' => [null, null, null],
        ] as $cat => [$due, $base, $rate]) {
            $p = Accounting::dec($paid[$cat] ?? '0');
            $taxes[$cat] = [
                'due' => $due === null ? null : (string) $due->toScale(2),
                'paid' => (string) $p->toScale(2),
                'balance' => $due === null ? null : (string) $due->minus($p)->toScale(2),
                'base' => $base === null ? null : (string) $base->toScale(2),
                'rate' => $rate,
            ];
        }
        $totalDue = BigDecimal::zero();
        $totalPaid = BigDecimal::zero();
        foreach ($taxes as $t) {
            $totalDue = $totalDue->plus($t['due'] ?? '0');
            $totalPaid = $totalPaid->plus($t['paid']);
        }

        return [
            'period' => $period, 'settings' => $s, 'taxes' => $taxes,
            'payrollRows' => $payroll->count(), 'drafts' => $payroll->where('status', 'DRAFT')->count(),
            'bankIncome' => (string) $bank->toScale(2), 'missingRate' => $missing,
            'totalDue' => (string) $totalDue->toScale(2), 'totalPaid' => (string) $totalPaid->toScale(2),
        ];
    }

    /** Оплачено по категориям TAX_* за период, UZS. */
    public static function paid(string $period): array
    {
        $out = [];
        foreach (self::rows(fn ($q) => $q->where('acc_transactions.direction', 'OUT')->whereIn('acc_transactions.category', Accounting::TAX_CATEGORIES)
            ->where('acc_transactions.period', $period)) as $r) {
            $out[$r->category] = Accounting::dec($out[$r->category] ?? '0')->plus($r->uzs ?? BigDecimal::zero());
        }

        return array_map(fn ($v) => (string) $v->toScale(2), $out);
    }

    /**
     * Поступления на банковские счета за месяц (база IT Park), UZS.
     *
     * @return array{total:BigDecimal,missingRate:bool,rows:Collection}
     */
    public static function bankIncome(string $period): array
    {
        [$a, $b] = Accounting::monthDates($period);
        $rows = self::rows(fn ($q) => $q->where('acc_transactions.direction', 'IN')->whereIn('acc_transactions.category', Accounting::ITPARK_BASE)
            ->where('a.kind', 'BANK')->where('acc_transactions.date', '>=', $a)->where('acc_transactions.date', '<', $b));
        $total = BigDecimal::zero();
        $missing = false;
        foreach ($rows as $r) {
            $missing = $missing || $r->uzs === null;
            $total = $total->plus($r->uzs ?? BigDecimal::zero());
        }

        return ['total' => $total, 'missingRate' => $missing, 'rows' => $rows];
    }

    /** Операции с суммой в UZS (поле uzs; null — нет курса USD). */
    private static function rows(callable $where): Collection
    {
        $q = AccTransaction::query()->join('acc_accounts as a', 'a.id', '=', 'acc_transactions.account_id')
            ->leftJoin('payments as p', 'p.id', '=', 'acc_transactions.payment_id')
            ->select(['acc_transactions.*', 'a.currency', 'a.name as account_name', 'p.amount_uzs as payment_uzs'])
            ->orderBy('acc_transactions.date');
        $where($q);

        return $q->get()->each(function ($r) {
            $r->setAttribute('uzs', Accounting::toUzs((string) $r->amount, $r->currency, $r->date->toDateString(), $r->payment_uzs === null ? null : (string) $r->payment_uzs));
        });
    }

    /** Банковские счета — для кнопки «Оплатить» (первый активный UZS-счёт по умолчанию). */
    public static function payAccount(): ?AccAccount
    {
        return AccAccount::where('is_active', true)->where('kind', 'BANK')->orderByRaw("currency <> 'UZS'")->orderBy('sort')->orderBy('created_at')->first();
    }
}
