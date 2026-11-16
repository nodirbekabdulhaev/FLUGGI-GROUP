{{-- Графики главной ($charts — App\Services\Dashboard\ChartsService::get). Рядом с каждым графиком — те же числа текстом. --}}
@php
    use App\Support\Format;
    $palette = ['#60a5fa', '#818cf8', '#f9a8d4', '#6ee7b7', '#fcd34d', '#a5b4fc', '#fda4af', '#7dd3fc', '#c4b5fd', '#5eead4', '#fdba74', '#cbd5e1'];
    $pctText = fn ($v) => str_replace('.', ',', (string) round((float) $v, 1)).'%';
    $s = $charts['sales'];
    $src = $charts['sources'];
    $st = $charts['stages'];
    $ex = $charts['expenses'];
    $smm = $charts['smm'];
@endphp
<div class="grid gap-4 lg:grid-cols-[repeat(3,minmax(0,1fr))]">
    @if ($s)
        <x-card :title="t('home.charts.salesTitle')" :subtitle="t('home.charts.salesSub')" class="min-w-0 lg:col-span-2" data-testid="chart-sales">
            <div class="mb-3 grid grid-cols-2 gap-3 sm:max-w-md">
                <div><p class="flex items-center gap-1.5 text-xs text-muted-foreground"><span class="size-2 rounded-full" style="background:#60a5fa"></span>{{ t('home.charts.revenue') }}</p>
                    <p class="text-lg font-semibold tabular-nums">{{ Format::moneyShort($s['revenueTotal']) }}</p></div>
                <div><p class="flex items-center gap-1.5 text-xs text-muted-foreground"><span class="size-2 rounded-full" style="background:#f472b6"></span>{{ t('home.charts.collected') }}</p>
                    <p class="text-lg font-semibold tabular-nums">{{ Format::moneyShort($s['collectedTotal']) }}</p></div>
            </div>
            @include('dashboard.partials.chart', ['empty' => $s['empty'], 'label' => t('home.charts.salesTitle'), 'height' => 'h-60', 'config' => [
                'type' => 'line', 'labels' => $s['labels'], 'format' => 'money', 'unit' => 'UZS', 'legend' => false,
                'series' => [['label' => t('home.charts.revenue'), 'data' => $s['revenue'], 'color' => '#60a5fa'], ['label' => t('home.charts.collected'), 'data' => $s['collected'], 'color' => '#f472b6', 'fill' => false]],
            ]])
            @unless ($s['empty'])
                <details class="mt-3">
                    <summary class="cursor-pointer text-xs text-muted-foreground">{{ t('home.charts.showTable') }}</summary>
                    <div class="mt-2 max-h-72 overflow-auto"><table class="table">
                        <thead><tr><th></th><th class="text-right">{{ t('home.charts.revenue') }}</th><th class="text-right">{{ t('home.charts.collected') }}</th></tr></thead>
                        <tbody>@foreach ($s['labels'] as $i => $l)<tr><td>{{ $l }}</td><td class="whitespace-nowrap text-right tabular-nums">{{ money($s['revenue'][$i]) }}</td><td class="whitespace-nowrap text-right tabular-nums">{{ money($s['collected'][$i]) }}</td></tr>@endforeach</tbody>
                    </table></div>
                </details>
            @endunless
        </x-card>
    @endif

    @if ($src)
        <x-card :title="t('home.charts.sourcesTitle')" :subtitle="t('home.charts.sourcesSub')" class="min-w-0" data-testid="chart-sources">
            @if (! $src['total'])
                @include('dashboard.partials.chart', ['empty' => true, 'height' => 'h-40'])
            @else
                @php($top = array_slice($src['rows'], 0, 8))
                <div class="relative">
                    @include('dashboard.partials.chart', ['label' => t('home.charts.sourcesTitle'), 'height' => 'h-44', 'config' => [
                        'type' => 'doughnut', 'labels' => array_column($top, 'name'), 'legend' => false,
                        'series' => [['label' => t('home.charts.leads'), 'data' => array_column($top, 'leads')]],
                    ]])
                    <div class="pointer-events-none absolute inset-0 grid place-content-center text-center"><span class="text-2xl font-semibold tabular-nums">{{ $src['total'] }}</span><span class="text-xs text-muted-foreground">{{ t('home.charts.leads') }}</span></div>
                </div>
                <ul class="mt-3 grid gap-1.5 text-sm">
                    @foreach ($top as $i => $r)
                        <li class="flex items-center gap-2"><span class="size-2.5 shrink-0 rounded-full" style="background: {{ $palette[$i % count($palette)] }}"></span>
                            <span class="min-w-0 flex-1 truncate">{{ $r['name'] }}</span><span class="tabular-nums font-medium">{{ $r['leads'] }}</span>
                            <span class="w-12 text-right text-xs tabular-nums text-muted-foreground">{{ $pctText($r['leads'] * 100 / $src['total']) }}</span></li>
                    @endforeach
                </ul>
            @endif
        </x-card>
    @endif

    @if ($st)
        <x-card :title="t('home.charts.stagesTitle')" :subtitle="t('home.charts.stagesSub', ['count' => $st['total'], 'amount' => Format::moneyShort($st['amountUzs'])])" class="min-w-0 lg:col-span-3" data-testid="chart-stages">
            <x-slot:actions><a href="/sales/deals" class="btn btn-ghost btn-sm">{{ t('home.charts.deals') }} <x-icon name="chevron-right" class="size-4" /></a></x-slot:actions>
            @if (! $st['total'])
                @include('dashboard.partials.chart', ['empty' => true, 'height' => 'h-32'])
            @else
                @php($maxCount = max(1, ...array_column($st['rows'], 'count')))
                <ul class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
                    @foreach ($st['rows'] as $i => $r)
                        <li class="flex min-w-0 flex-col justify-between gap-2 rounded-2xl bg-muted/40 p-3">
                            <div class="flex items-start justify-between gap-1">
                                <span class="line-clamp-2 min-w-0 rounded-lg px-2 py-0.5 text-xs font-medium" style="background: {{ $palette[$i % count($palette)] }}33">{{ $r['name'] }}</span>
                                <span class="shrink-0 text-[11px] tabular-nums text-muted-foreground">{{ $pctText($r['pct']) }}</span>
                            </div>
                            <div class="flex h-16 items-end"><div class="w-full rounded-lg" style="height: {{ max(6, $r['count'] / $maxCount * 100) }}%; background: repeating-linear-gradient(135deg, {{ $palette[$i % count($palette)] }}, {{ $palette[$i % count($palette)] }} 4px, {{ $palette[$i % count($palette)] }}aa 4px, {{ $palette[$i % count($palette)] }}aa 8px)"></div></div>
                            <div><p class="text-2xl font-semibold leading-none tabular-nums">{{ $r['count'] }}</p>
                                <p class="mt-1 truncate text-xs text-muted-foreground tabular-nums">{{ Format::moneyShort($r['amountUzs']) }}</p></div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-card>
    @endif

    @if ($ex)
        <x-card :title="t('home.charts.expensesTitle')" :subtitle="t('home.charts.expensesSub')" class="min-w-0" data-testid="chart-expenses">
            @if (! $ex['rows'])
                @include('dashboard.partials.chart', ['empty' => true, 'height' => 'h-40'])
            @else
                <div class="relative">
                    @include('dashboard.partials.chart', ['label' => t('home.charts.expensesTitle'), 'height' => 'h-44', 'config' => [
                        'type' => 'doughnut', 'labels' => array_column($ex['rows'], 'name'), 'legend' => false, 'format' => 'money', 'unit' => 'UZS',
                        'series' => [['label' => t('home.charts.amount'), 'data' => array_column($ex['rows'], 'amountUzs')]],
                    ]])
                    <div class="pointer-events-none absolute inset-0 grid place-content-center text-center"><span class="px-8 text-sm font-semibold leading-tight tabular-nums">{{ Format::moneyShort($ex['total']) }}</span><span class="text-xs text-muted-foreground">{{ t('home.charts.total') }}</span></div>
                </div>
                <ul class="mt-3 grid gap-1.5 text-sm">
                    @foreach ($ex['rows'] as $i => $r)
                        <li class="flex items-center gap-2"><span class="size-2.5 shrink-0 rounded-full" style="background: {{ $palette[$i % count($palette)] }}"></span>
                            <span class="min-w-0 flex-1 truncate">{{ $r['name'] }}</span><span class="whitespace-nowrap font-medium tabular-nums">{{ Format::moneyShort($r['amountUzs']) }}</span></li>
                    @endforeach
                </ul>
            @endif
        </x-card>
    @endif

    @if ($smm)
        <x-card :title="t('home.charts.smmTitle')" :subtitle="t('home.charts.smmSub')" :class="$ex ? 'min-w-0' : 'min-w-0 lg:col-span-2'" data-testid="chart-smm">
            <x-slot:actions><span class="text-lg font-semibold tabular-nums">{{ $smm['total'] }}</span></x-slot:actions>
            @include('dashboard.partials.chart', ['empty' => ! $smm['total'], 'label' => t('home.charts.smmTitle'), 'height' => 'h-48', 'config' => [
                'type' => 'bar', 'labels' => $smm['labels'], 'legend' => false,
                'series' => [['label' => t('home.charts.videos'), 'data' => $smm['counts'], 'color' => '#f9a8d4']],
            ]])
            @if ($smm['total'])
                <details class="mt-3">
                    <summary class="cursor-pointer text-xs text-muted-foreground">{{ t('home.charts.showTable') }}</summary>
                    <ul class="mt-2 grid grid-cols-3 gap-x-4 gap-y-1 text-sm sm:grid-cols-4">@foreach ($smm['labels'] as $i => $l)<li class="flex justify-between gap-2"><span class="text-muted-foreground">{{ $l }}</span><span class="tabular-nums">{{ $smm['counts'][$i] }}</span></li>@endforeach</ul>
                </details>
            @endif
        </x-card>
    @endif
</div>
