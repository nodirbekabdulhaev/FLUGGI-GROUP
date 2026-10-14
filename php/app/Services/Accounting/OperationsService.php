<?php

namespace App\Services\Accounting;

use App\Exceptions\BusinessRule;
use App\Models\AccAccount;
use App\Models\AccPerson;
use App\Models\AccTransaction;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Операции по счетам: приходы, расходы, переводы между своими счетами (две связанные строки).
 * Удаление — мягкое, с обязательной причиной; строки из CRM вручную не удаляются.
 */
final class OperationsService
{
    /**
     * Список с фильтрами: account, direction, category, period (YYYY-MM) или from/to, q.
     *
     * @param  array<string,mixed>  $f
     */
    public static function query(array $f): Builder
    {
        $q = AccTransaction::query()->with(['account', 'article', 'person', 'payment:id,number', 'createdBy:id,full_name']);
        if (! empty($f['account'])) {
            $q->where('account_id', $f['account']);
        }
        if (! empty($f['direction'])) {
            $q->where('direction', $f['direction']);
        }
        if (! empty($f['category'])) {
            // Своя статья — по category_id; базовая категория — все её операции, включая статьи с этой базой
            Str::isUuid($f['category']) ? $q->where('category_id', $f['category']) : $q->where('category', $f['category']);
        }
        if (! empty($f['period'])) {
            [$a, $b] = Accounting::monthDates($f['period']);
            $q->where('date', '>=', $a)->where('date', '<', $b);
        }
        if (! empty($f['from'])) {
            $q->where('date', '>=', $f['from']);
        }
        if (! empty($f['to'])) {
            $q->where('date', '<=', $f['to']);
        }
        if (! empty($f['person'])) {
            $q->where('person_id', $f['person']);
        }
        if (! empty($f['q'])) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $f['q']).'%';
            $q->where(fn ($w) => $w->where('counterparty', 'like', $like)->orWhere('description', 'like', $like)
                ->orWhereHas('person', fn ($p) => $p->where('full_name', 'like', $like)));
        }

        return $q->orderByDesc('date')->orderByDesc('created_at');
    }

    /** Итоги выборки: приходы и расходы по валютам (без переводов). */
    public static function totals(Builder $q): array
    {
        $rows = (clone $q)->reorder()->setEagerLoads([])
            ->join('acc_accounts as a', 'a.id', '=', 'acc_transactions.account_id')
            ->whereNotIn('acc_transactions.category', ['TRANSFER_IN', 'TRANSFER_OUT'])
            ->selectRaw('a.currency, acc_transactions.direction, SUM(acc_transactions.amount) AS total')
            ->groupBy('a.currency', 'acc_transactions.direction')->get();
        $out = [];
        foreach ($rows as $r) {
            $out[$r->direction][$r->currency] = (string) Accounting::dec($r->total)->toScale(2);
        }

        return $out;
    }

    /**
     * Приход или расход, введённый вручную. category — базовая категория ручного ввода либо id своей статьи
     * (тогда в операцию пишется category = база статьи и category_id = статья).
     *
     * @param  array{account_id:string,direction:string,category:string,category_id?:?string,amount:string,date:string,period:?string,counterparty:?string,description:?string,person_id:?string}  $d
     */
    public static function create(array $d): AccTransaction
    {
        $d['category_id'] = ($d['category_id'] ?? null) ?: null;
        if (Str::isUuid($d['category'])) {
            $d['category_id'] = $d['category'];
        }
        if ($d['category_id']) {
            $d['category'] = CategoriesService::forOperation($d['category_id'], $d['direction'])->base;
        }
        if (! in_array($d['category'], Accounting::manual($d['direction']), true)) {
            throw new BusinessRule(t('accounting.errors.category'), ['category' => t('accounting.errors.category')]);
        }
        $cat = $d['category'];
        $d['counterparty'] = trim((string) ($d['counterparty'] ?? '')) ?: null;
        $d['description'] = trim((string) ($d['description'] ?? '')) ?: null;
        $d['period'] = ($d['period'] ?? null) ?: null;
        $d['person_id'] = ($d['person_id'] ?? null) ?: null;
        if ($cat === 'SALARY') {
            if (! $d['person_id']) {
                throw new BusinessRule(t('accounting.errors.personRequired'), ['person_id' => t('accounting.errors.personRequired')]);
            }
            $person = AccPerson::findOrFail($d['person_id']);
            $d['counterparty'] ??= $person->full_name;
        } else {
            $d['person_id'] = null;
        }
        if (($cat === 'SALARY' || in_array($cat, Accounting::TAX_CATEGORIES, true)) && ! $d['period']) {
            throw new BusinessRule(t('accounting.errors.periodRequired'), ['period' => t('accounting.errors.periodRequired')]);
        }
        if ($cat === 'SUPPLIER' && ! $d['counterparty']) {
            throw new BusinessRule(t('accounting.errors.counterpartyRequired'), ['counterparty' => t('accounting.errors.counterpartyRequired')]);
        }

        return DB::transaction(function () use ($d) {
            $acc = self::activeAccount($d['account_id'], 'account_id');
            self::assertDate($acc, $d['date'], 'date');
            if ($d['category_id']) {
                CategoriesService::forOperation($d['category_id'], $d['direction']);
            }
            $row = AccTransaction::create([
                'account_id' => $acc->id, 'direction' => $d['direction'], 'category' => $d['category'], 'category_id' => $d['category_id'],
                'amount' => (string) Accounting::dec($d['amount'])->toScale(2), 'date' => $d['date'], 'period' => $d['period'],
                'counterparty' => $d['counterparty'], 'description' => $d['description'], 'person_id' => $d['person_id'],
                'created_by_id' => access()->id(),
            ]);
            Audit::log('accounting.operation.create', 'acc_transaction', $row->id, self::snapshot($row));

            return $row;
        });
    }

    /**
     * Перевод между своими счетами (например, снятие наличных с банковского счёта): расход и приход
     * с общей transfer_group. Разные валюты — сумма зачисления указывается отдельно.
     *
     * @param  array{from_id:string,to_id:string,amount:string,amount_to:?string,date:string,description:?string}  $d
     * @return array{0:AccTransaction,1:AccTransaction}
     */
    public static function transfer(array $d): array
    {
        if ($d['from_id'] === $d['to_id']) {
            throw new BusinessRule(t('accounting.errors.sameAccount'), ['to_id' => t('accounting.errors.sameAccount')]);
        }

        return DB::transaction(function () use ($d) {
            $from = self::activeAccount($d['from_id'], 'from_id');
            $to = self::activeAccount($d['to_id'], 'to_id');
            self::assertDate($from, $d['date'], 'date');
            self::assertDate($to, $d['date'], 'date');
            $amount = (string) Accounting::dec($d['amount'])->toScale(2);
            if ($from->currency === $to->currency) {
                $amountTo = $amount;
            } elseif (! empty($d['amount_to'])) {
                $amountTo = (string) Accounting::dec($d['amount_to'])->toScale(2);
            } else {
                throw new BusinessRule(t('accounting.errors.amountToRequired'), ['amount_to' => t('accounting.errors.amountToRequired')]);
            }
            $group = (string) Str::uuid();
            $desc = trim((string) ($d['description'] ?? '')) ?: null;
            $common = ['date' => $d['date'], 'description' => $desc, 'transfer_group' => $group, 'created_by_id' => access()->id()];
            $out = AccTransaction::create($common + ['account_id' => $from->id, 'direction' => 'OUT', 'category' => 'TRANSFER_OUT', 'amount' => $amount, 'counterparty' => $to->name]);
            $in = AccTransaction::create($common + ['account_id' => $to->id, 'direction' => 'IN', 'category' => 'TRANSFER_IN', 'amount' => $amountTo, 'counterparty' => $from->name]);
            Audit::log('accounting.transfer.create', 'acc_transaction', $out->id, [
                'from' => ['old' => null, 'new' => $from->name], 'to' => ['old' => null, 'new' => $to->name],
                'amount' => ['old' => null, 'new' => $amount], 'amountTo' => ['old' => null, 'new' => $amountTo],
                'date' => ['old' => null, 'new' => $d['date']], 'transferGroup' => ['old' => null, 'new' => $group],
            ]);

            return [$out, $in];
        });
    }

    /** Мягкое удаление с причиной; перевод удаляется целиком (обе строки). */
    public static function delete(string $id, string $reason): int
    {
        return DB::transaction(function () use ($id, $reason) {
            $row = AccTransaction::lockForUpdate()->findOrFail($id);
            if ($row->payment_id) {
                throw new BusinessRule(t('accounting.errors.crmLocked'));
            }
            $rows = $row->transfer_group
                ? AccTransaction::where('transfer_group', $row->transfer_group)->lockForUpdate()->get()
                : collect([$row]);
            foreach ($rows as $r) {
                $r->forceFill(['deleted_by_id' => access()->id(), 'delete_reason' => $reason])->save();
                $r->delete();
                Audit::log('accounting.operation.delete', 'acc_transaction', $r->id, ['deleted' => ['old' => self::snapshotText($r), 'new' => null], 'reason' => ['old' => null, 'new' => $reason]]);
            }

            return $rows->count();
        });
    }

    /** Последние операции (для обзора). */
    public static function recent(int $limit = 10): Collection
    {
        return self::query([])->limit($limit)->get();
    }

    private static function activeAccount(string $id, string $field): AccAccount
    {
        $acc = AccAccount::lockForUpdate()->find($id);
        if (! $acc || ! $acc->is_active) {
            throw new BusinessRule(t('accounting.errors.accountInactive'), [$field => t('accounting.errors.accountInactive')]);
        }

        return $acc;
    }

    /** Операции до даты начального остатка не вводятся: остаток на эту дату уже их учитывает. */
    private static function assertDate(AccAccount $acc, string $date, string $field): void
    {
        if ($acc->opening_date && $date < $acc->opening_date->toDateString()) {
            $msg = t('accounting.errors.beforeOpening', ['date' => d($acc->opening_date)]);
            throw new BusinessRule($msg, [$field => $msg]);
        }
    }

    private static function snapshot(AccTransaction $r): array
    {
        $out = [];
        foreach (['account_id', 'direction', 'category', 'category_id', 'amount', 'date', 'period', 'counterparty', 'description', 'person_id'] as $k) {
            $v = $r->getAttribute($k);
            if ($v !== null) {
                $out[$k] = ['old' => null, 'new' => $k === 'date' ? $r->date->toDateString() : (string) $v];
            }
        }
        if ($r->category_id) {
            $out['article'] = ['old' => null, 'new' => $r->article?->name];
        }

        return $out;
    }

    private static function snapshotText(AccTransaction $r): string
    {
        $article = $r->category_id ? ' «'.$r->article?->name.'»' : '';

        return "{$r->direction} {$r->category}{$article} {$r->amount} {$r->date->toDateString()}";
    }
}
