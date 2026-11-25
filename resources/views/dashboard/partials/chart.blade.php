{{-- График (Chart.js, resources/js/charts.js). config — см. charts.js; label — подпись для экранных дикторов; без данных — пустое состояние.
     Числа всегда дублируются таблицей или списком рядом — график только дополняет их. --}}
@php($height ??= 'h-56')
@if (! empty($empty))
    <div class="{{ $height }} grid place-items-center rounded-xl bg-muted/30 text-sm text-muted-foreground">{{ t('home.charts.noData') }}</div>
@else
    <div x-data="chart(@js($config))" class="relative {{ $height }} w-full min-w-0">
        <canvas x-ref="canvas" role="img" aria-label="{{ $label }}"></canvas>
    </div>
@endif
