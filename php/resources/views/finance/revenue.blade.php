@extends('layouts.app')
@section('title', t('finance.dashboard.title'))
@section('content')
    <x-page-header :title="t('finance.dashboard.title')" :subtitle="t('finance.dashboard.subtitle')">
        @includeIf('analytics.partials.accountant-package')
        @include('dashboard.partials.period')
    </x-page-header>
    @php($neg = fn ($v) => str_starts_with((string) $v, '-'))
    <div class="grid gap-6">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <x-stat :label="t('finance.kpi.revenue')" :value="money($s['revenueUzs'])" :hint="t('finance.kpi.revenueHint')" />
            <x-stat :label="t('finance.kpi.collected')" :value="money($s['collectedUzs'])" :hint="t('finance.kpi.collectedHint')" />
            <x-stat :label="t('finance.kpi.receivables')" :value="money($s['receivablesUzs'])" :hint="t('finance.kpi.receivablesHint')" />
            <x-stat :label="t('finance.kpi.expenses')" :value="money((string) \Brick\Math\BigDecimal::of($s['projectExpensesUzs'])->plus($s['companyExpensesUzs'] ?? '0'))"
                    :hint="$s['companyExpensesUzs'] !== null ? t('finance.kpi.expensesHint', ['project' => money($s['projectExpensesUzs']), 'company' => money($s['companyExpensesUzs'])]) : t('finance.kpi.projectExpensesHint')" />
            <x-stat :label="t('finance.kpi.grossProfit')" :value="money($s['grossProfitUzs'])" :hint="t('finance.kpi.grossProfitHint')" :tone="$neg($s['grossProfitUzs']) ? 'bad' : null" />
            <x-stat :label="t('finance.kpi.margin')" :value="$s['marginPct'] === null ? '—' : $s['marginPct'].'%'" />
            <x-stat :label="t('finance.kpi.refunds')" :value="money($s['refundsUzs'])" />
            <x-stat :label="t('finance.kpi.commissions')" :value="money($s['commissionsUzs'])" />
            @if ($s['otherIncomeUzs'] !== null)
                <x-stat :label="t('finance.kpi.otherIncome')" :value="money($s['otherIncomeUzs'])" :hint="t('finance.kpi.otherIncomeHint')" />
            @endif
            @if ($s['operatingProfitUzs'] !== null)
                <x-stat :label="t('finance.kpi.operatingProfit')" :value="money($s['operatingProfitUzs'])" :hint="t('finance.kpi.operatingProfitHint')" :tone="$neg($s['operatingProfitUzs']) ? 'bad' : null" />
            @endif
        </div>
        <x-card :title="t('finance.byCategory')">@include('finance.partials.category-bars', ['items' => $s['expensesByCategory']])</x-card>
        @if ($s['operatingProfitUzs'] === null)<p class="text-xs text-muted-foreground">{{ t('finance.dashboard.companyHint') }}</p>@endif
    </div>
@endsection
