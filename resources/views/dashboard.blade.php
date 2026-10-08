@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')
@php $f = $data['funnel']; $pct = fn($v) => $v === null ? '—' : $v.'%'; @endphp

<form method="GET" class="mb-4 flex flex-wrap items-end gap-2" x-data="{ custom: {{ $key === 'custom' ? 'true' : 'false' }} }">
    <div class="flex flex-wrap gap-1.5">
        @foreach(\App\Support\Period::LABELS as $k => $label)
            @if($k !== 'custom')
            <button name="period" value="{{ $k }}" class="{{ $key === $k ? 'btn-primary' : 'btn-secondary' }} btn-sm">{{ __($label) }}</button>
            @endif
        @endforeach
        <button type="button" @click="custom = !custom" class="{{ $key === 'custom' ? 'btn-primary' : 'btn-secondary' }} btn-sm">{{ __('Период') }}</button>
    </div>
    <div x-show="custom" x-cloak class="flex items-end gap-2">
        <input type="date" name="from" value="{{ $from->toDateString() }}" class="input !w-auto !py-1.5">
        <input type="date" name="to" value="{{ $to->toDateString() }}" class="input !w-auto !py-1.5">
        <button name="period" value="custom" class="btn-primary btn-sm">OK</button>
    </div>
    <span class="ml-auto text-xs text-slate-500">{{ $from->format('d.m.Y') }} — {{ $to->format('d.m.Y') }}</span>
</form>

<div class="grid grid-cols-2 gap-3 md:grid-cols-4">
    @if($showStudents)<x-stat :label="__('Активные ученики')" :value="number_format($data['active_students'], 0, '.', ' ')" :href="route('students.index', ['status' => 'active'])" />@endif
    @if($showSales)<x-stat :label="__('Новые лиды')" :value="$data['new_leads']" :href="route('leads.index')" />@endif
    @if($showStudents)<x-stat :label="__('Новые ученики')" :value="$data['new_students']" />@endif
    @if($showFinance)
        <x-stat :label="__('Выручка')" :value="money($data['revenue'])" tone="good" />
        <x-stat :label="__('Долги')" :value="money($data['debts'])" tone="bad" :hint="$data['overdue'].' '.__('просрочено')" :href="Gate::allows('debts.view') ? route('debts.index') : null" />
        <x-stat :label="__('Расходы')" :value="money($data['expenses'])" />
        <x-stat :label="__('Прибыль')" :value="money($data['profit'])" :tone="$data['profit'] >= 0 ? 'good' : 'bad'" />
    @endif
    @if($showStudents || Gate::allows('attendance.view'))<x-stat :label="__('Посещаемость')" :value="$pct($data['attendance'])" />@endif
</div>

<div class="mt-4 grid gap-4 xl:grid-cols-3">
    @if($showSales)
    <div class="card card-body xl:col-span-1">
        <h2 class="mb-3 text-sm font-semibold">{{ __('Воронка продаж') }}</h2>
        @php
          $steps = [
            [__('Новые лиды'), $f['total'], null],
            [__('Связались'), $f['contacted'], $f['contact_rate']],
            [__('Записались'), $f['booked'], $f['booking_rate']],
            [__('Пришли'), $f['attended'], $f['show_rate']],
            [__('Купили'), $f['sold'], $f['conversion_rate']],
          ];
        @endphp
        <div class="space-y-2">
        @foreach($steps as $i => [$label, $n, $rate])
            <div>
                <div class="mb-0.5 flex justify-between text-xs"><span>{{ $label }}</span><span class="font-semibold">{{ $n }} @if($rate !== null)<span class="font-normal text-slate-400">· {{ $rate }}%</span>@endif</span></div>
                <div class="h-2.5 rounded-full bg-slate-100 dark:bg-slate-800"><div class="h-2.5 rounded-full bg-brand-600" style="width: {{ $f['total'] ? max(2, round($n * 100 / $f['total'])) : 0 }}%"></div></div>
            </div>
        @endforeach
        </div>
        <dl class="mt-4 grid grid-cols-2 gap-2 text-xs">
            <div class="rounded-lg bg-slate-50 p-2 dark:bg-slate-800"><dt class="text-slate-500">Contact rate</dt><dd class="text-sm font-semibold">{{ $pct($f['contact_rate']) }}</dd></div>
            <div class="rounded-lg bg-slate-50 p-2 dark:bg-slate-800"><dt class="text-slate-500">Booking rate</dt><dd class="text-sm font-semibold">{{ $pct($f['booking_rate']) }}</dd></div>
            <div class="rounded-lg bg-slate-50 p-2 dark:bg-slate-800"><dt class="text-slate-500">Show-up rate</dt><dd class="text-sm font-semibold">{{ $pct($f['show_rate']) }}</dd></div>
            <div class="rounded-lg bg-slate-50 p-2 dark:bg-slate-800"><dt class="text-slate-500">Conversion</dt><dd class="text-sm font-semibold">{{ $pct($f['conversion_rate']) }}</dd></div>
        </dl>
    </div>
    @endif

    <div class="card card-body {{ $showSales ? 'xl:col-span-2' : 'xl:col-span-3' }}">
        <h2 class="mb-3 text-sm font-semibold">{{ $showFinance ? __('Выручка и расходы') : __('Новые лиды') }}</h2>
        <div class="relative h-64"><canvas id="mainChart"></canvas></div>
    </div>

    @if($showSales && $data['sources']->isNotEmpty())
    <div class="card card-body">
        <h2 class="mb-3 text-sm font-semibold">{{ __('Источники лидов') }}</h2>
        <ul class="space-y-2 text-sm">
            @foreach($data['sources'] as $s)
                <li class="flex items-center justify-between"><span>{{ $s['name'] }}</span>
                    <span class="text-xs text-slate-500">{{ $s['leads'] }} {{ __('лидов') }} · {{ $s['sales'] }} {{ __('продаж') }} · <b class="text-slate-800 dark:text-slate-200">{{ $s['conversion'] ?? 0 }}%</b></span></li>
            @endforeach
        </ul>
    </div>
    @endif

    @if($todayLessons->isNotEmpty())
    <div class="card card-body">
        <h2 class="mb-3 flex items-center justify-between text-sm font-semibold">{{ __('Занятия сегодня') }} <a href="{{ route('lessons.index') }}" class="link text-xs font-normal">{{ __('Все') }}</a></h2>
        <ul class="divide-y text-sm dark:divide-slate-800">
            @foreach($todayLessons as $l)
                <li class="flex items-center justify-between py-2"><a class="link" href="{{ route('lessons.show', $l) }}">{{ $l->group?->name }}</a>
                    <span class="text-xs text-slate-500">{{ ftime($l->start_time) }} · {{ $l->room?->name }}</span></li>
            @endforeach
        </ul>
    </div>
    @endif

    @if($topDebts->isNotEmpty())
    <div class="card card-body">
        <h2 class="mb-3 flex items-center justify-between text-sm font-semibold">{{ __('Крупнейшие долги') }} <a href="{{ route('debts.index') }}" class="link text-xs font-normal">{{ __('Все') }}</a></h2>
        <ul class="divide-y text-sm dark:divide-slate-800">
            @foreach($topDebts as $d)
                <li class="flex items-center justify-between py-2"><a class="link" href="{{ route('students.show', $d->student_id) }}">{{ $d->student?->full_name }}</a>
                    <span class="font-medium text-rose-600">{{ money($d->balance) }}</span></li>
            @endforeach
        </ul>
    </div>
    @endif
</div>
@endsection

@push('scripts')
<script src="{{ asset('vendor/chart.umd.min.js') }}"></script>
<script>
(() => {
    const s = @json($data['series']);
    const dark = matchMedia('(prefers-color-scheme: dark)').matches;
    const grid = dark ? '#1e293b' : '#e2e8f0', txt = dark ? '#94a3b8' : '#64748b';
    const fmt = v => new Intl.NumberFormat('ru-RU').format(v);
    const datasets = @json($showFinance)
        ? [{ label: @json(__('Выручка')), data: s.revenue, borderColor: '#4f46e5', backgroundColor: 'rgba(79,70,229,.12)', fill: true, tension: .3 },
           { label: @json(__('Расходы')), data: s.expenses, borderColor: '#e11d48', backgroundColor: 'transparent', tension: .3 }]
        : [{ label: @json(__('Лиды')), data: s.leads, borderColor: '#4f46e5', backgroundColor: 'rgba(79,70,229,.12)', fill: true, tension: .3 }];
    new Chart(document.getElementById('mainChart'), {
        type: 'line', data: { labels: s.labels, datasets },
        options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
            plugins: { legend: { labels: { color: txt, boxWidth: 12 } }, tooltip: { callbacks: { label: c => ' ' + c.dataset.label + ': ' + fmt(c.parsed.y) } } },
            scales: { x: { ticks: { color: txt, maxTicksLimit: 10 }, grid: { display: false } }, y: { beginAtZero: true, ticks: { color: txt, callback: fmt }, grid: { color: grid } } } },
    });
})();
</script>
@endpush
