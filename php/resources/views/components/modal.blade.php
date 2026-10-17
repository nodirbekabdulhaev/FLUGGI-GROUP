@props(['name', 'title', 'size' => 'max-w-lg', 'open' => false])
{{--
  Модальное окно на Alpine. Открыть: <button x-data @click="$dispatch('open-modal', 'lead-create')">…</button>
  Если в форме внутри есть ошибки валидации — передайте :open="$errors->any()".
--}}
<div x-data="{ show: @js((bool) $open) }" x-on:open-modal.window="if ($event.detail === '{{ $name }}') show = true"
     x-on:close-modal.window="if ($event.detail === '{{ $name }}') show = false" x-on:keydown.escape.window="show = false"
     x-show="show" x-cloak class="fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:p-4" role="dialog" aria-modal="true">
    <div class="absolute inset-0 bg-black/40" x-on:click="show = false"></div>
    <div class="relative max-h-[92dvh] w-full {{ $size }} overflow-y-auto rounded-t-xl bg-surface shadow-xl sm:rounded-xl">
        <header class="sticky top-0 z-10 flex items-center justify-between border-b bg-surface px-4 py-3 sm:px-5">
            <h2 class="font-semibold">{{ $title }}</h2>
            <button type="button" class="btn btn-ghost btn-icon" x-on:click="show = false" aria-label="{{ t('common.close') }}"><x-icon name="x" /></button>
        </header>
        <div class="p-4 sm:p-5">{{ $slot }}</div>
    </div>
</div>
