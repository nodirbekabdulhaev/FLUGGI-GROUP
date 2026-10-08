@extends('layouts.app')
@section('title', __('Аналитика продаж'))
@section('actions')@include('reports._bar', ['type' => 'sources'])@endsection
@section('content')
@include('partials.period')
@php $t = $d['total']; $f = $d['funnel']; $pct = fn($v) => $v === null ? '—' : $v.'%'; @endphp
<div class="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-6">
    <x-stat :label="__('Лидов')" :value="$t['leads']" /><x-stat :label="__('Продаж')" :value="$t['sales']" tone="good" /><x-stat :label="__('Конверсия')" :value="$pct($t['conversion'])" />
    <x-stat :label="__('Выручка с лидов')" :value="money($t['revenue'])" /><x-stat :label="__('Средний чек')" :value="money($t['avg_check'])" />
</div>
<div class="mt-4 grid gap-4 lg:grid-cols-3">
    <div class="card card-body"><h2 class="mb-3 text-sm font-semibold">{{ __('Воронка') }}</h2>
        @foreach([[__('Лиды'), $f['total'], null], [__('Связались'), $f['contacted'], $f['contact_rate']], [__('Записались'), $f['booked'], $f['booking_rate']], [__('Пришли'), $f['attended'], $f['show_rate']], [__('Купили'), $f['sold'], $f['conversion_rate']]] as [$l, $n, $r])
            <div class="mb-2"><div class="mb-0.5 flex justify-between text-xs"><span>{{ $l }}</span><span class="font-semibold">{{ $n }} @if($r !== null)<span class="font-normal text-slate-400">· {{ $r }}%</span>@endif</span></div>
            <div class="h-2.5 rounded-full bg-slate-100 dark:bg-slate-800"><div class="h-2.5 rounded-full bg-brand-600" style="width: {{ $f['total'] ? max(2, round($n * 100 / $f['total'])) : 0 }}%"></div></div></div>
        @endforeach
    </div>
    <div class="card card-body lg:col-span-2"><h2 class="mb-3 text-sm font-semibold">{{ __('Динамика лидов и продаж') }}</h2><div class="relative h-56"><canvas id="c"></canvas></div></div>
</div>
<h2 class="mb-2 mt-6 text-sm font-semibold">{{ __('Источники') }}</h2>@include('reports._sources')
<h2 class="mb-2 mt-6 text-sm font-semibold">{{ __('Менеджеры') }}</h2>@include('reports._managers')
@endsection
@push('scripts')
<script src="{{ asset('vendor/chart.umd.min.js') }}"></script>
<script>
const rows = @json($d['daily']);
new Chart(document.getElementById('c'), { type: 'bar', data: { labels: rows.map(r => r.date.slice(5)), datasets: [
  { label: @json(__('Лиды')), data: rows.map(r => r.leads), backgroundColor: '#818cf8' }, { label: @json(__('Продажи')), data: rows.map(r => r.sales), backgroundColor: '#10b981' }] },
  options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } } });
</script>
@endpush
