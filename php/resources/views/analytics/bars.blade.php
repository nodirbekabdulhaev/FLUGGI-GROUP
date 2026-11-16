{{-- Горизонтальные полосы: rows — [label, value, display, sub] --}}
@php($max = max(1, ...array_map(fn ($r) => (float) $r['value'], $rows ?: [['value' => 0]])))
<ul class="grid gap-3">
    @foreach ($rows as $r)
        <li>
            <div class="flex items-baseline justify-between gap-3 text-sm"><span class="min-w-0 truncate">{{ $r['label'] }}</span><span class="shrink-0 font-medium tabular-nums">{{ $r['display'] }}</span></div>
            <div class="mt-1 h-2 rounded-full bg-muted"><div class="h-2 rounded-full {{ $color ?? 'bg-accent' }}" style="width: {{ max(0, (float) $r['value']) / $max * 100 }}%"></div></div>
            @if (! empty($r['sub']))<p class="mt-0.5 text-xs text-muted-foreground">{{ $r['sub'] }}</p>@endif
        </li>
    @endforeach
</ul>
