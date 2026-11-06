{{-- Панель фильтров списка: слот — поля; кнопки «Фильтр» и «Сбросить». --}}
<form method="GET" class="flex flex-col gap-3 border-b p-4 sm:flex-row sm:flex-wrap sm:items-end">
    {{ $slot }}
    <div class="flex gap-2">
        <button class="btn btn-outline">{{ t('common.filter') }}</button>
        @if ($filtered ?? false)<a href="{{ url()->current() }}" class="btn btn-ghost">{{ t('common.reset') }}</a>@endif
    </div>
</form>
