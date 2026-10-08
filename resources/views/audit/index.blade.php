@extends('layouts.app')
@section('title', __('История действий'))
@section('content')
@php
 $events = ['created' => ['Создал', 'emerald'], 'updated' => ['Изменил', 'blue'], 'deleted' => ['Удалил', 'rose'], 'restored' => ['Восстановил', 'amber'], 'login' => ['Вход', 'slate'], 'logout' => ['Выход', 'slate'], 'login_failed' => ['Неудачный вход', 'rose'], 'password_reset' => ['Сброс пароля', 'amber']];
 $fmt = fn($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (is_bool($v) ? ($v ? 'true' : 'false') : (string) $v);
@endphp
<div class="card">
    <form method="GET" class="grid gap-2 border-b p-3 dark:border-slate-800 sm:grid-cols-3 lg:grid-cols-6">
        <select name="user_id" class="input"><option value="">{{ __('Все пользователи') }}</option>@foreach($users as $id => $n)<option value="{{ $id }}" @selected(request('user_id') == $id)>{{ $n }}</option>@endforeach</select>
        <select name="event" class="input"><option value="">{{ __('Все действия') }}</option>@foreach($events as $k => $v)<option value="{{ $k }}" @selected(request('event') === $k)>{{ __($v[0]) }}</option>@endforeach</select>
        <select name="auditable_type" class="input"><option value="">{{ __('Все объекты') }}</option>@foreach($types as $t)<option @selected(request('auditable_type') === $t)>{{ $t }}</option>@endforeach</select>
        <input type="number" name="object_id" value="{{ request('object_id') }}" class="input" placeholder="ID {{ __('объекта') }}">
        <input type="date" name="from" value="{{ request('from') }}" class="input"><input type="date" name="to" value="{{ request('to') }}" class="input">
        <div class="flex gap-2"><button class="btn-secondary">{{ __('Применить') }}</button>@if(request()->query())<a href="{{ route('audit.index') }}" class="btn-ghost">{{ __('Сбросить') }}</a>@endif</div>
    </form>
    <ul class="divide-y dark:divide-slate-800">
    @forelse($logs as $log)
        <li class="p-4 text-sm">
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-xs text-slate-500">{{ $log->created_at->format('d.m.Y H:i') }}</span>
                <span class="font-medium">{{ $log->user_name ?? $log->user?->name ?? 'system' }}</span>
                <x-badge :color="$events[$log->event][1] ?? 'slate'">{{ __($events[$log->event][0] ?? $log->event) }}</x-badge>
                @if($log->auditable_type)<span>{{ $log->auditable_type }} @if($log->auditable_id)<a class="link" href="{{ route('audit.index', ['auditable_type' => $log->auditable_type, 'object_id' => $log->auditable_id]) }}">#{{ $log->auditable_id }}</a>@endif</span>@endif
                <span class="ml-auto text-xs text-slate-400">{{ $log->ip }}</span>
            </div>
            @if($log->event === 'updated' && $log->new_values)
                <div class="mt-1.5 space-y-0.5 font-mono text-xs">
                @foreach($log->new_values as $k => $new)<div><span class="text-slate-500">{{ $k }}:</span> <span class="text-rose-600 line-through">{{ $fmt($log->old_values[$k] ?? null) }}</span> → <span class="text-emerald-600">{{ $fmt($new) }}</span></div>@endforeach
                </div>
            @endif
        </li>
    @empty<li class="p-8 text-center text-sm text-slate-400">{{ __('Нет данных') }}</li>@endforelse
    </ul>
    <div class="border-t p-3 dark:border-slate-800">{{ $logs->links() }}</div>
</div>
@endsection
