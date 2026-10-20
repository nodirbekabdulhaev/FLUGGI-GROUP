{{-- Плитка показателя; href — ссылка на раздел, danger — выделить красным --}}
@if (! empty($href))<a href="{{ $href }}" class="block">@endif
<div @class(['card card-body grid h-full content-start gap-1', 'hover:bg-muted/40' => ! empty($href)])>
    <p class="text-sm text-muted-foreground">{{ $label }}</p>
    <p @class(['text-2xl font-semibold tracking-tight tabular-nums', 'text-danger' => ! empty($danger)])>{{ $value }}</p>
    @if (! empty($hint))<p class="text-xs text-muted-foreground">{{ $hint }}</p>@endif
</div>
@if (! empty($href))</a>@endif
