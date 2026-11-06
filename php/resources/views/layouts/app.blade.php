<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    @include('partials.head')
</head>
<body class="min-h-dvh lg:pl-64" x-data="{ menu: false }">
@php($nav = \App\Support\Navigation::for(access()))
{{-- Меню: на компьютере слева, на телефоне — выезжающая панель (ТЗ §73) --}}
<aside class="fixed inset-y-3 left-3 z-30 hidden w-60 flex-col overflow-hidden rounded-3xl border border-white/80 bg-white/60 shadow-glass backdrop-blur-xl lg:flex">
    @include('partials.brand')
    <div class="flex-1 overflow-y-auto px-3 pb-6 [scrollbar-width:thin]">@include('partials.nav', ['nav' => $nav])</div>
</aside>
<div x-show="menu" x-cloak class="fixed inset-0 z-50 lg:hidden" role="dialog" aria-modal="true">
    <div class="absolute inset-0 bg-[#1e2a44]/30" @click="menu = false"></div>
    <div class="absolute inset-y-0 left-0 flex w-72 max-w-[85vw] flex-col rounded-r-3xl border-r border-white bg-[#f6f8fe] shadow-lift">
        <div class="flex items-center justify-between pr-2">@include('partials.brand')
            <button class="btn btn-ghost btn-icon" @click="menu = false" aria-label="{{ t('common.close') }}"><x-icon name="x" /></button>
        </div>
        <div class="flex-1 overflow-y-auto px-3 pb-6">@include('partials.nav', ['nav' => $nav])</div>
    </div>
</div>

{{-- Размытие фона шапки — отдельным слоем: у самой шапки нет backdrop-filter, поэтому окна (fixed) внутри неё открываются на весь экран --}}
<header class="sticky top-0 z-40 flex h-14 items-center gap-2 border-b border-white/70 px-3 sm:h-16 sm:gap-3 sm:px-6 lg:top-3 lg:mx-4 lg:mt-3 lg:rounded-2xl lg:border lg:shadow-soft">
    <div class="pointer-events-none absolute inset-0 -z-10 bg-white/60 backdrop-blur-xl lg:rounded-2xl" aria-hidden="true"></div>
    <button class="btn btn-ghost btn-icon lg:hidden" @click="menu = true" aria-label="{{ t('nav.openMenu') }}"><x-icon name="menu" class="size-5" /></button>
    <div class="min-w-0 flex-1">@yield('toolbar')</div>
    @includeIf('partials.header-extra')
    <form method="POST" action="{{ route('profile.locale') }}" class="hidden sm:block">
        @csrf
        <select name="locale" class="input h-8 w-auto rounded-full py-0 text-xs" onchange="this.form.submit()" aria-label="{{ t('auth.language') }}">
            @foreach (\App\Support\Lang::LOCALES as $loc)
                <option value="{{ $loc }}" @selected(app()->getLocale() === $loc)>{{ t('common.languages.'.$loc) }}</option>
            @endforeach
        </select>
    </form>
    <div class="relative" x-data="{ open: false }" @click.outside="open = false">
        <button class="flex items-center gap-2 rounded-full py-1 pl-1 pr-2 hover:bg-white/70" @click="open = !open">
            <span class="flex size-8 items-center justify-center rounded-full bg-gradient-to-br from-sky-soft via-periwinkle-soft to-blush-soft text-xs font-semibold text-periwinkle-strong ring-2 ring-white">{{ mb_strtoupper(mb_substr(access()->user->full_name, 0, 1)) }}</span>
            <span class="hidden max-w-[10rem] truncate text-left text-sm md:block">
                <span class="block truncate font-medium">{{ access()->user->full_name }}</span>
                <span class="block truncate text-xs text-muted-foreground">{{ t('roles.'.access()->roleCode) }}</span>
            </span>
        </button>
        <div x-show="open" x-cloak class="popover absolute right-0 mt-2 w-56 overflow-hidden py-1">
            <a href="{{ route('profile') }}" class="flex items-center gap-2 px-3 py-2 text-sm hover:bg-primary-soft/60"><x-icon name="user" />{{ t('nav.profile') }}</a>
            {{-- Язык интерфейса — в меню, чтобы переключатель был и на телефоне --}}
            <div class="border-y border-border/70 px-3 py-2">
                <p class="mb-1 flex items-center gap-2 text-xs text-muted-foreground"><x-icon name="globe" class="size-3.5" />{{ t('auth.language') }}</p>
                <div class="flex flex-wrap gap-1">
                    @foreach (\App\Support\Lang::LOCALES as $loc)
                        <form method="POST" action="{{ route('profile.locale') }}">@csrf
                            <input type="hidden" name="locale" value="{{ $loc }}">
                            <button @class(['btn btn-sm', 'btn-primary' => app()->getLocale() === $loc, 'btn-outline' => app()->getLocale() !== $loc])>{{ t('common.languages.'.$loc) }}</button>
                        </form>
                    @endforeach
                </div>
            </div>
            <form method="POST" action="{{ route('logout') }}">@csrf
                <button class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-primary-soft/60"><x-icon name="logout" />{{ t('nav.logout') }}</button>
            </form>
        </div>
    </div>
</header>

<main class="mx-auto w-full max-w-7xl px-4 pb-28 pt-6 sm:px-6 lg:pt-8">
    @yield('content')
</main>
@includeIf('partials.dock')
@stack('dock')
@include('partials.toasts')
@include('partials.notify-center')
@stack('scripts')
</body>
</html>
