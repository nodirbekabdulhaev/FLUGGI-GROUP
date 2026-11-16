<?php

namespace App\Services\Accounting;

use App\Models\AccCategory;
use App\Models\AccTransaction;
use App\Support\Audit;
use App\Support\Settings;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Переразнесение операций по статьям. Операции выбранной статьи (расход или приход) сгруппированы по получателю и
 * по тому, чем платёж распознаётся сейчас (BankRules по назначению платежа): платежи одного получателя — например,
 * налоговой — с пеней и обычными платежами попадают в разные группы. Для группы выбирается верная статья.
 * Операции со своей статьёй (выбранной вручную) и оплаты из CRM не затрагиваются.
 */
final class Recategorize
{
    /** Статьи, в которые можно перенести; переводы между своими счетами — отдельной статьёй (не расход и не выручка). */
    public static function targets(string $direction): array
    {
        return $direction === 'IN' ? [...Accounting::MANUAL_IN, 'TRANSFER_IN'] : [...Accounting::MANUAL_OUT, 'TRANSFER_OUT'];
    }

    /** Статьи, операции которых можно разбирать, с числом операций. */
    public static function sources(string $direction): array
    {
        $cats = $direction === 'IN' ? Accounting::IN_CATEGORIES : array_values(array_diff(Accounting::OUT_CATEGORIES, ['CLIENT_PAYMENT']));
        $counts = AccTransaction::where('direction', $direction)->whereNull('payment_id')->whereNull('category_id')->whereIn('category', $cats)->groupBy('category')
            ->selectRaw('category, COUNT(*) as n')->pluck('n', 'category');

        return collect($cats)->mapWithKeys(fn ($c) => [$c => (int) ($counts[$c] ?? 0)])->all();
    }

    /** Ключ группы: получатель и распознанная статья. */
    private static function key(string $cp, string $suggest): string
    {
        return sha1($cp."\0".$suggest);
    }

    /** Подсказка по одной операции: те же правила, что при загрузке выписки (в т. ч. ИНН из описания — свои переводы). */
    private static function suggest(AccTransaction $r, string $direction, Collection $articles, string $inn): string
    {
        $desc = (string) $r->description;
        $opInn = preg_match('/(?:ИНН|СТИР|STIR|INN)\s*:\s*(\d{9,14})/iu', $desc, $m) ? $m[1] : null;

        return BankRules::suggest(['direction' => $direction, 'purpose' => $desc, 'counterparty' => trim((string) $r->counterparty), 'inn' => $opInn], $articles, $inn)['category'];
    }

    /**
     * @return Collection<int,array{key:string,cp:string,suggest:string,count:int,total:BigDecimal,years:array<string,BigDecimal>,samples:list<string>,first:string,last:string}>
     */
    public static function groups(string $direction, string $category): Collection
    {
        $inn = (string) (Settings::company()['inn'] ?? '');
        $articles = AccCategory::where('is_active', true)->where('direction', $direction)->get();
        $rows = AccTransaction::query()->join('acc_accounts as a', 'a.id', '=', 'acc_transactions.account_id')
            ->where('acc_transactions.direction', $direction)->where('acc_transactions.category', $category)->whereNull('acc_transactions.payment_id')->whereNull('acc_transactions.category_id')
            ->orderBy('acc_transactions.date')->get(['acc_transactions.*', 'a.currency']);
        $groups = [];
        foreach ($rows as $r) {
            $cp = trim((string) $r->counterparty);
            $sg = self::suggest($r, $direction, $articles, $inn);
            $k = self::key($cp, $sg);
            $uzs = Accounting::toUzs((string) $r->amount, $r->currency, $r->date->toDateString()) ?? BigDecimal::zero();
            $g = $groups[$k] ??= ['key' => $k, 'cp' => $cp, 'suggest' => $sg, 'count' => 0, 'total' => BigDecimal::zero(), 'years' => [], 'samples' => [], 'first' => $r->date->toDateString(), 'last' => $r->date->toDateString()];
            $g['count']++;
            $g['total'] = $g['total']->plus($uzs);
            $y = $r->date->format('Y');
            $g['years'][$y] = ($g['years'][$y] ?? BigDecimal::zero())->plus($uzs);
            $g['last'] = $r->date->toDateString();
            if (count($g['samples']) < 2 && ! in_array(trim((string) $r->description), $g['samples'], true)) {
                $g['samples'][] = trim((string) $r->description);
            }
            $groups[$k] = $g;
        }

        return collect(array_values($groups))->sortByDesc(fn ($g) => (float) (string) $g['total'])->values();
    }

    /**
     * Перенести группы в выбранные статьи.
     *
     * @param  array<int,array{key:string,category:string}>  $choices
     * @return array{groups:int,operations:int}
     */
    public static function apply(string $direction, string $category, array $choices): array
    {
        $want = [];
        foreach ($choices as $c) {
            $to = (string) ($c['category'] ?? '');
            if ($to !== $category && in_array($to, self::targets($direction), true) && preg_match('/^[0-9a-f]{40}$/', (string) ($c['key'] ?? ''))) {
                $want[$c['key']] = $to;
            }
        }
        if (! $want) {
            return ['groups' => 0, 'operations' => 0];
        }
        $inn = (string) (Settings::company()['inn'] ?? '');
        $articles = AccCategory::where('is_active', true)->where('direction', $direction)->get();

        return DB::transaction(function () use ($direction, $category, $want, $inn, $articles) {
            $moved = [];
            $rows = AccTransaction::where('direction', $direction)->where('category', $category)->whereNull('payment_id')->whereNull('category_id')->lockForUpdate()->get();
            foreach ($rows as $r) {
                $cp = trim((string) $r->counterparty);
                $k = self::key($cp, self::suggest($r, $direction, $articles, $inn));
                if (! isset($want[$k])) {
                    continue;
                }
                $to = $want[$k];
                $tax = $to === 'SALARY' || in_array($to, Accounting::TAX_CATEGORIES, true);
                $r->forceFill(['category' => $to, 'period' => $tax ? ($r->period ?: $r->date->format('Y-m')) : null])->save();
                $moved[$k] = ['to' => $to, 'cp' => $cp, 'n' => ($moved[$k]['n'] ?? 0) + 1];
            }
            foreach ($moved as $m) {
                Audit::log('accounting.operations.recategorize', 'acc_transaction', null, [
                    'category' => ['old' => $category, 'new' => $m['to']], 'counterparty' => ['old' => null, 'new' => $m['cp']], 'operations' => ['old' => null, 'new' => $m['n']],
                ]);
            }

            return ['groups' => count($moved), 'operations' => array_sum(array_column($moved, 'n'))];
        });
    }
}
