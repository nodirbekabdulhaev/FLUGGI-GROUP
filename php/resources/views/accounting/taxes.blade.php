@extends('layouts.app')
@section('title', t('accounting.taxes.title'))
@section('content')
    @php
        use Brick\Math\BigDecimal;
        $payUrl = fn ($cat, $tx) => '/accounting/operations?'.http_build_query(array_filter(['new' => 'OUT', 'p_category' => $cat, 'p_period' => $period,
            'p_amount' => $tx['balance'] !== null && BigDecimal::of($tx['balance'])->isPositive() ? $tx['balance'] : null, 'p_account' => $payAccount?->id]));
    @endphp
    <x-page-header :title="t('accounting.taxes.title')" :subtitle="t('accounting.taxes.subtitle')">
        <form method="GET"><input type="month" name="period" value="{{ $period }}" class="input w-44" aria-label="{{ t('accounting.fields.month') }}" onchange="this.form.submit()"></form>
        <a href="/accounting/tax-report" class="btn btn-primary"><x-icon name="file" /> {{ t('accounting.taxReport.title') }}</a>
        @if ($canExport)
            <a href="/accounting/taxes/export?period={{ $period }}" class="btn btn-outline"><x-icon name="download" /> {{ t('accounting.taxes.export') }}</a>
            <form method="GET" action="/accounting/annual-report" class="flex items-center gap-1">
                <select name="year" class="input h-9 w-auto" aria-label="{{ t('accounting.annual.year') }}">
                    @foreach (range((int) now()->format('Y'), (int) now()->format('Y') - 5) as $y)<option value="{{ $y }}" @selected($y === (int) now()->format('Y') - 1)>{{ $y }}</option>@endforeach
                </select>
                <button class="btn btn-outline" title="{{ t('accounting.annual.hint') }}"><x-icon name="download" /> {{ t('accounting.annual.button') }}</button>
            </form>
        @endif
    </x-page-header>
    @include('accounting.partials.tabs', ['active' => '/accounting/taxes'])

    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat :label="t('accounting.taxes.totalDue')" :value="money($totalDue)" />
        <x-stat :label="t('accounting.taxes.totalPaid')" :value="money($totalPaid)" />
        <x-stat :label="t('accounting.taxes.totalBalance')" :value="money($totalBalance)" :hint="t('accounting.taxes.totalBalanceHint')" />
        <x-stat :label="t('accounting.taxes.bankIncome')" :value="money($bankIncome)" :hint="t('accounting.taxes.bankIncomeHint')" />
    </div>
    @if ($drafts)<p class="mb-3 flex items-center gap-2 text-sm text-warning"><x-icon name="alert" />{{ t('accounting.taxes.draftsWarning', ['count' => $drafts]) }}</p>@endif
    @if (! $payrollRows && $fromSalary)<p class="mb-3 text-sm text-muted-foreground">{{ t('accounting.taxes.fromSalary') }} <a class="link" href="/accounting/people">{{ t('accounting.tabs.people') }}</a></p>
    @elseif (! $payrollRows)<p class="mb-3 text-sm text-muted-foreground">{{ t('accounting.taxes.noPayroll') }} <a class="link" href="/accounting/payroll?period={{ $period }}">{{ t('accounting.tabs.payroll') }}</a></p>@endif
    @if ($missingRate)<p class="mb-3 flex items-center gap-2 text-sm text-warning"><x-icon name="alert" />{{ t('accounting.errors.noUsdRate') }}</p>@endif

    @if (! $actsLoaded && $canManage)<p class="mb-3 flex items-center gap-2 text-sm text-warning"><x-icon name="alert" />{{ t('accounting.taxes.noActs', ['from' => \App\Services\Accounting\Accounting::monthLabel($balanceFrom)]) }} <a class="link" href="/accounting/filed-reports">{{ t('accounting.tabs.filed') }}</a></p>@endif
    @if ($anchored)<p class="mb-3 flex items-center gap-2 text-sm text-primary"><x-icon name="info" />{{ t('accounting.taxes.anchored', ['year' => (int) substr($period, 0, 4) - 1]) }}</p>@endif
    @if ($firstFiled && $balanceFrom > $firstFiled)<p class="mb-3 flex items-center gap-2 text-sm text-warning"><x-icon name="alert" />{{ t('accounting.taxes.startLate', ['from' => \App\Services\Accounting\Accounting::monthLabel($balanceFrom), 'first' => \App\Services\Accounting\Accounting::monthLabel($firstFiled)]) }} <a class="link" href="/accounting/settings#tax-balance">{{ t('accounting.tabs.settings') }}</a></p>@endif
    @if ($settings['regime'] === 'EARLIER')<p class="mb-3 flex items-center gap-2 text-sm text-primary"><x-icon name="info" />{{ t('accounting.taxes.regimeEarlier', ['turnover' => $settings['turnoverRate'], 'ndfl' => $settings['ndflRate'], 'social' => $settings['socialRate']]) }}</p>@endif
    <p class="mb-3 text-sm text-muted-foreground">{{ $balanceFrom <= $period ? t('accounting.taxes.carryFrom', ['month' => \App\Services\Accounting\Accounting::monthLabel($balanceFrom)]) : t('accounting.taxes.carryLater', ['month' => \App\Services\Accounting\Accounting::monthLabel($balanceFrom)]) }}
        @if ($canManage)<a class="link" href="/accounting/settings#tax-balance">{{ t('accounting.tabs.settings') }}</a>@endif</p>

    <x-table>
        <thead><tr>
            <th>{{ t('accounting.taxes.tax') }}</th><th class="text-right">{{ t('accounting.taxes.base') }}</th><th class="text-right">{{ t('accounting.taxes.rate') }}</th>
            <th class="text-right">{{ t('accounting.taxes.opening') }}</th><th class="text-right">{{ t('accounting.taxes.due') }}</th><th class="text-right">{{ t('accounting.taxes.paid') }}</th><th class="text-right">{{ t('accounting.taxes.balance') }}</th><th></th>
        </tr></thead>
        <tbody>
        @foreach ($taxes as $cat => $tx)
            <tr>
                <td><span class="font-medium">{{ t('accounting.category.'.$cat) }}</span><span class="block text-xs text-muted-foreground">{{ t('accounting.taxes.how.'.$cat) }}</span></td>
                <td class="whitespace-nowrap text-right tabular-nums">{{ $tx['base'] !== null ? money($tx['base']) : '—' }}</td>
                <td class="whitespace-nowrap text-right tabular-nums">{{ $tx['rate'] !== null ? $tx['rate'].'%' : '—' }}</td>
                <td @class(['whitespace-nowrap text-right tabular-nums', 'text-warning' => $tx['opening'] !== null && BigDecimal::of($tx['opening'])->isPositive(), 'text-success' => $tx['opening'] !== null && BigDecimal::of($tx['opening'])->isNegative()])>{{ $tx['opening'] !== null ? money($tx['opening']) : '—' }}</td>
                <td class="whitespace-nowrap text-right font-medium tabular-nums">{{ $tx['due'] !== null ? money($tx['due']) : '—' }}</td>
                <td class="whitespace-nowrap text-right tabular-nums"><a class="link" href="/accounting/operations?category={{ $cat }}&direction=OUT">{{ money($tx['paid']) }}</a></td>
                <td @class(['whitespace-nowrap text-right font-semibold tabular-nums', 'text-warning' => $tx['balance'] !== null && BigDecimal::of($tx['balance'])->isPositive(), 'text-success' => $tx['balance'] !== null && BigDecimal::of($tx['balance'])->isNegative()])>{{ $tx['balance'] !== null ? money($tx['balance']) : '—' }}
                    @if ($tx['balance'] !== null && BigDecimal::of($tx['balance'])->isNegative())<span class="block text-xs font-normal">{{ t('accounting.taxes.overpaid') }}</span>@endif</td>
                <td class="text-right">
                    @if ($canManage && ($tx['balance'] === null || BigDecimal::of($tx['balance'])->isPositive()))
                        <a href="{{ $payUrl($cat, $tx) }}" class="btn btn-outline btn-sm">{{ t('accounting.taxes.pay') }}</a>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </x-table>
    @if ($yearly['years'])
        <x-table class="mt-6">
            <caption class="px-3 pb-2 pt-3 text-left text-sm font-semibold">{{ t('accounting.taxes.yearly.title') }}<span class="block text-xs font-normal text-muted-foreground">{{ t('accounting.taxes.yearly.hint', ['month' => \App\Services\Accounting\Accounting::monthLabel($yearly['from'])]) }}</span></caption>
            <thead><tr>
                <th>{{ t('accounting.taxes.yearly.year') }}</th><th class="text-right">{{ t('accounting.taxes.yearly.accrued') }}</th><th class="text-right">{{ t('accounting.taxes.yearly.paid') }}</th>
                <th class="text-right">{{ t('accounting.taxes.yearly.diff') }}</th><th class="text-right">{{ t('accounting.category.TAX_NDFL') }}</th><th class="text-right">{{ t('accounting.taxes.yearly.debt') }}</th><th class="text-right">{{ t('accounting.taxes.yearly.over') }}</th>
            </tr></thead>
            <tbody>
            @foreach (collect($yearly['years'])->sortKeysDesc() as $y => $r)
                <tr>
                    <td class="font-medium">{{ $y }}@if (isset($yearly['acts'][$y]))<span class="block text-xs font-normal text-primary">{{ t('accounting.taxes.yearly.byAct', ['date' => \Carbon\CarbonImmutable::parse($yearly['acts'][$y]['asOf'])->format('d.m.Y')]) }}</span>@endif</td>
                    <td class="whitespace-nowrap text-right tabular-nums">{{ money($r['due']) }}</td>
                    <td class="whitespace-nowrap text-right tabular-nums">{{ money($r['paid']) }}@if (! isset($yearly['acts'][$y]) && BigDecimal::of($r['otherPaid'])->isPositive())<span class="block text-xs text-muted-foreground">{{ t('accounting.taxes.yearly.inOther', ['sum' => money($r['otherPaid'])]) }}@if ($canManage) · <a class="link" href="/accounting/recategorize?category=TAX_OTHER">{{ t('accounting.recategorize.check') }}</a>@endif</span>@endif</td>
                    <td @class(['whitespace-nowrap text-right font-medium tabular-nums', 'text-warning' => BigDecimal::of($r['due'])->isGreaterThan($r['paid']), 'text-success' => BigDecimal::of($r['due'])->isLessThan($r['paid'])])>{{ money((string) BigDecimal::of($r['due'])->minus($r['paid'])) }}</td>
                    <td class="whitespace-nowrap text-right text-xs tabular-nums text-muted-foreground">{{ money($r['ndflDue']) }} / {{ money($r['ndflPaid']) }}</td>
                    <td class="whitespace-nowrap text-right tabular-nums text-warning">{{ money($r['debt']) }}</td>
                    <td class="whitespace-nowrap text-right tabular-nums text-success">{{ money($r['over']) }}</td>
                </tr>
            @endforeach
            </tbody>
        </x-table>
        <p class="mt-2 text-xs text-muted-foreground">{{ t('accounting.taxes.yearly.explain') }}</p>
    @endif
    <p class="mt-3 text-xs text-muted-foreground">{{ t('accounting.taxes.hint', ['ndfl' => $settings['ndflRate'], 'inps' => $settings['inpsRate'], 'itpark' => $settings['itparkRate']]) }}</p>
@endsection
