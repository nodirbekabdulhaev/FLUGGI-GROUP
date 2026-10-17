<?php

namespace App\Services\Finance;

use App\Exceptions\BusinessRule;
use App\Models\Expense;
use App\Models\FinanceCategory;
use App\Models\User;
use App\Services\Crm\Activities;
use App\Services\Projects\ProjectAccess;
use App\Services\References\ExchangeRates;
use App\Support\Audit;
use App\Support\Format;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Расходы (ТЗ §26). Проектные видит и вносит тот, у кого есть доступ к финансам проекта
 * (по умолчанию — только CEO); расходы компании — только с правом finance.company.read на всю компанию.
 */
final class ExpenseService
{
    public static function companyAllowed(): bool
    {
        return access()->can('finance.company.read', 'ALL');
    }

    /** Расходы, которые пользователь видит (finance.read) или может менять (expense.update). */
    public static function query(string $code = 'finance.read'): Builder
    {
        $projects = ProjectAccess::projects($code)->select('projects.id');
        $company = self::companyAllowed();

        return Expense::query()->where(function ($q) use ($projects, $company) {
            $q->where(fn ($w) => $w->where('expenses.scope', 'PROJECT')->whereIn('expenses.project_id', $projects));
            if ($company) {
                $q->orWhere('expenses.scope', 'COMPANY');
            }
        });
    }

    /** @param  array{scope?:?string,projectId?:?string,category?:?string,dateFrom?:?string,dateTo?:?string}  $f */
    public static function filtered(array $f): Builder
    {
        $q = self::query();
        if (! empty($f['scope'])) {
            $q->where('expenses.scope', $f['scope']);
        }
        if (! empty($f['projectId'])) {
            $q->where('expenses.project_id', $f['projectId']);
        }
        if (! empty($f['category'])) {
            $q->where('expenses.category', $f['category']);
        }
        if (! empty($f['dateFrom'])) {
            $q->where('expenses.expense_date', '>=', $f['dateFrom']);
        }
        if (! empty($f['dateTo'])) {
            $q->where('expenses.expense_date', '<=', $f['dateTo']);
        }

        return $q;
    }

    /** Список с итогом и признаком «можно изменить». */
    public static function list(array $f, int $perPage = 25)
    {
        $page = self::filtered($f)->with(['project:id,number,name', 'payee:id,full_name', 'createdBy:id,full_name', 'categoryRef:code,name'])
            ->orderByDesc('expense_date')->orderByDesc('created_at')->paginate($perPage)->withQueryString();
        $total = (string) (self::filtered($f)->sum('amount_uzs') ?: '0');
        $ids = $page->getCollection()->pluck('id')->all();
        $editable = access()->can('expense.update') && $ids
            ? array_flip(self::query('expense.update')->whereIn('expenses.id', $ids)->pluck('expenses.id')->all()) : [];
        foreach ($page->getCollection() as $e) {
            $e->setAttribute('can_edit', isset($editable[$e->id]));
        }

        return ['page' => $page, 'totalUzs' => $total];
    }

    /** Категория расхода — действующая категория из справочника. */
    private static function assertCategory(string $code): void
    {
        $c = FinanceCategory::where('code', $code)->first();
        if (! $c || $c->kind !== 'EXPENSE' || ! $c->is_active) {
            throw new BusinessRule(t('finance.errors.category'), ['category' => t('finance.errors.categoryField')]);
        }
    }

    private static function assertPayee(?string $userId): void
    {
        if ($userId && ! User::whereKey($userId)->exists()) {
            throw new BusinessRule(t('finance.errors.payee'), ['payee_user_id' => t('finance.errors.payee')]);
        }
    }

    /** @param  array{scope:string,project_id:?string,category:string,amount:string,currency:string,expense_date:string,payee_user_id:?string,description:?string}  $input */
    public static function create(array $input): Expense
    {
        if ($input['scope'] === 'COMPANY') {
            if (! self::companyAllowed()) {
                abort(403, t('finance.expenses.companyOnlyCeo'));
            }
        } else {
            ProjectAccess::project($input['project_id'], 'expense.create');
        }
        self::assertPayee($input['payee_user_id'] ?? null);
        self::assertCategory($input['category']);
        ['rate' => $rate, 'amountUzs' => $amountUzs] = ExchangeRates::convert($input['amount'], $input['currency']);

        return DB::transaction(function () use ($input, $rate, $amountUzs) {
            $e = Expense::create([
                'scope' => $input['scope'],
                'project_id' => $input['scope'] === 'PROJECT' ? $input['project_id'] : null,
                'category' => $input['category'],
                'amount' => $input['amount'],
                'currency' => $input['currency'],
                'exchange_rate' => $rate,
                'amount_uzs' => $amountUzs,
                'expense_date' => $input['expense_date'],
                'payee_user_id' => $input['payee_user_id'] ?? null,
                'description' => $input['description'] ?? null,
                'created_by_id' => access()->id(),
            ]);
            $e->refresh();
            Audit::log('expense.create', 'expense', $e->id, [
                'amountUzs' => ['old' => null, 'new' => $amountUzs],
                'category' => ['old' => null, 'new' => $e->category],
                'projectId' => ['old' => null, 'new' => $e->project_id],
            ]);
            if ($e->project_id) {
                Activities::log('expense.created', ['project_id' => $e->project_id], ['number' => Format::code('EXP', $e->number), 'amountUzs' => $amountUzs, 'category' => $e->category]);
            }

            return $e;
        });
    }

    private static function findEditable(string $id): Expense
    {
        abort_unless(access()->can('expense.update'), 403);

        return self::query('expense.update')->whereKey($id)->firstOrFail();
    }

    /** @param  array<string,mixed>  $input */
    public static function update(string $id, array $input): Expense
    {
        $before = self::findEditable($id);
        if (! empty($input['payee_user_id'])) {
            self::assertPayee($input['payee_user_id']);
        }
        if (isset($input['category']) && $input['category'] !== $before->category) {
            self::assertCategory($input['category']);
        }
        $data = array_intersect_key($input, array_flip(['category', 'expense_date', 'payee_user_id', 'description']));
        if (array_key_exists('amount', $input) || array_key_exists('currency', $input)) {
            $amount = $input['amount'] ?? (string) $before->amount;
            $currency = $input['currency'] ?? $before->currency;
            ['rate' => $rate, 'amountUzs' => $amountUzs] = ExchangeRates::convert($amount, $currency);
            $data += ['amount' => $amount, 'currency' => $currency, 'exchange_rate' => $rate, 'amount_uzs' => $amountUzs];
        }

        return DB::transaction(function () use ($before, $data, $id) {
            $before_ = ['expense_date' => $before->expense_date?->format('Y-m-d'), 'amount' => self::dec($before->amount), 'amount_uzs' => self::dec($before->amount_uzs)] + $before->getAttributes();
            $after = $data;
            if (isset($after['amount'])) {
                $after['amount'] = self::dec($after['amount']);
                $after['amount_uzs'] = self::dec($after['amount_uzs']);
            }
            $changes = Audit::diff($before_, $after, ['category', 'amount', 'currency', 'amount_uzs', 'expense_date', 'payee_user_id', 'description']);
            $before->update($data);
            if ($changes) {
                Audit::log('expense.update', 'expense', $id, $changes);
            }

            return $before->fresh();
        });
    }

    private static function dec($v): string
    {
        return (string) BigDecimal::of((string) $v)->toScale(2, RoundingMode::HalfUp);
    }

    /** Удаление мягкое: запись остаётся в БД и в журнале аудита. */
    public static function remove(string $id): void
    {
        $e = self::findEditable($id);
        DB::transaction(function () use ($e, $id) {
            $e->delete();
            Audit::log('expense.delete', 'expense', $id, ['amountUzs' => ['old' => (string) $e->amount_uzs, 'new' => null]]);
        });
    }
}
