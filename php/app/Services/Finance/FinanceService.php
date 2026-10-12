<?php

namespace App\Services\Finance;

use App\Domain\Finance;
use App\Models\Project;
use App\Services\Crm\CrmAccess;
use App\Services\Projects\ProjectAccess;
use App\Services\References\ExchangeRates;
use App\Exceptions\BusinessRule;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/** Финансы проекта и компании (ТЗ §25, §27; формулы — App\Domain\Finance). */
final class FinanceService
{
    private static function d($v): BigDecimal
    {
        return BigDecimal::of($v === null || $v === '' ? '0' : (string) $v);
    }

    private static function money(BigDecimal $v): string
    {
        return (string) $v->toScale(2);
    }

    /** Названия категорий по коду. */
    public static function categoryNames(): array
    {
        return DB::table('finance_categories')->pluck('name', 'code')->all();
    }

    /** Получено по сделкам: оплаты минус возвраты. @return array<string,BigDecimal> */
    public static function collectedByDeal(array $dealIds): array
    {
        if (! $dealIds) {
            return [];
        }
        $out = [];
        foreach (DB::table('payments')->whereIn('deal_id', $dealIds)->where('status', 'PAID')->groupBy('deal_id', 'type')
            ->select('deal_id', 'type', DB::raw('SUM(amount_uzs) AS s'))->get() as $r) {
            $v = self::d($r->s);
            $out[$r->deal_id] = ($out[$r->deal_id] ?? BigDecimal::zero())->plus($r->type === 'REFUND' ? $v->negated() : $v);
        }

        return $out;
    }

    /** Плановые (ещё не начисленные) расходы по тарифу, UZS. */
    public static function plannedCost(string $projectId): BigDecimal
    {
        $lines = DB::table('project_cost_lines')->where('project_id', $projectId)->where('status', 'PLANNED')->get(['quantity', 'rate', 'currency']);
        if ($lines->isEmpty()) {
            return BigDecimal::zero();
        }
        $usd = BigDecimal::zero();
        if ($lines->contains('currency', 'USD')) {
            try {
                $usd = self::d(ExchangeRates::rateFor('USD'));
            } catch (BusinessRule) {
                $usd = BigDecimal::zero();
            }
        }

        return $lines->reduce(fn ($s, $l) => $s->plus(self::d($l->quantity)->multipliedBy(self::d($l->rate))->multipliedBy($l->currency === 'USD' ? $usd : 1)), BigDecimal::zero());
    }

    /** Финансовая карточка проекта (ТЗ §25). */
    public static function project(string $id): array
    {
        $p = ProjectAccess::project($id, 'finance.read');
        $byCat = DB::table('expenses')->where('project_id', $id)->whereNull('deleted_at')->groupBy('category')
            ->select('category', DB::raw('SUM(amount_uzs) AS s'))->get();
        $names = self::categoryNames();
        $expenses = $byCat->reduce(fn ($s, $r) => $s->plus(self::d($r->s)), BigDecimal::zero());
        $got = self::collectedByDeal([$p->deal_id])[$p->deal_id] ?? BigDecimal::zero();
        $commissions = self::d(DB::table('commissions')->where('deal_id', $p->deal_id)->where('status', '<>', 'CANCELLED')->sum('amount_uzs'));
        $overhead = OverheadService::forProjects([$p])[$p->id] ?? BigDecimal::zero();
        $price = self::d($p->price_uzs);
        $f = Finance::projectFinance($price, $expenses);
        $receivable = $price->minus($got);

        return [
            'revenueUzs' => self::money($price),
            'collectedUzs' => self::money($got),
            'receivableUzs' => self::money($receivable->isNegative() ? BigDecimal::zero() : $receivable),
            'expensesUzs' => self::money($expenses),
            'byCategory' => self::sortCats($byCat->map(fn ($r) => ['category' => $r->category, 'name' => $names[$r->category] ?? $r->category, 'amountUzs' => self::money(self::d($r->s))])->all()),
            'grossProfitUzs' => $f['grossProfit'],
            'marginPct' => $f['marginPct'],
            'commissionsUzs' => self::money($commissions),
            'overheadUzs' => self::money($overhead),
            'netProfitUzs' => self::money(self::d($f['grossProfit'])->minus($overhead)),
            'plannedCostUzs' => self::money(self::plannedCost($id)),
        ];
    }

    private static function sortCats(array $rows): array
    {
        usort($rows, fn ($a, $b) => self::d($b['amountUzs'])->compareTo(self::d($a['amountUzs'])));

        return $rows;
    }

    /**
     * Финансовый дашборд (ТЗ §27) за период. Видимость — по праву finance.read;
     * расходы компании и операционная прибыль — только с finance.company.read на всю компанию.
     */
    public static function summary(array $period): array
    {
        $from = $period['from'];
        $to = $period['to'];
        $deals = CrmAccess::deals('finance.read')->select('deals.id');
        $projects = ProjectAccess::projects('finance.read')->select('projects.id');
        $company = access()->can('finance.company.read', 'ALL');
        $dates = [$period['fromDate'], $period['toDate']];
        $pay = fn () => DB::table('payments')->where('status', 'PAID')->where('paid_at', '>=', $from)->where('paid_at', '<', $to)->whereIn('deal_id', $deals);

        $revenue = self::d(DB::table('contracts')->where('status', 'SIGNED')->where('signed_at', '>=', $from)->where('signed_at', '<', $to)->whereIn('deal_id', $deals)->sum('amount_uzs'));
        $collected = self::d($pay()->where('type', '<>', 'REFUND')->sum('amount_uzs'));
        $refunds = self::d($pay()->where('type', 'REFUND')->sum('amount_uzs'));
        $projectExp = self::d(DB::table('expenses')->whereNull('deleted_at')->where('scope', 'PROJECT')->where('expense_date', '>=', $dates[0])->where('expense_date', '<', $dates[1])->whereIn('project_id', $projects)->sum('amount_uzs'));
        $companyExp = $company ? self::d(DB::table('expenses')->whereNull('deleted_at')->where('scope', 'COMPANY')->where('expense_date', '>=', $dates[0])->where('expense_date', '<', $dates[1])->sum('amount_uzs')) : null;
        $commissions = self::d(DB::table('commissions')->where('status', '<>', 'CANCELLED')->where('created_at', '>=', $from)->where('created_at', '<', $to)->whereIn('deal_id', $deals)->sum('amount_uzs'));
        $other = $company ? self::d(DB::table('other_incomes')->whereNull('deleted_at')->where('income_date', '>=', $dates[0])->where('income_date', '<', $dates[1])->sum('amount_uzs')) : null;
        $names = self::categoryNames();
        $byCat = ExpenseService::query()->where('expense_date', '>=', $dates[0])->where('expense_date', '<', $dates[1])
            ->groupBy('category')->select('category', DB::raw('SUM(amount_uzs) AS s'))->get()
            ->map(fn ($r) => ['category' => $r->category, 'name' => $names[$r->category] ?? $r->category, 'amountUzs' => self::money(self::d($r->s))])->all();

        $f = Finance::companyFinance([
            'collected' => $collected, 'refunds' => $refunds, 'projectExpenses' => $projectExp,
            'companyExpenses' => $companyExp ?? BigDecimal::zero(), 'commissions' => $commissions, 'otherIncome' => $other ?? BigDecimal::zero(),
        ]);

        return [
            'from' => $from, 'to' => $to,
            'revenueUzs' => self::money($revenue),
            'collectedUzs' => self::money($collected),
            'refundsUzs' => self::money($refunds),
            'receivablesUzs' => self::money(self::receivables($deals)),
            'projectExpensesUzs' => self::money($projectExp),
            'companyExpensesUzs' => $companyExp ? self::money($companyExp) : null,
            'commissionsUzs' => self::money($commissions),
            'otherIncomeUzs' => $other ? self::money($other) : null,
            'grossProfitUzs' => $f['grossProfit'],
            'operatingProfitUzs' => $company ? $f['operatingProfit'] : null,
            'marginPct' => $f['marginPct'],
            'expensesByCategory' => self::sortCats($byCat),
        ];
    }

    /** Дебиторка на сегодня: по каждой сделке подписано − получено (не меньше нуля). */
    private static function receivables($deals): BigDecimal
    {
        $signed = DB::table('contracts')->where('status', 'SIGNED')->whereIn('deal_id', $deals)->groupBy('deal_id')
            ->select('deal_id', DB::raw('SUM(amount_uzs) AS s'))->get();
        $got = self::collectedByDeal($signed->pluck('deal_id')->all());

        return $signed->reduce(function ($s, $r) use ($got) {
            $left = self::d($r->s)->minus($got[$r->deal_id] ?? BigDecimal::zero());

            return $s->plus($left->isNegative() ? BigDecimal::zero() : $left);
        }, BigDecimal::zero());
    }

    /** Прибыльность проектов: выручка, получено, расходы, валовая прибыль, маржа, накладные. */
    public static function projectsProfit(?string $search, int $perPage = 25)
    {
        $q = ProjectAccess::projects('finance.read')->with('client:id,name');
        if ($search) {
            $term = '%'.$search.'%';
            $q->where(fn ($w) => $w->where('projects.name', 'like', $term)
                ->orWhereIn('projects.client_id', fn ($s) => $s->select('id')->from('clients')->where('name', 'like', $term)));
        }
        $page = $q->orderByDesc('projects.created_at')->paginate($perPage)->withQueryString();
        $rows = $page->getCollection();
        $expenses = $rows->isEmpty() ? collect() : DB::table('expenses')->whereIn('project_id', $rows->pluck('id'))->whereNull('deleted_at')
            ->groupBy('project_id')->select('project_id', DB::raw('SUM(amount_uzs) AS s'))->pluck('s', 'project_id');
        $collected = self::collectedByDeal($rows->pluck('deal_id')->all());
        $overhead = OverheadService::forProjects($rows);
        foreach ($rows as $p) {
            /** @var Project $p */
            $exp = self::d($expenses[$p->id] ?? 0);
            $f = Finance::projectFinance(self::d($p->price_uzs), $exp);
            $oh = $overhead[$p->id] ?? BigDecimal::zero();
            $p->setAttribute('fin', [
                'revenueUzs' => self::money(self::d($p->price_uzs)),
                'collectedUzs' => self::money($collected[$p->deal_id] ?? BigDecimal::zero()),
                'expensesUzs' => self::money($exp),
                'grossProfitUzs' => $f['grossProfit'],
                'marginPct' => $f['marginPct'],
                'overheadUzs' => self::money($oh),
                'netProfitUzs' => self::money(self::d($f['grossProfit'])->minus($oh)),
            ]);
        }

        return $page;
    }
}
