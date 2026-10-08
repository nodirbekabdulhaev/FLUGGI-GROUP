@extends('layouts.guest')
@section('title', __('Восстановление пароля'))
@section('content')
<form method="POST" action="{{ route('password.email') }}" class="space-y-4">
    @csrf
    <p class="text-sm text-slate-600">{{ __('Укажите email, привязанный к учётной записи — мы отправим ссылку для сброса пароля.') }}</p>
    @if(session('ok'))<div class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{{ session('ok') }}</div>@endif
    <div><label class="label" for="email">Email</label><input id="email" type="email" name="email" value="{{ old('email') }}" class="input" required>
        @error('email')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror</div>
    <button class="btn-primary w-full">{{ __('Отправить ссылку') }}</button>
    <a href="{{ route('login') }}" class="block text-center text-sm link">{{ __('Назад ко входу') }}</a>
</form>
@endsection
