{{-- Кольцо выполнения: pct — процент (строка или число), center — подпись под числом --}}
@php
    $p = max(0, (float) $pct);
    $shown = min(100, $p);
    $r = 42;
    $c = 2 * M_PI * $r;
@endphp
<div class="relative mx-auto aspect-square w-36 shrink-0">
    <svg viewBox="0 0 100 100" class="size-full -rotate-90" role="img" aria-label="{{ $label }}: {{ str_replace('.', ',', (string) round($p, 1)) }}%">
        <defs><linearGradient id="ring-{{ $id }}" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="#60a5fa"/><stop offset="100%" stop-color="#f9a8d4"/></linearGradient></defs>
        <circle cx="50" cy="50" r="{{ $r }}" fill="none" stroke="currentColor" stroke-width="10" class="text-muted"/>
        <circle cx="50" cy="50" r="{{ $r }}" fill="none" stroke="url(#ring-{{ $id }})" stroke-width="10" stroke-linecap="round"
            stroke-dasharray="{{ round($c * $shown / 100, 2) }} {{ round($c, 2) }}"/>
    </svg>
    <div class="absolute inset-0 grid place-content-center text-center">
        <span class="text-2xl font-semibold tabular-nums">{{ str_replace('.', ',', (string) round($p, 1)) }}%</span>
        @if (! empty($center))<span class="text-xs text-muted-foreground">{{ $center }}</span>@endif
    </div>
</div>
