<?php

namespace App\Services\Accounting;

use App\Models\AccTransaction;
use App\Support\Audit;
use App\Support\Settings;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Проверка «Прочих налогов»: расходы с этой статьёй сгруппированы по получателю — в неё при загрузке выписок попадали
 * и платежи поставщикам со словом «налог» в назначении. Для каждого получателя можно выбрать верную статью;
 * подсказка — по текущим правилам распознавания (BankRules). Остальные поля операции не меняются.
 */
final class OtherTaxes
{
    /** Статьи, в которые можно перенести (расходы ручного ввода). */
    public static function targets(): array
    {
        return Accounting::MANUAL_OUT;
    }

    /**
     * Получатели с суммами по годам.
     *
     * @return Collection<int,array{cp:string,count:int,total:BigDecimal,years:array<string,BigDecimal>,sample:string,suggest:string,first:string,last:string}>
     */
    public static function groups(): Collection
    {
        $rows = AccTransaction::query()->join('acc_accounts as a', 'a.id', '=', 'acc_transactions.account_id')
            ->where('acc_transactions.direction', 'OUT')->where('acc_transactions.category', 'TAX_OTHER')->whereNull('acc_transactions.payment_id')
            ->orderBy('acc_transactions.date')->get(['acc_transactions.*', 'a.currency']);
        $inn = (string) (Settings::company()['inn'] ?? '');
        $articles = collect();
        $groups = [];
        foreach ($rows as $r) {
            $cp = trim((string) $r->counterparty);
            $uzs = Accounting::toUzs((string) $r->amount, $r->currency, $r->date->toDateString()) ?? BigDecimal::zero();
            $g = $groups[$cp] ??= ['cp' => $cp, 'count' => 0, 'total' => BigDecimal::zero(), 'years' => [], 'sample' => (string) $r->description, 'first' => $r->date->toDateString(), 'last' => $r->date->toDateString(),
                'suggest' => BankRules::suggest(['direction' => 'OUT', 'purpose' => (string) $r->description, 'counterparty' => $cp, 'inn' => null], $articles, $inn)['category']];
            $g['count']++;
            $g['total'] = $g['total']->plus($uzs);
            $y = $r->date->format('Y');
            $g['years'][$y] = ($g['years'][$y] ?? BigDecimal::zero())->plus($uzs);
            $g['last'] = $r->date->toDateString();
            $groups[$cp] = $g;
        }

        return collect(array_values($groups))->sortByDesc(fn ($g) => (float) (string) $g['total'])->values();
    }

    /**
     * Перенести операции получателей в выбранные статьи.
     *
     * @param  array<int,array{cp:string,category:string}>  $choices
     * @return array{groups:int,operations:int}
     */
    public static function apply(array $choices): array
    {
        return DB::transaction(function () use ($choices) {
            $groups = 0;
            $ops = 0;
            foreach ($choices as $c) {
                $cat = (string) ($c['category'] ?? '');
                if ($cat === 'TAX_OTHER' || ! in_array($cat, self::targets(), true)) {
                    continue;
                }
                $cp = trim((string) ($c['cp'] ?? ''));
                $q = AccTransaction::where('direction', 'OUT')->where('category', 'TAX_OTHER')->whereNull('payment_id')
                    ->where(fn ($w) => $cp === '' ? $w->whereNull('counterparty')->orWhere('counterparty', '') : $w->where('counterparty', $cp));
                $ids = $q->pluck('id');
                if ($ids->isEmpty()) {
                    continue;
                }
                $keepPeriod = $cat === 'SALARY' || in_array($cat, Accounting::TAX_CATEGORIES, true);
                AccTransaction::whereIn('id', $ids)->update($keepPeriod ? ['category' => $cat, 'category_id' => null] : ['category' => $cat, 'category_id' => null, 'period' => null]);
                Audit::log('accounting.operations.recategorize', 'acc_transaction', null, [
                    'category' => ['old' => 'TAX_OTHER', 'new' => $cat], 'counterparty' => ['old' => null, 'new' => $cp], 'operations' => ['old' => null, 'new' => $ids->count()],
                ]);
                $groups++;
                $ops += $ids->count();
            }

            return ['groups' => $groups, 'operations' => $ops];
        });
    }
}
