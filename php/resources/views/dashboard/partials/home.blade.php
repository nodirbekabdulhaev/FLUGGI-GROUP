{{-- Под плитками: «Мой день» + счета и план, затем графики --}}
@php($side = $wallets || ($charts['plan'] ?? null) || (($d['myKpi']['pct'] ?? null) !== null))
<div @class(['grid grid-cols-1 gap-4', 'lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]' => $side])>
    <div @class(['min-w-0', ])>@include('dashboard.partials.agenda')</div>
    @if ($side)
        <div class="grid min-w-0 grid-cols-1 content-start gap-4">
            @if ($wallets)@include('dashboard.partials.wallets', ['w' => $wallets])@endif
            @include('dashboard.partials.plan')
        </div>
    @endif
</div>
@if (array_filter($charts))
    @include('dashboard.partials.charts')
@endif
