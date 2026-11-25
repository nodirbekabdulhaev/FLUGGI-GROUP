{{-- Итоги по строкам: $totals, $kinds = [статус => [ключ перевода, тон]], $base — адрес страницы для фильтра, $filter --}}
<div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
    @foreach ($kinds as $status => [$key, $tone])
        <a href="{{ $base }}?status={{ $status }}" @class(['rounded-lg ring-2 ring-primary' => $filter === $status])>
            <x-stat :label="t($key)" :value="$totals[$status] ?? 0" :tone="($totals[$status] ?? 0) > 0 ? $tone : null" />
        </a>
    @endforeach
</div>
@if ($filter)
    <p class="mb-3 text-sm"><a class="link" href="{{ $base }}">{{ t('import.showAll') }}</a></p>
@endif
