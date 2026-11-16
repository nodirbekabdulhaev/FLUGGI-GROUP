<?php

namespace App\Services\Dashboard;

use App\Auth\Access;
use App\Services\Accounting\Accounting;
use App\Services\Accounting\AccountsService;
use App\Services\Accounting\OverviewService;
use App\Services\Finance\Period;
use Brick\Math\BigDecimal;

/**
 * «Кошельки и счета» на главной: активные счета бухгалтерии с остатком, итог в UZS
 * (USD — по курсу на сегодня) и приходы с начала года. Только с правом accounting.read.
 */
final class WalletsService
{
    public static function get(Access $a): ?array
    {
        if (! $a->can('accounting.read')) {
            return null;
        }
        $accounts = AccountsService::list(true);
        $today = Period::resolve('today')['fromDate'];
        $total = BigDecimal::zero();
        $missingRate = false;
        $rows = [];
        foreach ($accounts as $acc) {
            $uzs = Accounting::toUzs((string) $acc->balance, $acc->currency, $today);
            $missingRate = $missingRate || $uzs === null;
            $total = $total->plus($uzs ?? BigDecimal::zero());
            $rows[] = [
                'id' => $acc->id,
                'name' => $acc->name,
                'kind' => $acc->kind,
                'bank' => $acc->bank_name,
                'currency' => $acc->currency,
                'balance' => (string) $acc->balance,
                'balanceUzs' => $uzs === null ? null : (string) $uzs->toScale(2),
            ];
        }

        return [
            'accounts' => $rows,
            'byCurrency' => AccountsService::totals($accounts),
            'totalUzs' => (string) $total->toScale(2),
            'missingRate' => $missingRate,
            'ytd' => OverviewService::yearToDate(Period::currentMonth()),
        ];
    }
}
