@props(['title', 'subtitle' => null, 'back' => null])
{{-- Заголовок страницы; кнопки действий — в слоте. --}}
<div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
    <div class="min-w-0">
        @if ($back)
            <a href="{{ $back }}" class="mb-1 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
                <x-icon name="arrow-left" class="size-3.5" /> {{ t('common.back') }}
            </a>
        @endif
        <h1 class="text-xl font-semibold tracking-tight sm:text-2xl">{{ $title }}</h1>
        @if ($subtitle)
            <p class="mt-1 text-sm text-muted-foreground">{{ $subtitle }}</p>
        @endif
    </div>
    @if ($slot->isNotEmpty())
        <div class="flex flex-wrap items-center gap-2">{{ $slot }}</div>
    @endif
</div>
