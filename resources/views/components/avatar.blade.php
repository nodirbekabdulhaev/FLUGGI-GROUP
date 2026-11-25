@props(['name' => null])
{{-- Кружок с инициалами сотрудника (пастельный градиент): <x-avatar :name="$user->full_name" /> --}}
@php
    $parts = preg_split('/\s+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $initials = mb_strtoupper(implode('', array_map(fn ($p) => mb_substr($p, 0, 1), array_slice($parts, 0, 2)))) ?: '·';
@endphp
<span {{ $attributes->merge(['class' => 'flex size-6 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-sky-soft to-periwinkle-soft text-[10px] font-semibold text-periwinkle-strong ring-2 ring-white']) }} aria-hidden="true">{{ $initials }}</span>
