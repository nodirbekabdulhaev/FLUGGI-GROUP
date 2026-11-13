@props(['color' => 'gray'])
<span {{ $attributes->merge(['class' => 'badge badge-'.$color]) }}>{{ $slot }}</span>
