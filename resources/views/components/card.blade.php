@props(['title' => null, 'subtitle' => null, 'padding' => true])
<section {{ $attributes->merge(['class' => 'card']) }}>
    @if ($title || isset($actions))
        <header class="flex items-start justify-between gap-3 border-b px-4 py-3 sm:px-5">
            <div class="min-w-0">
                <h2 class="font-semibold">{{ $title }}</h2>
                @if ($subtitle)<p class="text-xs text-muted-foreground">{{ $subtitle }}</p>@endif
            </div>
            @isset($actions)<div class="flex shrink-0 items-center gap-2">{{ $actions }}</div>@endisset
        </header>
    @endif
    <div @class(['card-body' => $padding])>{{ $slot }}</div>
</section>
