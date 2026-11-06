@extends('layouts.app')
@section('title', t('analytics.title'))
@php
    use App\Support\Format;
    $short = fn ($v) => Format::moneyShort((string) $v);
    $pct = fn ($v) => str_replace('.', ',', (string) round((float) $v, 1)).'%';
    $q = fn (array $extra = []) => route('analytics', array_filter(array_merge(['tab' => $tab, 'period' => $period, 'from' => $fromInput, 'to' => $toInput, 'user' => $userId], $extra), fn ($v) => $v !== null && $v !== ''));
    $tabs = collect(\App\Http\Controllers\Analytics\AnalyticsController::TABS)->mapWithKeys(fn ($k) => [$q(['tab' => $k, 'page' => null, 'health' => null, 'sort' => null]) => t('analytics.tabs.'.$k)])->all();
    $months = fn (string $ym) => t('analytics.months.'.(int) substr($ym, 5, 2)).' '.substr($ym, 0, 4);
    $pointLabel = function (string $key, string $g) {
        if ($g === 'month') {
            return t('analytics.monthsShort.'.(int) substr($key, 5, 2)).' '.substr($key, 0, 4);
        }
        $d = (int) substr($key, 8, 2).' '.t('analytics.monthsShort.'.(int) substr($key, 5, 2));

        return $g === 'week' ? t('analytics.weekFrom', ['date' => $d]) : $d;
    };
    $palette = ['#60a5fa', '#818cf8', '#f9a8d4', '#6ee7b7', '#fcd34d', '#a5b4fc', '#fda4af', '#7dd3fc', '#c4b5fd', '#5eead4', '#fdba74', '#cbd5e1'];
    $stat = function (string $label, string $value, $change = false) {
        return ['label' => $label, 'value' => $value, 'change' => $change];
    };
@endphp
@section('content')
    <x-page-header :title="t('analytics.title')" :subtitle="t('analytics.subtitle')">
        <form method="GET" class="flex flex-wrap items-center gap-2" x-data="{ period: @js($period) }">
            <input type="hidden" name="tab" value="{{ $tab }}">
            @if ($users)
                <select name="user" class="input h-9 w-auto" aria-label="{{ t('analytics.employee') }}" onchange="this.form.submit()">
                    <option value="">{{ t('analytics.allEmployees') }}</option>
                    @foreach ($users as $id => $name)<option value="{{ $id }}" @selected($userId === $id)>{{ $name }}</option>@endforeach
                </select>
            @elseif ($userId)
                <input type="hidden" name="user" value="{{ $userId }}">
            @endif
            @unless (in_array($tab, ['forecast', 'clients'], true))
                <select name="period" class="input h-9 w-auto" aria-label="{{ t('period.label') }}" x-model="period" @change="period !== 'custom' && $el.form.submit()">
                    @foreach (\App\Services\Analytics\Period::PRESETS as $p)<option value="{{ $p }}">{{ t('period.'.$p) }}</option>@endforeach
                </select>
                <template x-if="period === 'custom'">
                    <span class="flex flex-wrap items-center gap-2">
                        <input type="date" name="from" class="input h-9 w-auto" value="{{ $fromInput }}" aria-label="{{ t('period.from') }}">
                        <input type="date" name="to" class="input h-9 w-auto" value="{{ $toInput }}" aria-label="{{ t('period.to') }}">
                        <button class="btn btn-outline">{{ t('period.apply') }}</button>
                    </span>
                </template>
            @endunless
        </form>
    </x-page-header>
    <x-tabs :items="$tabs" :active="$q(['page' => null, 'health' => null, 'sort' => null])" />

    @if ($tab === 'sales')
        @php
            $stats = [
                [t('analytics.revenue'), $short($data['totals']['revenue']), $data['change']['revenue']],
                [t('analytics.collected'), $short($data['totals']['collected']), $data['change']['collected']],
                [t('analytics.won'), (string) $data['totals']['won'], $data['change']['won']],
                [t('analytics.leads'), (string) $data['totals']['leads'], $data['change']['leads']],
            ];
        @endphp
        <div class="grid grid-cols-1 gap-4">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($stats as [$label, $value, $change])
                    <div class="card card-body">
                        <p class="text-sm text-muted-foreground">{{ $label }}</p>
                        <p class="text-2xl font-semibold tabular-nums">{{ $value }}</p>
                        @if ($change === null)<p class="text-xs text-muted-foreground">{{ t('analytics.noCompare') }}</p>
                        @else<p @class(['text-xs', 'text-success' => $change >= 0, 'text-danger' => $change < 0])>{{ $change > 0 ? '+' : '' }}{{ str_replace('.', ',', (string) $change) }}% {{ t('analytics.vsPrev') }}</p>@endif
                    </div>
                @endforeach
            </div>
            <x-card :title="t('analytics.salesTitle')" :subtitle="t('analytics.salesText', ['avg' => $short($data['totals']['avgCheck'])])">
                @include('dashboard.partials.chart', ['empty' => ! collect($data['points'])->contains(fn ($p) => (float) $p['revenue'] != 0 || (float) $p['collected'] != 0),
                    'label' => t('analytics.salesTitle'), 'height' => 'h-64', 'config' => [
                    'type' => count($data['points']) > 1 ? 'line' : 'bar', 'format' => 'money', 'unit' => 'UZS', 'legend' => true,
                    'labels' => array_map(fn ($p) => $pointLabel($p['key'], $data['granularity']), $data['points']),
                    'series' => [['label' => t('analytics.revenue'), 'data' => array_column($data['points'], 'revenue'), 'color' => '#60a5fa'],
                        ['label' => t('analytics.collected'), 'data' => array_column($data['points'], 'collected'), 'color' => '#f472b6', 'fill' => false]],
                ]])
                <details class="mt-3">
                    <summary class="cursor-pointer text-xs text-muted-foreground">{{ t('analytics.showTable') }}</summary>
                    <div class="mt-2 max-h-80 overflow-auto">
                        <table class="table">
                            <thead><tr><th>{{ t('analytics.date') }}</th><th class="text-right">{{ t('analytics.revenue') }}</th><th class="text-right">{{ t('analytics.collected') }}</th><th class="text-right">{{ t('analytics.won') }}</th><th class="text-right">{{ t('analytics.leads') }}</th></tr></thead>
                            <tbody>
                                @foreach ($data['points'] as $p)
                                    <tr><td>{{ $pointLabel($p['key'], $data['granularity']) }}</td><td class="whitespace-nowrap text-right tabular-nums">{{ money($p['revenue']) }}</td>
                                        <td class="whitespace-nowrap text-right tabular-nums">{{ money($p['collected']) }}</td><td class="text-right">{{ $p['won'] }}</td><td class="text-right">{{ $p['leads'] }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </details>
            </x-card>
        </div>
    @elseif ($tab === 'funnel')
        <x-card :title="t('analytics.funnelTitle')" :subtitle="t('analytics.funnelText')">
            @if ($data[0]['count'] === 0)
                <x-empty :title="t('analytics.empty')" />
            @else
                <div class="grid gap-6 lg:grid-cols-[repeat(2,minmax(0,1fr))]">
                    @include('dashboard.partials.chart', ['label' => t('analytics.funnelTitle'), 'height' => 'h-72', 'config' => [
                        'type' => 'hbar', 'labels' => array_column($data, 'label'), 'legend' => false,
                        'series' => [['label' => t('analytics.leads'), 'data' => array_column($data, 'count'), 'color' => '#818cf8']],
                    ]])
                    @include('analytics.bars', ['rows' => array_map(fn ($s, $i) => ['label' => $s['label'], 'value' => $s['count'], 'display' => (string) $s['count'],
                        'sub' => $i === 0 ? null : t('analytics.fromPrev', ['prev' => $s['fromPrev'], 'start' => $s['fromStart']])], $data, array_keys($data))])
                </div>
            @endif
        </x-card>
    @elseif ($tab === 'sources' || $tab === 'services')
        @if (! $data)
            <x-card><x-empty :title="t('analytics.empty')" /></x-card>
        @else
            <div class="grid gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
                <x-card :title="t($tab === 'sources' ? 'analytics.sourcesTitle' : 'analytics.servicesTitle')" :subtitle="t('analytics.revenueByLeads')">
                    @php
                        $byRevenue = collect($data)->contains(fn ($r) => (float) $r['revenue'] > 0);
                        $donut = array_slice($data, 0, 10);
                    @endphp
                    @include('dashboard.partials.chart', ['label' => t($tab === 'sources' ? 'analytics.sourcesTitle' : 'analytics.servicesTitle'), 'height' => 'h-52', 'config' => [
                        'type' => 'doughnut', 'labels' => array_column($donut, 'name'), 'legend' => false, 'format' => $byRevenue ? 'money' : 'number', 'unit' => 'UZS',
                        'series' => [['label' => $byRevenue ? t('analytics.revenue') : t('analytics.leads'), 'data' => $byRevenue ? array_column($donut, 'revenue') : array_column($donut, 'leads')]],
                    ]])
                    <ul class="mt-4 grid grid-cols-1 gap-1.5 text-sm">
                        @foreach ($data as $i => $r)
                            <li class="flex min-w-0 items-center gap-2"><span class="size-2.5 shrink-0 rounded-full" style="background: {{ $i < 10 ? $palette[$i % count($palette)] : '#e2e8f0' }}"></span>
                                <span class="min-w-0 flex-1 truncate">{{ $r['name'] }}</span><span class="whitespace-nowrap font-medium tabular-nums">{{ $byRevenue ? $short($r['revenue']) : $r['leads'] }}</span></li>
                        @endforeach
                    </ul>
                </x-card>
                <x-table>
                    <thead><tr><th>{{ t($tab === 'sources' ? 'analytics.source' : 'analytics.service') }}</th><th class="text-right">{{ t('analytics.leads') }}</th><th class="text-right">{{ t('analytics.deals') }}</th>
                        <th class="text-right">{{ t('analytics.paidDeals') }}</th><th class="text-right">{{ t('analytics.conversion') }}</th><th class="text-right">{{ t('analytics.avgCheck') }}</th></tr></thead>
                    <tbody>
                        @foreach ($data as $r)
                            <tr><td class="font-medium">{{ $r['name'] }}</td><td class="text-right tabular-nums">{{ $r['leads'] }}</td><td class="text-right tabular-nums">{{ $r['deals'] }}</td>
                                <td class="text-right tabular-nums">{{ $r['won'] }}</td><td class="text-right tabular-nums">{{ $pct($r['conversion']) }}</td><td class="whitespace-nowrap text-right tabular-nums">{{ $short($r['avgCheck']) }}</td></tr>
                        @endforeach
                    </tbody>
                </x-table>
            </div>
        @endif
    @elseif ($tab === 'losses')
        <div class="mb-4 grid gap-4 sm:grid-cols-3">
            <x-stat :label="t('analytics.leadsQualified')" :value="(string) $qualification['qualified']" />
            <x-stat :label="t('analytics.leadsUnqualified')" :value="(string) $qualification['unqualified']" />
            <x-stat :label="t('analytics.qualificationRate')" :value="$qualification['rate'] === null ? '—' : $qualification['rate'].'%'" />
        </div>
        <x-card :title="t('analytics.lossesTitle')" :subtitle="t('analytics.lossesText')">
            @if (! $data)
                <x-empty :title="t('analytics.noLosses')" />
            @else
                <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
                @include('dashboard.partials.chart', ['label' => t('analytics.lossesTitle'), 'height' => 'h-56', 'config' => [
                    'type' => 'doughnut', 'labels' => array_column($data, 'name'), 'legend' => false,
                    'series' => [['label' => t('home.charts.losses'), 'data' => array_map(fn ($r) => $r['leads'] + $r['deals'], $data), 'colors' => array_map(fn ($i) => ['#fda4af', '#fcd34d', '#f9a8d4', '#fdba74', '#c4b5fd', '#a5b4fc', '#7dd3fc', '#6ee7b7', '#cbd5e1'][$i % 9], array_keys($data))]],
                ]])
                <div class="min-w-0">
                @include('analytics.bars', ['color' => 'bg-warning', 'rows' => array_map(fn ($r) => ['label' => $r['name'], 'value' => $r['leads'] + $r['deals'], 'display' => (string) ($r['leads'] + $r['deals']),
                    'sub' => t('analytics.lossSub', ['leads' => $r['leads'], 'deals' => $r['deals'], 'amount' => $short($r['amount'])])], $data)])
                </div>
                </div>
            @endif
        </x-card>
    @elseif ($tab === 'forecast')
        @php
            $first = $data['months'][0];
        @endphp
        <div class="grid grid-cols-1 gap-4">
            <div class="grid gap-4 sm:grid-cols-3">
                <x-stat :label="t('analytics.forecastMonth')" :value="$short($first['total'])" />
                <x-stat :label="t('analytics.planMonth')" :value="$first['plan'] ? $short($first['plan']) : t('analytics.noPlan')" />
                <x-stat :label="t('analytics.pipeline')" :value="$short($data['pipeline'])" />
            </div>
            <x-card :title="t('analytics.forecastTitle')" :subtitle="t('analytics.forecastText')">
                @php
                    $hasPlan = collect($data['months'])->contains(fn ($m) => $m['plan'] !== null);
                @endphp
                @include('dashboard.partials.chart', ['empty' => ! collect($data['months'])->contains(fn ($m) => (float) $m['total'] != 0 || $m['plan']),
                    'label' => t('analytics.forecastTitle'), 'height' => 'h-64', 'config' => [
                    'type' => 'bar', 'stacked' => true, 'format' => 'money', 'unit' => 'UZS', 'legend' => true,
                    'labels' => array_map(fn ($m) => $months($m['month']), $data['months']),
                    'series' => array_values(array_filter([
                        ['label' => t('analytics.collected'), 'data' => array_column($data['months'], 'collected'), 'color' => '#60a5fa', 'stack' => 'f'],
                        ['label' => t('analytics.scheduled'), 'data' => array_column($data['months'], 'scheduled'), 'color' => '#fcd34d', 'stack' => 'f'],
                        ['label' => t('analytics.weighted'), 'data' => array_column($data['months'], 'weighted'), 'color' => '#6ee7b7', 'stack' => 'f'],
                        $hasPlan ? ['label' => t('analytics.plan'), 'data' => array_map(fn ($m) => $m['plan'], $data['months']), 'color' => '#f472b6', 'kind' => 'line', 'dashed' => true, 'fill' => false] : null,
                    ])),
                ]])
                <div class="mt-4 overflow-x-auto">
                    <table class="table">
                        <thead><tr><th>{{ t('analytics.month') }}</th><th class="text-right">{{ t('analytics.collected') }}</th><th class="text-right">{{ t('analytics.scheduled') }}</th>
                            <th class="text-right">{{ t('analytics.weighted') }}</th><th class="text-right">{{ t('analytics.total') }}</th><th class="text-right">{{ t('analytics.plan') }}</th></tr></thead>
                        <tbody>
                            @foreach ($data['months'] as $m)
                                <tr><td>{{ $months($m['month']) }}</td><td class="whitespace-nowrap text-right tabular-nums">{{ $short($m['collected']) }}</td><td class="whitespace-nowrap text-right tabular-nums">{{ $short($m['scheduled']) }}</td>
                                    <td class="whitespace-nowrap text-right tabular-nums">{{ $short($m['weighted']) }}</td><td class="whitespace-nowrap text-right font-medium tabular-nums">{{ $short($m['total']) }}</td>
                                    <td class="whitespace-nowrap text-right tabular-nums">{{ $m['plan'] ? $short($m['plan']) : '—' }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-card>
        </div>
    @else
        @php($s = $data['summary'])
        <div class="grid grid-cols-1 gap-4">
            <div class="grid gap-4 sm:grid-cols-3">
                <x-stat :label="t('analytics.clients')" :value="(string) $s['clients']" />
                <x-stat :label="t('analytics.avgLtv')" :value="$short($s['avgLtv'])" />
                <x-stat :label="t('analytics.repeatRate')" :value="$pct($s['repeatRate'])" />
            </div>
            <x-card :title="t('analytics.clientsTitle')" :subtitle="t('analytics.clientsText')" :padding="false">
                <x-slot:actions>@include('analytics.export-menu', ['entity' => 'clients'])</x-slot:actions>
                <div class="flex flex-wrap items-center gap-2 p-4">
                    <a href="{{ $q(['health' => null, 'page' => null, 'sort' => request('sort')]) }}" @class(['rounded-full border px-3 py-1 text-xs', 'border-foreground bg-muted' => ! request('health')])>{{ t('analytics.allClients') }} · {{ $s['clients'] }}</a>
                    @foreach (['RISK', 'ATTENTION', 'HEALTHY', 'LOST'] as $l)
                        <a href="{{ $q(['health' => $l, 'page' => null, 'sort' => request('sort')]) }}" @class(['rounded-full', 'ring-2 ring-foreground/40' => request('health') === $l])>
                            @include('analytics.health-badge', ['level' => $l])<span class="ml-1 text-xs text-muted-foreground">{{ $s['byHealth'][$l] }}</span></a>
                    @endforeach
                    <form method="GET" class="ml-auto">
                        @foreach (['tab' => $tab, 'health' => request('health'), 'user' => $userId] as $k => $v)@if ($v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif @endforeach
                        <select name="sort" class="input h-8 w-auto text-xs" aria-label="{{ t('analytics.sort') }}" onchange="this.form.submit()">
                            @foreach (['ltv' => 'sortLtv', 'health' => 'sortHealth', 'lastPayment' => 'sortLastPayment'] as $k => $label)<option value="{{ $k }}" @selected(request('sort', 'ltv') === $k)>{{ t('analytics.'.$label) }}</option>@endforeach
                        </select>
                    </form>
                </div>
                @if (! $data['items'])
                    <x-empty :title="t('analytics.empty')" />
                @else
                    <div class="overflow-x-auto border-t">
                        <table class="table">
                            <thead><tr><th>{{ t('analytics.client') }}</th><th>{{ t('analytics.health.title') }}</th><th class="text-right">LTV</th><th class="text-right">{{ t('analytics.paidDeals') }}</th>
                                <th class="text-right">{{ t('analytics.avgCheck') }}</th><th>{{ t('analytics.lastContact') }}</th><th>{{ t('analytics.owner') }}</th></tr></thead>
                            <tbody>
                                @foreach ($data['items'] as $c)
                                    <tr>
                                        <td class="font-medium"><a href="/clients/{{ $c['id'] }}" class="hover:underline">{{ $c['name'] }}</a></td>
                                        <td><div class="grid gap-0.5">
                                            <span>@include('analytics.health-badge', ['level' => $c['health']['level'], 'score' => $c['health']['score']])</span>
                                            @if ($c['health']['reasons'])<span class="max-w-64 text-xs text-muted-foreground">{{ implode(' · ', \App\Services\Analytics\ClientInsights::reasonTexts($c['health']['reasons'])) }}</span>@endif
                                        </div></td>
                                        <td class="whitespace-nowrap text-right tabular-nums">{{ $short($c['ltv']) }}</td>
                                        <td class="text-right tabular-nums">{{ $c['paidDeals'] }}</td>
                                        <td class="whitespace-nowrap text-right tabular-nums">{{ $c['paidDeals'] ? $short($c['avgCheck']) : '—' }}</td>
                                        <td class="whitespace-nowrap text-muted-foreground">{{ $c['lastContactAt'] ? $c['lastContactAt']->locale(\App\Support\Lang::icu())->diffForHumans() : '—' }}</td>
                                        <td>{{ $c['owner'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @php($paginator = new \Illuminate\Pagination\LengthAwarePaginator([], $data['total'], $data['perPage'], $data['page'], ['path' => route('analytics'), 'query' => request()->except('page')]))
                    @if ($paginator->hasPages())<div class="border-t px-3 py-2">{{ $paginator->links('vendor.pagination.fluggi') }}</div>@endif
                @endif
            </x-card>
        </div>
    @endif
@endsection
