<?php

namespace App\Services\Accounting;

use App\Exceptions\BusinessRule;
use App\Models\AccAccount;
use App\Models\AccTransaction;
use App\Support\Audit;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Счета и кошельки: банковские счета, касса, карта. Остаток = начальный + приходы − расходы. */
final class AccountsService
{
    private const FIELDS = ['name', 'kind', 'bank_name', 'account_number', 'currency', 'opening_balance', 'opening_date', 'crm_methods', 'is_active', 'sort'];

    /** Счета с остатками: [id => balance (строка, 2 знака)]. */
    public static function balances(): array
    {
        $sums = AccTransaction::query()
            ->selectRaw("account_id, SUM(CASE WHEN direction = 'IN' THEN amount ELSE 0 END) AS sin, SUM(CASE WHEN direction = 'OUT' THEN amount ELSE 0 END) AS sout")
            ->groupBy('account_id')->get()->keyBy('account_id');
        $out = [];
        foreach (AccAccount::all() as $a) {
            $s = $sums[$a->id] ?? null;
            $out[$a->id] = (string) Accounting::dec($a->opening_balance)->plus(Accounting::dec($s?->sin))->minus(Accounting::dec($s?->sout))->toScale(2);
        }

        return $out;
    }

    public static function balance(string $id): string
    {
        return self::balances()[$id] ?? '0.00';
    }

    /** Все счета (активные первыми) с остатком и числом операций. */
    public static function list(bool $activeOnly = false): Collection
    {
        $balances = self::balances();
        $counts = AccTransaction::withTrashed()->selectRaw('account_id, COUNT(*) AS n')->groupBy('account_id')->pluck('n', 'account_id');

        return AccAccount::query()->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->orderByDesc('is_active')->orderBy('sort')->orderBy('name')->get()
            ->each(function (AccAccount $a) use ($balances, $counts) {
                $a->setAttribute('balance', $balances[$a->id] ?? '0.00');
                $a->setAttribute('operations_count', (int) ($counts[$a->id] ?? 0));
            });
    }

    /** Итоги остатков по валютам: [UZS => '…', USD => '…'] (только активные счета). */
    public static function totals(Collection $accounts): array
    {
        $t = [];
        foreach ($accounts->where('is_active', true) as $a) {
            $t[$a->currency] = (string) BigDecimal::of($t[$a->currency] ?? '0')->plus($a->balance)->toScale(2);
        }

        return $t;
    }

    public static function save(?string $id, array $data): AccAccount
    {
        $data['crm_methods'] = array_values(array_intersect(Accounting::CRM_METHODS, $data['crm_methods'] ?? []));
        $data['opening_balance'] = (string) Accounting::dec($data['opening_balance'] ?? '0')->toScale(2);
        if ($data['kind'] !== 'BANK') {
            $data['bank_name'] = null;
            $data['account_number'] = null;
        }

        return DB::transaction(function () use ($id, $data) {
            $before = $id ? AccAccount::lockForUpdate()->findOrFail($id) : null;
            if ($before && $before->currency !== $data['currency'] && AccTransaction::withTrashed()->where('account_id', $id)->exists()) {
                throw new BusinessRule(t('accounting.errors.currencyLocked'), ['currency' => t('accounting.errors.currencyLocked')]);
            }
            $old = $before ? $before->only(self::FIELDS) + ['opening_date' => $before->opening_date?->toDateString()] : [];
            $acc = $before ? tap($before)->update($data) : AccAccount::create($data + ['is_active' => true]);
            Audit::log($before ? 'accounting.account.update' : 'accounting.account.create', 'acc_account', $acc->id, Audit::diff($old, $data, self::FIELDS));

            return $acc;
        });
    }

    public static function setActive(string $id, bool $active): void
    {
        DB::transaction(function () use ($id, $active) {
            $acc = AccAccount::lockForUpdate()->findOrFail($id);
            if ($acc->is_active === $active) {
                return;
            }
            $acc->update(['is_active' => $active]);
            Audit::log($active ? 'accounting.account.activate' : 'accounting.account.deactivate', 'acc_account', $id, ['is_active' => ['old' => ! $active, 'new' => $active]]);
        });
    }

    /** Удалить можно только счёт без операций; иначе — деактивировать. */
    public static function delete(string $id): void
    {
        DB::transaction(function () use ($id) {
            $acc = AccAccount::lockForUpdate()->findOrFail($id);
            if (AccTransaction::withTrashed()->where('account_id', $id)->exists()) {
                throw new BusinessRule(t('accounting.errors.accountHasOperations'));
            }
            Audit::log('accounting.account.delete', 'acc_account', $id, ['name' => ['old' => $acc->name, 'new' => null]]);
            $acc->delete();
        });
    }

    /** Счёт для оплат CRM данного способа: активный, у которого способ отмечен (первый по порядку). */
    public static function forCrmMethod(string $method): ?AccAccount
    {
        return AccAccount::where('is_active', true)->whereJsonContains('crm_methods', $method)->orderBy('sort')->orderBy('created_at')->first();
    }
}
