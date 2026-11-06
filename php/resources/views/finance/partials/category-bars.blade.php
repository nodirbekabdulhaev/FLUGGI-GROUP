{{-- Расходы по категориям: одна шкала, подписи значений рядом --}}
@if (empty($items))
    <p class="text-sm text-muted-foreground">{{ t('finance.noExpenses') }}</p>
@else
    @php($max = max(array_map(fn ($i) => (float) $i['amountUzs'], $items)) ?: 1)
    <ul class="grid gap-3" aria-label="{{ t('finance.byCategory') }}">
        @foreach ($items as $i)
            <li class="grid gap-1" title="{{ $i['name'] }}: {{ money($i['amountUzs']) }}">
                <div class="flex items-baseline justify-between gap-3 text-sm"><span>{{ $i['name'] }}</span><span class="tabular-nums text-muted-foreground">{{ money($i['amountUzs']) }}</span></div>
                <div class="h-2 rounded-full bg-muted"><div class="h-full rounded-full bg-accent" style="width: {{ max(1, round((float) $i['amountUzs'] / $max * 100, 1)) }}%"></div></div>
            </li>
        @endforeach
    </ul>
@endif
