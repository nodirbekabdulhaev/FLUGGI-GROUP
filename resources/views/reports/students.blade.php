@extends('layouts.app')
@section('title', __('Аналитика учеников'))
@section('actions')@include('reports._bar', ['type' => 'students'])@endsection
@section('content')
@include('partials.period')
<div class="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-7">
    <x-stat :label="__('Новые')" :value="$d['new']" /><x-stat :label="__('Активные')" :value="$d['active']" tone="good" /><x-stat :label="__('Замороженные')" :value="$d['frozen']" />
    <x-stat :label="__('Отчисленные')" :value="$d['expelled']" tone="bad" /><x-stat :label="__('Завершили')" :value="$d['completed']" />
    <x-stat label="Retention" :value="$d['retention'] === null ? '—' : $d['retention'].'%'" tone="good" /><x-stat label="Churn" :value="$d['churn'] === null ? '—' : $d['churn'].'%'" tone="bad" />
</div>
<div class="mt-4 grid gap-4 lg:grid-cols-2">
    <div class="card card-body"><h2 class="mb-3 text-sm font-semibold">{{ __('Почему уходят ученики') }}</h2>
        @php $max = max(1, (int) $d['reasons']->max('c')); @endphp
        @forelse($d['reasons'] as $r)<div class="mb-2"><div class="mb-0.5 flex justify-between text-xs"><span>{{ $r->name }}</span><b>{{ $r->c }}</b></div><div class="h-2 rounded-full bg-slate-100 dark:bg-slate-800"><div class="h-2 rounded-full bg-rose-500" style="width: {{ round($r->c * 100 / $max) }}%"></div></div></div>
        @empty<p class="text-sm text-slate-400">{{ __('Отчислений за период нет') }}</p>@endforelse</div>
    <div class="card card-body"><h2 class="mb-3 text-sm font-semibold">{{ __('Активные по филиалам') }}</h2>
        @forelse($d['by_branch'] as $b)<div class="flex justify-between border-b py-1.5 text-sm last:border-0 dark:border-slate-800"><span>{{ $b->name }}</span><b>{{ $b->c }}</b></div>@empty<p class="text-sm text-slate-400">—</p>@endforelse</div>
</div>
@endsection
