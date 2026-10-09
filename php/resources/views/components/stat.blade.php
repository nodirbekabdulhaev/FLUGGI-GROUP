@props(['label', 'value', 'hint' => null, 'tone' => null])
<div {{ $attributes->merge(['class' => 'card card-body']) }}>
    <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">{{ $label }}</p>
    <p @class(['mt-1 text-2xl font-semibold tabular-nums', 'text-success' => $tone === 'good', 'text-danger' => $tone === 'bad', 'text-warning' => $tone === 'warn'])>{{ $value }}</p>
    @if ($hint)<p class="mt-0.5 text-xs text-muted-foreground">{{ $hint }}</p>@endif
</div>
