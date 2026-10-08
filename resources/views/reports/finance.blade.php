@extends('layouts.app')
@section('title', __('Финансовая аналитика'))
@section('actions')@include('reports._bar', ['type' => 'finance'])@endsection
@section('content')
@include('partials.period')
<p class="mb-3 text-xs text-slate-500">{{ __('Управленческий учёт (кассовый метод), не официальная бухгалтерия. Прибыль = выручка − расходы.') }}</p>
<div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
    <x-stat label="Revenue" :value="money($d['revenue'])" tone="good" /><x-stat label="Expenses" :value="money($d['expenses'])" />
    <x-stat label="Profit" :value="money($d['profit'])" :tone="$d['profit'] >= 0 ? 'good' : 'bad'" /><x-stat label="Debts" :value="money($d['debts'])" tone="bad" />
    <x-stat label="Payments" :value="$d['payments_count']" /><x-stat label="Average check" :value="money($d['avg_check'])" />
</div>
<div class="mt-4 grid gap-4 lg:grid-cols-3">
    @foreach([[__('По способам оплаты'), $d['by_method']], [__('Расходы по категориям'), $d['by_category']], [__('Выручка по филиалам'), $d['by_branch']]] as [$title, $rows])
    <div class="card card-body"><h2 class="mb-3 text-sm font-semibold">{{ $title }}</h2>
        @php $max = max(1, (float) $rows->max('total')); @endphp
        @forelse($rows as $r)<div class="mb-2"><div class="mb-0.5 flex justify-between text-xs"><span>{{ $r->name }}</span><b>{{ money($r->total, false) }}</b></div><div class="h-2 rounded-full bg-slate-100 dark:bg-slate-800"><div class="h-2 rounded-full bg-brand-600" style="width: {{ max(2, round(abs($r->total) * 100 / $max)) }}%"></div></div></div>
        @empty<p class="text-sm text-slate-400">—</p>@endforelse</div>
    @endforeach
</div>
@endsection
