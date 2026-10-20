{{-- Вкладки настроек (все модули), отфильтрованные по правам: @include('settings.partials.tabs') --}}
@php($settingsTabs = \App\Http\Controllers\People\SettingsController::tabs())
<nav class="mb-6 flex gap-1 overflow-x-auto border-b">
    @foreach ($settingsTabs as $tabItem)
        @php($active = \App\Support\Navigation::isActive($tabItem['href']))
        <a href="{{ $tabItem['href'] }}" @if ($active) aria-current="page" @endif @class(['-mb-px whitespace-nowrap border-b-2 px-3 py-2.5 text-sm', 'border-foreground font-medium' => $active, 'border-transparent text-muted-foreground hover:text-foreground' => ! $active])>{{ t('settings.'.$tabItem['key']) }}</a>
    @endforeach
</nav>
