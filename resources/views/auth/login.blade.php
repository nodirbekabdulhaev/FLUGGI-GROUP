@extends('layouts.guest')
@section('title', __('Вход'))
@section('content')
<form method="POST" action="{{ route('login.attempt') }}" class="space-y-4">
    @csrf
    @if(session('ok'))<div class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{{ session('ok') }}</div>@endif
    <div>
        <label class="label" for="login">{{ __('Телефон или email') }}</label>
        <input id="login" name="login" value="{{ old('login') }}" class="input" autocomplete="username" required autofocus>
        @error('login')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="label" for="password">{{ __('Пароль') }}</label>
        <input id="password" type="password" name="password" class="input" autocomplete="current-password" required>
    </div>
    <div class="flex items-center justify-between text-sm">
        <label class="inline-flex items-center gap-2"><input type="checkbox" name="remember" value="1" class="rounded border-slate-300 text-brand-600"> {{ __('Запомнить меня') }}</label>
        <a href="{{ route('password.request') }}" class="link">{{ __('Забыли пароль?') }}</a>
    </div>
    <button class="btn-primary w-full">{{ __('Войти') }}</button>
</form>
@endsection
