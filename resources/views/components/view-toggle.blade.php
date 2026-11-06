@props(['view', 'key'])
{{--
  Переключатель вида «Kanban | Список» (?view=kanban|list). Выбор запоминается в браузере (localStorage),
  при заходе без ?view= открывается сохранённый вид. По умолчанию — Kanban.
--}}
@php
    $storageKey = 'fluggi.view.'.$key;
    $href = fn (string $v) => request()->fullUrlWithQuery(['view' => $v, 'page' => null, 'create' => null]);
@endphp
<div {{ $attributes->merge(['class' => 'segmented']) }} role="group" aria-label="{{ t('ui.view.label') }}">
    @foreach (['kanban' => ['kanban', t('ui.view.kanban')], 'list' => ['rows', t('ui.view.list')]] as $v => [$icon, $label])
        <a href="{{ $href($v) }}" data-view="{{ $v }}" @if ($view === $v) aria-current="page" @endif
           onclick="try { localStorage.setItem(@js($storageKey), @js($v)) } catch (e) {}"><x-icon :name="$icon" class="size-4" /> {{ $label }}</a>
    @endforeach
</div>
<script>
    (() => {
        try {
            const key = @js($storageKey), p = new URLSearchParams(location.search);
            if (p.has('view')) localStorage.setItem(key, p.get('view') === 'list' ? 'list' : 'kanban');
            else if (localStorage.getItem(key) === 'list' && !p.has('create')) { p.set('view', 'list'); location.replace(location.pathname + '?' + p.toString()); }
        } catch (e) {}
    })();
</script>
