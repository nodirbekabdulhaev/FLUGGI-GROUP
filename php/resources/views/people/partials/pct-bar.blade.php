{{-- Полоса выполнения цели: 100% — полная, сверх плана — без переполнения --}}
@if ($pct === null)
    <span class="text-xs text-muted-foreground">—</span>
@else
    @php($v = (float) $pct)
    <div class="grid min-w-24 gap-1 {{ $class ?? '' }}">
        <div class="h-1.5 overflow-hidden rounded-full bg-muted" role="progressbar" aria-valuenow="{{ $v }}">
            <div @class(['h-full rounded-full', 'bg-success' => $v >= 100, 'bg-accent' => $v >= 70 && $v < 100, 'bg-warning' => $v < 70]) style="width: {{ min($v, 100) }}%"></div>
        </div>
        <span class="text-xs tabular-nums">{{ $pct }}%</span>
    </div>
@endif
