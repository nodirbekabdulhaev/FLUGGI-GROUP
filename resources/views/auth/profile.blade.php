@extends('layouts.app')
@section('title', __('Профиль'))
@section('content')
<form method="POST" action="{{ route('profile.update') }}" class="card card-body max-w-xl space-y-4">
    @csrf @method('PUT')
    <div><label class="label">{{ __('Имя') }}</label><input name="name" value="{{ old('name', $user->name) }}" class="input" required></div>
    <div><label class="label">{{ __('Язык') }}</label><select name="locale" class="input"><option value="ru" @selected($user->locale==='ru')>Русский</option><option value="uz" @selected($user->locale==='uz')>O‘zbekcha</option></select></div>
    <hr class="dark:border-slate-700">
    <div class="text-sm font-medium">{{ __('Смена пароля') }}</div>
    <div><label class="label">{{ __('Текущий пароль') }}</label><input type="password" name="current_password" class="input" autocomplete="current-password"></div>
    <div><label class="label">{{ __('Новый пароль') }}</label><input type="password" name="password" class="input" autocomplete="new-password"><p class="mt-1 text-xs text-slate-500">{{ __('Минимум 10 символов, буквы и цифры') }}</p></div>
    <div><label class="label">{{ __('Повторите пароль') }}</label><input type="password" name="password_confirmation" class="input" autocomplete="new-password"></div>
    <button class="btn-primary">{{ __('Сохранить') }}</button>
</form>
@endsection
