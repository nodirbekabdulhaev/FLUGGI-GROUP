{{-- План месяца (получено / план «Выручка» в области видимости) или «Мой KPI», если плана нет --}}
@php($plan = $charts['plan'] ?? null)
@php($kpi = $d['myKpi'] ?? null)
@if ($plan || ($kpi && $kpi['pct'] !== null))
    <x-card :title="$plan ? t('home.charts.planTitle') : t('home.charts.kpiTitle')" :subtitle="$plan ? t('home.charts.planSub') : t('home.charts.kpiSub')" data-testid="plan-ring">
        <div class="flex flex-wrap items-center gap-4 sm:flex-nowrap">
            @include('dashboard.partials.ring', ['id' => 'plan', 'pct' => $plan ? $plan['pct'] : $kpi['pct'], 'label' => $plan ? t('home.charts.planTitle') : t('home.charts.kpiTitle'),
                'center' => $plan ? t('analytics.months.'.(int) substr($plan['month'], 5, 2)) : $kpi['period']])
            @if ($plan)
                <dl class="grid min-w-0 flex-1 gap-2 text-sm">
                    <div><dt class="text-xs text-muted-foreground">{{ t('home.charts.fact') }}</dt><dd class="font-semibold tabular-nums">{{ \App\Support\Format::moneyShort($plan['fact']) }}</dd></div>
                    <div><dt class="text-xs text-muted-foreground">{{ t('home.charts.plan') }}</dt><dd class="font-semibold tabular-nums">{{ \App\Support\Format::moneyShort($plan['plan']) }}</dd></div>
                    <div><dt class="text-xs text-muted-foreground">{{ t('home.charts.forecast') }}</dt><dd class="tabular-nums">{{ \App\Support\Format::moneyShort($plan['forecast']) }}</dd></div>
                </dl>
            @else
                <ul class="grid min-w-0 flex-1 gap-1.5 text-sm">
                    @foreach (array_slice($kpi['targets'], 0, 4) as $tg)
                        <li class="flex justify-between gap-2"><span class="truncate text-muted-foreground">{{ t('kpi.metric.'.$tg['metric']) }}</span><span class="tabular-nums">{{ $tg['pct'] !== null ? $tg['pct'].'%' : '—' }}</span></li>
                    @endforeach
                    <li><a href="/kpi" class="link text-xs">{{ t('dash.openKpi') }}</a></li>
                </ul>
            @endif
        </div>
    </x-card>
@endif
