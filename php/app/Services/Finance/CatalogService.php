<?php

namespace App\Services\Finance;

use App\Exceptions\BusinessRule;
use App\Models\FinanceCategory;
use App\Models\OtherIncome;
use App\Models\User;
use App\Models\WorkItem;
use App\Services\References\ExchangeRates;
use App\Support\Audit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Справочники финансов (CEO): категории доходов и расходов, прочие поступления,
 * единицы работ исполнителей и личные сдельные ставки сотрудников.
 */
final class CatalogService
{
    // ─────────────── Категории ───────────────

    public static function categories(?string $kind = null): Collection
    {
        return FinanceCategory::query()->when($kind, fn ($q) => $q->where('kind', $kind))
            ->withCount(['expenses', 'otherIncomes'])->orderBy('kind')->orderBy('sort')->orderBy('name')->get()
            ->each(fn ($c) => $c->setAttribute('usage', $c->expenses_count + $c->other_incomes_count));
    }

    /** code → название; активные + текущая выбранная. */
    public static function categoryOptions(string $kind, ?string $keep = null): array
    {
        return self::categories($kind)->filter(fn ($c) => $c->is_active || $c->code === $keep)->pluck('name', 'code')->all();
    }

    /** @param  array{kind:string,name:string,account_hint:?string,is_overhead:bool,is_active:bool,sort:int}  $input */
    public static function saveCategory(?string $id, array $input): FinanceCategory
    {
        $data = [
            'name' => $input['name'],
            'account_hint' => $input['account_hint'] ?: null,
            'is_overhead' => $input['kind'] === 'EXPENSE' && $input['is_overhead'],
            'is_active' => $input['is_active'],
            'sort' => $input['sort'],
        ];

        return DB::transaction(function () use ($id, $input, $data) {
            if ($id) {
                $before = FinanceCategory::findOrFail($id);
                if ($before->kind !== $input['kind']) {
                    throw new BusinessRule(t('financeSettings.errors.kind'));
                }
                $old = ['name' => $before->name, 'isOverhead' => $before->is_overhead];
                $before->update($data);
                Audit::log('finance_category.update', 'finance_category', $id, [
                    'name' => ['old' => $old['name'], 'new' => $data['name']],
                    'isOverhead' => ['old' => $old['isOverhead'], 'new' => $data['is_overhead']],
                ]);

                return $before;
            }
            $c = FinanceCategory::create($data + ['code' => 'C_'.strtoupper(bin2hex(random_bytes(4))), 'kind' => $input['kind']]);
            Audit::log('finance_category.create', 'finance_category', $c->id, ['name' => ['old' => null, 'new' => $c->name]]);

            return $c;
        });
    }

    public static function deleteCategory(string $id): void
    {
        $c = self::categories()->firstWhere('id', $id) ?? abort(404);
        if ($c->usage > 0) {
            throw new BusinessRule(t('financeSettings.errors.inUse'));
        }
        DB::transaction(function () use ($c) {
            FinanceCategory::whereKey($c->id)->delete();
            Audit::log('finance_category.delete', 'finance_category', $c->id, ['name' => ['old' => $c->name, 'new' => null]]);
        });
    }

    // ─────────────── Прочие поступления ───────────────

    public static function incomes(array $f, int $perPage = 25)
    {
        return OtherIncome::with(['categoryRef:code,name', 'project:id,number,name', 'client:id,name', 'createdBy:id,full_name'])
            ->when($f['category'] ?? null, fn ($q, $v) => $q->where('category', $v))
            ->when($f['dateFrom'] ?? null, fn ($q, $v) => $q->where('income_date', '>=', $v))
            ->when($f['dateTo'] ?? null, fn ($q, $v) => $q->where('income_date', '<=', $v))
            ->orderByDesc('income_date')->orderByDesc('number')->paginate($perPage)->withQueryString();
    }

    /** @param  array{category:string,amount:string,currency:string,income_date:string,project_id:?string,client_id:?string,description:?string}  $input */
    public static function saveIncome(?string $id, array $input): OtherIncome
    {
        $c = FinanceCategory::where('code', $input['category'])->first();
        if (! $c || $c->kind !== 'INCOME' || ! $c->is_active) {
            throw new BusinessRule(t('incomes.errors.category'), ['category' => t('incomes.errors.categoryField')]);
        }
        ['rate' => $rate, 'amountUzs' => $amountUzs] = ExchangeRates::convert($input['amount'], $input['currency']);
        $data = [
            'category' => $input['category'], 'amount' => $input['amount'], 'currency' => $input['currency'],
            'exchange_rate' => $rate, 'amount_uzs' => $amountUzs, 'income_date' => $input['income_date'],
            'project_id' => $input['project_id'] ?? null, 'client_id' => $input['client_id'] ?? null, 'description' => $input['description'] ?? null,
        ];

        return DB::transaction(function () use ($id, $data, $amountUzs) {
            if ($id) {
                $row = OtherIncome::findOrFail($id);
                $old = (string) $row->amount_uzs;
                $row->update($data);
                Audit::log('other_income.update', 'other_income', $id, ['amountUzs' => ['old' => $old, 'new' => $amountUzs]]);

                return $row;
            }
            $row = OtherIncome::create($data + ['created_by_id' => access()->id()]);
            Audit::log('other_income.create', 'other_income', $row->id, ['amountUzs' => ['old' => null, 'new' => $amountUzs]]);

            return $row->refresh();
        });
    }

    public static function deleteIncome(string $id): void
    {
        $r = OtherIncome::findOrFail($id);
        DB::transaction(function () use ($r, $id) {
            $r->delete();
            Audit::log('other_income.delete', 'other_income', $id, ['amountUzs' => ['old' => (string) $r->amount_uzs, 'new' => null]]);
        });
    }

    // ─────────────── Единицы работ и ставки ───────────────

    public static function workItems(bool $activeOnly = false): Collection
    {
        return WorkItem::when($activeOnly, fn ($q) => $q->where('is_active', true))->orderBy('sort')->orderBy('name')->get();
    }

    /** @param  array{name:string,unit:string,specialty:?string,default_rate:string,currency:string,is_active:bool}  $input */
    public static function saveWorkItem(?string $id, array $input): WorkItem
    {
        return DB::transaction(function () use ($id, $input) {
            if ($id) {
                $w = WorkItem::findOrFail($id);
                $old = (string) $w->default_rate;
                $w->update($input);
            } else {
                $old = null;
                $w = WorkItem::create($input + ['code' => 'W_'.strtoupper(bin2hex(random_bytes(4))), 'sort' => 100]);
            }
            Audit::log($id ? 'work_item.update' : 'work_item.create', 'work_item', $w->id, ['defaultRate' => ['old' => $old, 'new' => (string) $w->fresh()->default_rate]]);

            return $w;
        });
    }

    /** Ставки сотрудника: CEO (зарплаты) — любого, сотрудник — свои. */
    public static function employeeRates(string $userId): Collection
    {
        if ($userId !== access()->id() && ! access()->can('payroll.manage', 'ALL')) {
            abort(403);
        }
        $rates = DB::table('employee_rates')->where('user_id', $userId)->get()->keyBy('work_item_id');

        return self::workItems(true)->each(function ($w) use ($rates) {
            $r = $rates[$w->id] ?? null;
            $w->setAttribute('personal_rate', $r?->rate);
            $w->setAttribute('personal_currency', $r?->currency);
        });
    }

    /** @param  list<array{work_item_id:string, rate:?string, currency:string}>  $rates */
    public static function saveEmployeeRates(string $userId, array $rates): void
    {
        User::findOrFail($userId);
        DB::transaction(function () use ($userId, $rates) {
            foreach ($rates as $r) {
                if ($r['rate'] === null) {
                    DB::table('employee_rates')->where('user_id', $userId)->where('work_item_id', $r['work_item_id'])->delete();
                } else {
                    DB::table('employee_rates')->updateOrInsert(
                        ['user_id' => $userId, 'work_item_id' => $r['work_item_id']],
                        ['rate' => $r['rate'], 'currency' => $r['currency'], 'updated_at' => now()->format('Y-m-d H:i:s.v')],
                    );
                }
            }
            Audit::log('employee.rates', 'user', $userId, ['rates' => ['old' => null, 'new' => $rates]]);
        });
    }

    public static function uuid(): string
    {
        return (string) Str::uuid();
    }
}
