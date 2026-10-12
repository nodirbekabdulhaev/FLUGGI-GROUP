@extends('layouts.app')
@section('title', t('kpi.title'))
@section('content')
    @php
        use App\Support\Format;
        $me = access();
        $canSet = fn ($r) => $me->can('kpi.target.manage') && ($me->can('kpi.target.manage', 'ALL') || $r['user']->id !== $me->id());
    @endphp
    <x-page-header :title="t('kpi.title')" :subtitle="t('kpi.subtitle')">
        <form method="GET" class="flex items-center gap-2">
            <input type="hidden" name="group" value="{{ $group }}">
            <input type="month" name="period" value="{{ $period }}" class="input w-44" aria-label="{{ t('kpi.period') }}" onchange="this.form.submit()">
        </form>
    </x-page-header>
    @if (count($groups) > 1)
        <x-tabs :items="collect($groups)->mapWithKeys(fn ($g) => ['/kpi?period='.$period.'&group='.$g => t('kpi.groups.'.$g)])->all()" :active="'/kpi?period='.$period.'&group='.$group" />
    @endif
    <div class="card">
        @if (empty($rows))
            <x-empty icon="target" :title="t('kpi.empty')">{{ t('kpi.emptyText') }}</x-empty>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead><tr>
                        <th>{{ t('kpi.employee') }}</th>
                        @if ($group === 'MANAGER')
                            @foreach (['leads', 'meetings', 'proposals', 'contracts', 'orders', 'revenue', 'avgCheck', 'conversion'] as $k)<th class="text-right">{{ t('kpi.m.'.$k) }}</th>@endforeach
                        @elseif ($group === 'ROP')
                            @foreach (['teamRevenue', 'orders', 'avgCheck', 'conversion', 'managers'] as $k)<th class="text-right">{{ t('kpi.m.'.$k) }}</th>@endforeach
                            <th>{{ t('kpi.m.planPct') }}</th><th class="text-right">{{ t('kpi.m.margin') }}</th><th class="text-right">{{ t('kpi.m.projects') }}</th><th class="text-right">{{ t('kpi.m.overdue') }}</th>
                        @else
                            @foreach (['tasks', 'done', 'overdueCount', 'avgHours', 'completion', 'reworks'] as $k)<th class="text-right">{{ t('kpi.m.'.$k) }}</th>@endforeach
                        @endif
                        <th>{{ t('kpi.kpi') }}</th><th></th>
                    </tr></thead>
                    <tbody>
                    @foreach ($rows as $r)
                        <tr>
                            <td class="min-w-56">
                                <span class="font-medium">{{ $r['user']->full_name }}</span>
                                <span class="block text-xs text-muted-foreground">{{ $r['team']?->name }}</span>
                                @if (empty($r['targets']))
                                    <span class="text-xs text-muted-foreground">{{ t('kpi.noTargets') }}</span>
                                @else
                                    <ul class="grid gap-0.5 text-xs text-muted-foreground">
                                        @foreach ($r['targets'] as $tg)<li>@include('people.partials.target-text') · <span class="text-foreground">{{ $tg['pct'] ?? '—' }}%</span></li>@endforeach
                                    </ul>
                                @endif
                            </td>
                            @if ($m = $r['manager'])
                                <td class="text-right tabular-nums">{{ $m['leads'] }}<span class="block text-xs text-muted-foreground">{{ t('kpi.m.processedLeads') }}: {{ $m['processedLeads'] }}</span></td>
                                <td class="text-right tabular-nums">{{ $m['meetings'] }}</td><td class="text-right tabular-nums">{{ $m['proposals'] }}</td>
                                <td class="text-right tabular-nums">{{ $m['contracts'] }}</td><td class="text-right tabular-nums">{{ $m['orders'] }}</td>
                                <td class="whitespace-nowrap text-right tabular-nums">{{ money($m['revenueUzs']) }}</td>
                                <td class="whitespace-nowrap text-right tabular-nums">{{ money($m['avgCheckUzs']) }}</td>
                                <td class="text-right tabular-nums">{{ $m['conversionPct'] ? $m['conversionPct'].'%' : '—' }}</td>
                            @elseif ($k = $r['rop'])
                                <td class="whitespace-nowrap text-right tabular-nums">{{ money($k['teamRevenueUzs']) }}</td>
                                <td class="text-right tabular-nums">{{ $k['orders'] }}</td>
                                <td class="whitespace-nowrap text-right tabular-nums">{{ money($k['avgCheckUzs']) }}</td>
                                <td class="text-right tabular-nums">{{ $k['conversionPct'] ? $k['conversionPct'].'%' : '—' }}</td>
                                <td class="text-right tabular-nums">{{ $k['managers'] }}</td>
                                <td>@include('people.partials.pct-bar', ['pct' => $k['planPct']])<span class="text-xs text-muted-foreground">{{ t('kpi.m.plan') }}: {{ Format::moneyShort($k['planUzs']) }}</span></td>
                                <td class="text-right tabular-nums">{{ $k['marginPct'] ? $k['marginPct'].'%' : '—' }}</td>
                                <td class="text-right tabular-nums">{{ $k['projects'] }}</td>
                                <td class="text-right text-xs">{{ $k['overdueTasks'] }} {{ t('kpi.m.overdueTasks') }}<span class="block">{{ $k['overdueProjects'] }} {{ t('kpi.m.overdueProjects') }}</span></td>
                            @elseif ($e = $r['executor'])
                                <td class="text-right tabular-nums">{{ $e['tasks'] }}</td><td class="text-right tabular-nums">{{ $e['done'] }}</td>
                                <td @class(['text-right tabular-nums', 'text-danger' => $e['overdue'] > 0])>{{ $e['overdue'] }}</td>
                                <td class="text-right tabular-nums">{{ $e['avgHours'] ?? '—' }}</td>
                                <td class="text-right tabular-nums">{{ $e['completionPct'] ? $e['completionPct'].'%' : '—' }}</td>
                                <td class="text-right tabular-nums">{{ $e['reworks'] }}</td>
                            @endif
                            <td>@include('people.partials.pct-bar', ['pct' => $r['kpiPct']])</td>
                            <td class="text-right">@if ($canSet($r))<a href="/kpi?period={{ $period }}&group={{ $group }}&targets={{ $r['user']->id }}" class="btn btn-outline btn-sm"><x-icon name="target" /> {{ t('kpi.setTargets') }}</a>@endif</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
    @isset($editing)
        @php($metrics = \App\Services\People\KpiService::METRICS_FOR[$editing['role']] ?? \App\Services\People\KpiService::METRICS)
        @php($cur = collect($editing['targets'])->keyBy('metric'))
        <x-modal name="kpi-targets" :title="t('kpi.targetsTitle', ['period' => $period, 'name' => $editing['user']->full_name])" :open="true">
            <p class="mb-4 text-sm text-muted-foreground">{{ t('kpi.targetsHint') }}</p>
            <form method="POST" action="/kpi/targets" class="space-y-4">
                @csrf @method('PUT')
                <input type="hidden" name="user_id" value="{{ $editing['user']->id }}"><input type="hidden" name="period" value="{{ $period }}"><input type="hidden" name="group" value="{{ $group }}">
                @foreach ($metrics as $i => $metric)
                    <input type="hidden" name="targets[{{ $i }}][metric]" value="{{ $metric }}">
                    <div @class(['grid gap-2', 'grid-cols-[1fr,6rem]' => $metric === 'REVENUE'])>
                        <div>
                            <label class="label" for="tg-{{ $metric }}">{{ t('kpi.metric.'.$metric) }}</label>
                            <input id="tg-{{ $metric }}" class="input" name="targets[{{ $i }}][value]" inputmode="decimal" value="{{ isset($cur[$metric]) ? rtrim(rtrim($cur[$metric]['target'], '0'), '.') : '' }}">
                        </div>
                        @if ($metric === 'REVENUE')
                            <div><label class="label" for="tg-cur">{{ t('incomes.currency') }}</label>
                                <select id="tg-cur" class="input" name="targets[{{ $i }}][currency]">@foreach (['UZS', 'USD'] as $c)<option @selected(($cur[$metric]['currency'] ?? 'UZS') === $c)>{{ $c }}</option>@endforeach</select></div>
                        @endif
                    </div>
                @endforeach
                <div class="flex justify-end gap-2">
                    <a href="/kpi?period={{ $period }}&group={{ $group }}" class="btn btn-outline">{{ t('common.cancel') }}</a>
                    <button class="btn btn-primary">{{ t('common.save') }}</button>
                </div>
            </form>
        </x-modal>
    @endisset
@endsection
