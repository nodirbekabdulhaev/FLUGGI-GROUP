<?php

namespace App\Services\Accounting;

use App\Models\AccCategory;
use App\Models\AccTransaction;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/** Обзор бухгалтерии за месяц: остатки счетов, приходы и расходы по категориям и своим статьям (в UZS), налоги. */
final class OverviewService
{
    public static function month(string $period): array
    {
        [$a, $b] = Accounting::monthDates($period);
        $rows = AccTransaction::query()->join('acc_accounts as acc', 'acc.id', '=', 'acc_transactions.account_id')
            ->leftJoin('payments as p', 'p.id', '=', 'acc_transactions.payment_id')
            ->whereNotIn('acc_transactions.category', ['TRANSFER_IN', 'TRANSFER_OUT'])
            ->where('acc_transactions.date', '>=', $a)->where('acc_transactions.date', '<', $b)
            ->get(['acc_transactions.direction', 'acc_transactions.category', 'acc_transactions.category_id', 'acc_transactions.amount', 'acc_transactions.date', 'acc.currency', 'acc.kind', 'p.amount_uzs as payment_uzs']);
        $in = [];
        $out = [];
        $byArticle = ['IN' => [], 'OUT' => []];
        $bank = BigDecimal::zero();
        $missing = false;
        foreach ($rows as $r) {
            $uzs = Accounting::toUzs((string) $r->amount, $r->currency, $r->date->toDateString(), $r->payment_uzs === null ? null : (string) $r->payment_uzs);
            $missing = $missing || $uzs === null;
            $uzs ??= BigDecimal::zero();
            if ($r->category_id) {
                $byArticle[$r->direction][$r->category][$r->category_id] = ($byArticle[$r->direction][$r->category][$r->category_id] ?? BigDecimal::zero())->plus($uzs);
            }
            if ($r->direction === 'IN') {
                $in[$r->category] = ($in[$r->category] ?? BigDecimal::zero())->plus($uzs);
                if ($r->kind === 'BANK' && in_array($r->category, Accounting::ITPARK_BASE, true)) {
                    $bank = $bank->plus($uzs);
                }
            } else {
                $out[$r->category] = ($out[$r->category] ?? BigDecimal::zero())->plus($uzs);
            }
        }
        $sum = fn (array $xs) => (string) array_reduce($xs, fn ($s, $v) => $s->plus($v), BigDecimal::zero())->toScale(2);
        $fmt = function (array $xs) {
            uasort($xs, fn ($x, $y) => $y->compareTo($x));

            return array_map(fn ($v) => (string) $v->toScale(2), $xs);
        };
        $accounts = AccountsService::list(true);
        $names = AccCategory::whereIn('id', collect($byArticle)->flatMap(fn ($cats) => collect($cats)->flatMap(fn ($xs) => array_keys($xs)))->unique()->values())->pluck('name', 'id');
        // Разбивка по своим статьям внутри базовой категории: [base => [[id, name, sum], …]] по убыванию суммы
        $articles = fn (array $cats) => array_map(function (array $xs) use ($names) {
            uasort($xs, fn ($x, $y) => $y->compareTo($x));

            return array_map(fn ($id, $v) => ['id' => $id, 'name' => $names[$id] ?? '—', 'sum' => (string) $v->toScale(2)], array_keys($xs), $xs);
        }, $cats);

        return [
            'accounts' => $accounts,
            'balances' => AccountsService::totals($accounts),
            'income' => $fmt($in), 'incomeTotal' => $sum($in), 'incomeArticles' => $articles($byArticle['IN']),
            'expenses' => $fmt($out), 'expensesTotal' => $sum($out), 'expensesArticles' => $articles($byArticle['OUT']),
            'bankIncome' => (string) $bank->toScale(2),
            'missingRate' => $missing,
            'taxes' => TaxesService::forPeriod($period),
            'recent' => OperationsService::recent(10),
            'ytd' => self::yearToDate($period),
        ];
    }

    /**
     * Приходы с начала года по конец выбранного месяца (в UZS, без переводов между счетами).
     *
     * @return array{from:string,to:string,income:string,missingRate:bool}
     */
    public static function yearToDate(string $period): array
    {
        [, $b] = Accounting::monthDates($period);
        $a = substr($period, 0, 4).'-01-01';
        $rows = AccTransaction::query()->join('acc_accounts as acc', 'acc.id', '=', 'acc_transactions.account_id')
            ->leftJoin('payments as p', 'p.id', '=', 'acc_transactions.payment_id')
            ->where('acc_transactions.direction', 'IN')->where('acc_transactions.category', '<>', 'TRANSFER_IN')
            ->where('acc_transactions.date', '>=', $a)->where('acc_transactions.date', '<', $b)
            ->get(['acc_transactions.amount', 'acc_transactions.date', 'acc.currency', 'p.amount_uzs as payment_uzs']);
        $in = BigDecimal::zero();
        $missing = false;
        foreach ($rows as $r) {
            $uzs = Accounting::toUzs((string) $r->amount, $r->currency, $r->date->toDateString(), $r->payment_uzs === null ? null : (string) $r->payment_uzs);
            $missing = $missing || $uzs === null;
            $in = $in->plus($uzs ?? BigDecimal::zero());
        }

        return ['from' => $a, 'to' => CarbonImmutable::parse($b)->subDay()->toDateString(), 'income' => (string) $in->toScale(2), 'missingRate' => $missing];
    }
}
