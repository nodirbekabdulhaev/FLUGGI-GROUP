@props(['items' => [], 'active' => null])
{{-- Вкладки-ссылки: :items="[url => подпись]" --}}
<nav class="mb-4 flex gap-1 overflow-x-auto border-b">
    @foreach ($items as $href => $label)
        <a href="{{ $href }}" @class([
            'whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium',
            'border-foreground text-foreground' => $active === $href || ($active === null && url()->current() === url($href)),
            'border-transparent text-muted-foreground hover:text-foreground' => ! ($active === $href || ($active === null && url()->current() === url($href))),
        ])>{{ $label }}</a>
    @endforeach
</nav>
