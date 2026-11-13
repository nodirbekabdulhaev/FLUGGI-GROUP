{{-- Вкладки настроек (все модули), отфильтрованные по правам: @include('settings.partials.tabs') --}}
@php($settingsTabs = \App\Http\Controllers\People\SettingsController::tabs())
<nav class="tabs mb-6">
    @foreach ($settingsTabs as $tabItem)
        @php($active = \App\Support\Navigation::isActive($tabItem['href']))
        <a href="{{ $tabItem['href'] }}" @if ($active) aria-current="page" @endif @class(['tab', 'tab-active' => $active])>{{ t('settings.'.$tabItem['key']) }}</a>
    @endforeach
</nav>
