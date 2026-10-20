{{-- «Мой KPI за месяц»: выполнение целей, KPI-бонус и ожидаемая выплата --}}
<x-card :title="t('dash.myKpiTitle', ['period' => $k['period']])">
    <div class="grid gap-6 lg:grid-cols-[1fr,20rem]">
        <div class="grid content-start gap-4">
            <div class="flex items-end gap-4">
                <p class="text-4xl font-semibold tabular-nums" data-testid="my-kpi-pct">{{ $k['pct'] ? $k['pct'].'%' : '—' }}</p>
                <div class="flex-1 pb-2">@include('people.partials.pct-bar', ['pct' => $k['pct']])</div>
            </div>
            @if (! empty($k['targets']))
                <ul class="grid gap-2 text-sm">
                    @foreach ($k['targets'] as $tg)
                        @php($fmt = fn ($v) => $tg['metric'] === 'REVENUE' ? \App\Support\Format::moneyShort($v, $tg['currency']) : \App\Support\Format::number($v))
                        <li class="grid grid-cols-[7rem,1fr] items-center gap-3 sm:grid-cols-[8rem,1fr,7rem]">
                            <span class="text-muted-foreground">{{ t('kpi.metric.'.$tg['metric']) }}</span>
                            <span class="tabular-nums">{{ $fmt($tg['fact']) }} / {{ $fmt($tg['target']) }}</span>
                            <span class="col-span-2 sm:col-span-1">@include('people.partials.pct-bar', ['pct' => $tg['pct']])</span>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="text-sm text-muted-foreground">{{ t('dash.noKpiTargets') }}</p>
            @endif
        </div>
        <dl class="grid content-start gap-2 rounded-lg bg-muted/40 p-4 text-sm">
            <div class="flex justify-between gap-3"><dt class="text-muted-foreground">{{ t('dash.kpiBonus') }}</dt><dd class="font-semibold tabular-nums" data-testid="my-kpi-bonus">{{ money($k['bonusUzs']) }}</dd></div>
            <p class="-mt-1 text-xs text-muted-foreground">{{ $k['bonusTarget'] ? t('dash.kpiBonusHint', ['amount' => money($k['bonusTarget'])]) : t('dash.kpiBonusNotSet') }}</p>
            <div class="flex justify-between gap-3"><dt class="text-muted-foreground">{{ t('dash.commissionMonth') }}</dt><dd class="tabular-nums">{{ money($k['commissionUzs']) }}</dd></div>
            @if ((float) $k['pieceRateUzs'])<div class="flex justify-between gap-3"><dt class="text-muted-foreground">{{ t('dash.pieceRate') }}</dt><dd class="tabular-nums">{{ money($k['pieceRateUzs']) }}</dd></div>@endif
            @if ($k['baseSalary'])<div class="flex justify-between gap-3"><dt class="text-muted-foreground">{{ t('dash.baseSalary') }}</dt><dd class="tabular-nums">{{ money($k['baseSalary']) }}</dd></div>@endif
            <div class="mt-1 flex justify-between gap-3 border-t pt-2"><dt class="font-medium">{{ t('dash.expectedPay') }}</dt><dd class="font-semibold tabular-nums">{{ money($k['expectedUzs']) }}</dd></div>
        </dl>
    </div>
</x-card>
