{{-- Таблица с горизонтальной прокруткой на телефоне: <x-table><thead>…</thead><tbody>…</tbody></x-table> --}}
<div {{ $attributes->merge(['class' => 'card overflow-hidden']) }}>
    <div class="overflow-x-auto"><table class="table">{{ $slot }}</table></div>
    @isset($footer)<div class="border-t px-3 py-2">{{ $footer }}</div>@endisset
</div>
