@props(['label', 'value', 'hint' => null, 'tone' => null, 'href' => null])
@php
 $tones = ['good' => 'text-emerald-600', 'bad' => 'text-rose-600', 'warn' => 'text-amber-600'];
 $cls = 'card block p-4'.($href ? ' transition hover:border-brand-500' : '');
@endphp
@if($href)<a href="{{ $href }}" class="{{ $cls }}">@else<div class="{{ $cls }}">@endif
    <div class="text-xs font-medium text-slate-500">{{ $label }}</div>
    <div class="mt-1 text-2xl font-semibold tracking-tight {{ $tones[$tone] ?? '' }}">{{ $value }}</div>
    @if($hint)<div class="mt-0.5 text-xs text-slate-500">{{ $hint }}</div>@endif
@if($href)</a>@else</div>@endif
