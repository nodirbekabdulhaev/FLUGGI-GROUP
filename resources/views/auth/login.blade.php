@extends('layouts.guest')
@section('title', t('auth.title', ['app' => brand()->name()]))
@section('content')
    <div class="card relative overflow-hidden p-6 sm:p-8">
        {{-- Мягкие пастельные пятна за формой --}}
        <div class="pointer-events-none absolute -right-16 -top-20 size-48 rounded-full bg-periwinkle-soft blur-2xl" aria-hidden="true"></div>
        <div class="pointer-events-none absolute -bottom-24 -left-16 size-48 rounded-full bg-sky-soft blur-2xl" aria-hidden="true"></div>
        <div class="relative">
            <div class="mb-6 flex flex-col items-center text-center">
                <img src="{{ brand()->logo() }}" alt="" class="mb-4 size-14 rounded-2xl shadow-soft ring-4 ring-white">
                <h1 class="text-xl font-semibold tracking-tight">{{ t('auth.title', ['app' => brand()->name()]) }}</h1>
                <p class="mt-1 text-sm text-muted-foreground">{{ t('auth.subtitle') }}</p>
            </div>
            <form method="POST" action="{{ route('login') }}" class="space-y-4">
                @csrf
                <x-field name="email" type="email" :label="t('auth.email')" autocomplete="username" autofocus required />
                <x-field name="password" type="password" :label="t('auth.password')" autocomplete="current-password" required />
                <button type="submit" class="btn btn-primary h-11 w-full">{{ t('auth.submit') }}</button>
                <p class="text-center text-xs text-muted-foreground">{{ t('auth.forgot') }}</p>
            </form>
        </div>
    </div>
    <nav class="mt-5 flex justify-center" aria-label="{{ t('auth.language') }}">
        <div class="segmented">
            @foreach (\App\Support\Lang::LOCALES as $loc)
                <a href="?lang={{ $loc }}" @if (app()->getLocale() === $loc) aria-current="page" @endif>{{ t('common.languages.'.$loc) }}</a>
            @endforeach
        </div>
    </nav>
@endsection
