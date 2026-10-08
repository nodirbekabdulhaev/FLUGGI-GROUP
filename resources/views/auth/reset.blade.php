@extends('layouts.guest')
@section('title', __('Новый пароль'))
@section('content')
<form method="POST" action="{{ route('password.update') }}" class="space-y-4">
    @csrf
    <input type="hidden" name="token" value="{{ $token }}">
    <div><label class="label">Email</label><input type="email" name="email" value="{{ old('email', $email) }}" class="input" required>
        @error('email')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror</div>
    <div><label class="label">{{ __('Новый пароль') }}</label><input type="password" name="password" class="input" required autocomplete="new-password">
        <p class="mt-1 text-xs text-slate-500">{{ __('Минимум 10 символов, буквы и цифры') }}</p>
        @error('password')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror</div>
    <div><label class="label">{{ __('Повторите пароль') }}</label><input type="password" name="password_confirmation" class="input" required autocomplete="new-password"></div>
    <button class="btn-primary w-full">{{ __('Сохранить пароль') }}</button>
</form>
@endsection
