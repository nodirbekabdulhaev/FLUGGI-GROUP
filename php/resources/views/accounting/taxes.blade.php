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
        @if ($canExport)
            <a href="/accounting/taxes/export?period={{ $period }}" class="btn btn-outline"><x-icon name="download" /> {{ t('accounting.taxes.export') }}</a>
        @endif
    </x-page-header>
    @include('accounting.partials.tabs', ['active' => '/accounting/taxes'])

    <div class="mb-4 grid gap-3 sm:grid-cols-3">
        <x-stat :label="t('accounting.taxes.totalDue')" :value="money($totalDue)" />
        <x-stat :label="t('accounting.taxes.totalPaid')" :value="money($totalPaid)" />
        <x-stat :label="t('accounting.taxes.bankIncome')" :value="money($bankIncome)" :hint="t('accounting.taxes.bankIncomeHint')" />
    </div>
    @if ($drafts)<p class="mb-3 flex items-center gap-2 text-sm text-warning"><x-icon name="alert" />{{ t('accounting.taxes.draftsWarning', ['count' => $drafts]) }}</p>@endif
    @if (! $payrollRows)<p class="mb-3 text-sm text-muted-foreground">{{ t('accounting.taxes.noPayroll') }} <a class="link" href="/accounting/payroll?period={{ $period }}">{{ t('accounting.tabs.payroll') }}</a></p>@endif
    @if ($missingRate)<p class="mb-3 flex items-center gap-2 text-sm text-warning"><x-icon name="alert" />{{ t('accounting.errors.noUsdRate') }}</p>@endif

    <x-table>
        <thead><tr>
            <th>{{ t('accounting.taxes.tax') }}</th><th class="text-right">{{ t('accounting.taxes.base') }}</th><th class="text-right">{{ t('accounting.taxes.rate') }}</th>
            <th class="text-right">{{ t('accounting.taxes.due') }}</th><th class="text-right">{{ t('accounting.taxes.paid') }}</th><th class="text-right">{{ t('accounting.taxes.balance') }}</th><th></th>
        </tr></thead>
        <tbody>
        @foreach ($taxes as $cat => $tx)
            <tr>
                <td><span class="font-medium">{{ t('accounting.category.'.$cat) }}</span><span class="block text-xs text-muted-foreground">{{ t('accounting.taxes.how.'.$cat) }}</span></td>
                <td class="whitespace-nowrap text-right tabular-nums">{{ $tx['base'] !== null ? money($tx['base']) : '—' }}</td>
                <td class="whitespace-nowrap text-right tabular-nums">{{ $tx['rate'] !== null ? $tx['rate'].'%' : '—' }}</td>
                <td class="whitespace-nowrap text-right font-medium tabular-nums">{{ $tx['due'] !== null ? money($tx['due']) : '—' }}</td>
                <td class="whitespace-nowrap text-right tabular-nums"><a class="link" href="/accounting/operations?category={{ $cat }}&direction=OUT">{{ money($tx['paid']) }}</a></td>
                <td @class(['whitespace-nowrap text-right font-semibold tabular-nums', 'text-warning' => $tx['balance'] !== null && BigDecimal::of($tx['balance'])->isPositive()])>{{ $tx['balance'] !== null ? money($tx['balance']) : '—' }}</td>
                <td class="text-right">
                    @if ($canManage && ($tx['balance'] === null || BigDecimal::of($tx['balance'])->isPositive()))
                        <a href="{{ $payUrl($cat, $tx) }}" class="btn btn-outline btn-sm">{{ t('accounting.taxes.pay') }}</a>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </x-table>
    <p class="mt-3 text-xs text-muted-foreground">{{ t('accounting.taxes.hint', ['ndfl' => $settings['ndflRate'], 'inps' => $settings['inpsRate'], 'itpark' => $settings['itparkRate']]) }}</p>
@endsection
