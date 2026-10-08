@extends('layouts.app')
@section('title', __('Роли'))
@section('actions')<a href="{{ route('roles.create') }}" class="btn-primary"><x-icon name="plus" class="h-4 w-4" /> {{ __('Новая роль') }}</a>@endsection
@section('content')
<div class="card overflow-x-auto"><table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
    <thead class="bg-slate-50 dark:bg-slate-800/50"><tr><th class="th">{{ __('Роль') }}</th><th class="th">{{ __('Описание') }}</th><th class="th">{{ __('Прав') }}</th><th class="th">{{ __('Пользователей') }}</th><th class="th"></th></tr></thead>
    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
    @foreach($roles as $r)
        <tr><td class="td font-medium">{{ $r->name }} @if($r->is_system)<x-badge>{{ __('системная') }}</x-badge>@endif</td><td class="td text-slate-500">{{ $r->description }}</td>
            <td class="td">{{ $r->slug === 'super_admin' ? __('все') : $r->permissions_count }}</td><td class="td">{{ $r->users_count }}</td>
            <td class="td text-right whitespace-nowrap">
                @if(! $r->is_system)
                <a href="{{ route('roles.edit', $r) }}" class="btn-ghost btn-sm"><x-icon name="edit" class="h-4 w-4" /></a>
                <form method="POST" action="{{ route('roles.destroy', $r) }}" class="inline" onsubmit="return confirm('{{ __('Удалить роль?') }}')">@csrf @method('DELETE')<button class="btn-ghost btn-sm text-rose-600"><x-icon name="trash" class="h-4 w-4" /></button></form>
                @endif</td></tr>
    @endforeach
    </tbody></table></div>
@if($errors->has('delete'))<div class="mt-3 rounded-lg border border-rose-200 bg-rose-50 px-4 py-2 text-sm text-rose-800">{{ $errors->first('delete') }}</div>@endif
@endsection
