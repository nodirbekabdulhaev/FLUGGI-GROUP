@extends('layouts.app')
@section('title', $role ? __('Роль').': '.$role->name : __('Новая роль'))
@section('content')
<form method="POST" action="{{ $role ? route('roles.update', $role) : route('roles.store') }}" class="max-w-4xl space-y-4">
    @csrf @if($role) @method('PUT') @endif
    <div class="card card-body grid gap-4 sm:grid-cols-2">
        <div><label class="label">{{ __('Название') }}</label><input name="name" value="{{ old('name', $role?->name) }}" class="input" required></div>
        <div><label class="label">{{ __('Описание') }}</label><input name="description" value="{{ old('description', $role?->description) }}" class="input"></div>
    </div>
    <div class="grid gap-4 md:grid-cols-2">
    @foreach($groups as $group => $perms)
        <div class="card card-body"><h3 class="mb-2 text-sm font-semibold">{{ __($group) }}</h3>
            <div class="space-y-1.5">@foreach($perms as $slug => $name)
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="permissions[]" value="{{ $slug }}" @checked(in_array($slug, old('permissions', $selected))) class="rounded border-slate-300 text-brand-600"> {{ __($name) }} <code class="text-[10px] text-slate-400">{{ $slug }}</code></label>
            @endforeach</div></div>
    @endforeach
    </div>
    <div class="flex gap-2"><button class="btn-primary">{{ __('Сохранить') }}</button><a href="{{ route('roles.index') }}" class="btn-secondary">{{ __('Отмена') }}</a></div>
</form>
@endsection
