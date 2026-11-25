@props(['items' => [], 'active' => null])
{{-- Вкладки-ссылки («таблетки»): :items="[url => подпись]" --}}
<nav {{ $attributes->merge(['class' => 'tabs']) }}>
    @foreach ($items as $href => $label)
        @php($on = $active === $href || ($active === null && url()->current() === url($href)))
        <a href="{{ $href }}" @class(['tab', 'tab-active' => $on]) @if ($on) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
