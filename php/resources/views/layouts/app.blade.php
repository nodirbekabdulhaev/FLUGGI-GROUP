<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    @include('partials.head')
</head>
<body class="min-h-dvh lg:pl-64" x-data="{ menu: false }">
@php($nav = \App\Support\Navigation::for(access()))
{{-- Меню: на компьютере слева, на телефоне — выезжающая панель (ТЗ §73) --}}
<aside class="fixed inset-y-0 left-0 z-30 hidden w-64 flex-col border-r bg-surface lg:flex">
    @include('partials.brand')
    <div class="flex-1 overflow-y-auto px-3 pb-6">@include('partials.nav', ['nav' => $nav])</div>
</aside>
<div x-show="menu" x-cloak class="fixed inset-0 z-50 lg:hidden" role="dialog" aria-modal="true">
    <div class="absolute inset-0 bg-black/40" @click="menu = false"></div>
    <div class="absolute inset-y-0 left-0 flex w-72 max-w-[85vw] flex-col bg-surface shadow-xl">
        <div class="flex items-center justify-between border-b pr-2">@include('partials.brand')
            <button class="btn btn-ghost btn-icon" @click="menu = false" aria-label="{{ t('common.close') }}"><x-icon name="x" /></button>
        </div>
        <div class="flex-1 overflow-y-auto p-3">@include('partials.nav', ['nav' => $nav])</div>
    </div>
</div>

<header class="sticky top-0 z-40 flex h-14 items-center gap-2 border-b bg-surface/90 px-3 backdrop-blur sm:h-16 sm:gap-3 sm:px-6">
    <button class="btn btn-ghost btn-icon lg:hidden" @click="menu = true" aria-label="{{ t('nav.openMenu') }}"><x-icon name="menu" class="size-5" /></button>
    <div class="min-w-0 flex-1">@yield('toolbar')</div>
    @includeIf('partials.header-extra')
    <form method="POST" action="{{ route('profile.locale') }}" class="hidden sm:block">
        @csrf
        <select name="locale" class="input h-8 w-auto py-0 text-xs" onchange="this.form.submit()" aria-label="{{ t('auth.language') }}">
            @foreach (\App\Support\Lang::LOCALES as $loc)
                <option value="{{ $loc }}" @selected(app()->getLocale() === $loc)>{{ t('common.languages.'.$loc) }}</option>
            @endforeach
        </select>
    </form>
    <div class="relative" x-data="{ open: false }" @click.outside="open = false">
        <button class="flex items-center gap-2 rounded-md px-2 py-1.5 hover:bg-muted" @click="open = !open">
            <span class="flex size-8 items-center justify-center rounded-full bg-accent-soft text-xs font-semibold text-accent">{{ mb_strtoupper(mb_substr(access()->user->full_name, 0, 1)) }}</span>
            <span class="hidden max-w-[10rem] truncate text-left text-sm md:block">
                <span class="block truncate font-medium">{{ access()->user->full_name }}</span>
                <span class="block truncate text-xs text-muted-foreground">{{ t('roles.'.access()->roleCode) }}</span>
            </span>
        </button>
        <div x-show="open" x-cloak class="absolute right-0 mt-1 w-52 rounded-md border bg-surface py-1 shadow-lg">
            <a href="{{ route('profile') }}" class="flex items-center gap-2 px-3 py-2 text-sm hover:bg-muted"><x-icon name="user" />{{ t('nav.profile') }}</a>
            <form method="POST" action="{{ route('logout') }}">@csrf
                <button class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-muted"><x-icon name="logout" />{{ t('nav.logout') }}</button>
            </form>
        </div>
    </div>
</header>

<main class="mx-auto w-full max-w-7xl px-4 pb-28 pt-6 sm:px-6 lg:pt-8">
    @yield('content')
</main>
@stack('dock')
@include('partials.toasts')
@stack('scripts')
</body>
</html>
