@props(['icon' => 'info', 'title' => null])
<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center gap-2 px-4 py-12 text-center']) }}>
    <x-icon :name="$icon" class="size-8 text-muted-foreground/60" />
    <p class="font-medium">{{ $title ?? t('common.empty') }}</p>
    @if ($slot->isNotEmpty())<div class="max-w-sm text-sm text-muted-foreground">{{ $slot }}</div>@endif
</div>
