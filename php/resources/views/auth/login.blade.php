@extends('layouts.guest')
@section('title', t('auth.title'))
@section('content')
    <div class="mb-6 flex flex-col items-center text-center">
        <img src="/icon.svg" alt="" class="mb-3 size-12">
        <h1 class="text-xl font-semibold">{{ t('auth.title') }}</h1>
        <p class="mt-1 text-sm text-muted-foreground">{{ t('auth.subtitle') }}</p>
    </div>
    <form method="POST" action="{{ route('login') }}" class="card card-body space-y-4">
        @csrf
        <x-field name="email" type="email" :label="t('auth.email')" autocomplete="username" autofocus required />
        <x-field name="password" type="password" :label="t('auth.password')" autocomplete="current-password" required />
        <button type="submit" class="btn btn-primary w-full">{{ t('auth.submit') }}</button>
        <p class="text-center text-xs text-muted-foreground">{{ t('auth.forgot') }}</p>
    </form>
    <div class="mt-4 flex justify-center gap-3 text-sm">
        @foreach (\App\Support\Lang::LOCALES as $loc)
            <a href="?lang={{ $loc }}" @class(['font-medium' => app()->getLocale() === $loc, 'text-muted-foreground hover:text-foreground' => app()->getLocale() !== $loc])>{{ t('common.languages.'.$loc) }}</a>
        @endforeach
    </div>
@endsection
