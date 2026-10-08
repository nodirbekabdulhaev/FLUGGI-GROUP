@extends('layouts.app')
@section('title', __('Настройки системы'))
@section('content')
<div class="grid gap-4 xl:grid-cols-3">
<form method="POST" enctype="multipart/form-data" action="{{ route('settings.update') }}" class="space-y-4 xl:col-span-2">@csrf @method('PUT')
    <div class="card card-body grid gap-4 sm:grid-cols-2">
        <h2 class="text-sm font-semibold sm:col-span-2">{{ __('Организация') }}</h2>
        <div class="sm:col-span-2"><label class="label">{{ __('Название') }}</label><input name="name" value="{{ old('name', $org->name) }}" class="input" required></div>
        <div><label class="label">{{ __('Валюта') }}</label><input name="currency" value="{{ old('currency', $org->currency) }}" class="input" required></div>
        <div><label class="label">{{ __('Часовой пояс') }}</label><input name="timezone" value="{{ old('timezone', $org->timezone) }}" class="input" required></div>
        <div><label class="label">{{ __('Язык по умолчанию') }}</label><select name="locale" class="input"><option value="ru" @selected($org->locale==='ru')>Русский</option><option value="uz" @selected($org->locale==='uz')>O‘zbekcha</option></select></div>
        <div><label class="label">{{ __('Логотип') }}</label><input type="file" name="logo" accept=".jpg,.jpeg,.png,.webp" class="input"></div>
        <div class="sm:col-span-2 rounded-lg bg-slate-50 p-3 text-sm dark:bg-slate-800">
            <div class="font-medium">{{ __('Webhook лидов') }}</div>
            <code class="mt-1 block break-all text-xs">POST {{ url('/api/v1/webhooks/leads') }}<br>X-Webhook-Key: {{ $org->webhook_key }}</code>
            <label class="mt-2 inline-flex items-center gap-2 text-xs"><input type="checkbox" name="regenerate_key" value="1" class="rounded border-slate-300 text-brand-600"> {{ __('Перевыпустить ключ (старый перестанет работать)') }}</label>
        </div>
    </div>
    <div class="card card-body space-y-3">
        <h2 class="text-sm font-semibold">{{ __('Шаблоны Telegram-уведомлений') }}</h2>
        <p class="text-xs text-slate-500">{{ __('Переменные') }}: {name} {phone} {course} {branch} {source} {group} {time} {room} {amount} {date} {leads} {students} {paid} {debts} {lessons} {attendance} {cancelled}. {{ __('Пустое поле = текст по умолчанию.') }}</p>
        @foreach($defaults as $type => $def)
            <div><label class="label">{{ __(\App\Models\Notification::TYPES[$type] ?? 'Дневной отчёт') }} <code class="text-slate-400">{{ $type }}</code></label>
                <textarea name="tpl[{{ $type }}]" rows="{{ $type === 'daily_report' ? 9 : 4 }}" class="input font-mono text-xs" placeholder="{{ $def }}">{{ old('tpl.'.$type, \App\Models\Setting::get('tpl.'.$type)) }}</textarea></div>
        @endforeach
    </div>
    <button class="btn-primary">{{ __('Сохранить настройки') }}</button>
</form>

<div class="space-y-4">
    <div class="card card-body"><h2 class="mb-2 text-sm font-semibold">{{ __('Система') }}</h2>
        <dl class="grid grid-cols-2 gap-y-1 text-sm">@foreach($system as $k => $v)<dt class="text-slate-500">{{ $k }}</dt><dd>{{ $v }}</dd>@endforeach</dl></div>
    @can('backup.manage')
    <div class="card card-body">
        <div class="mb-2 flex items-center justify-between"><h2 class="text-sm font-semibold">{{ __('Резервные копии') }}</h2>
            <form method="POST" action="{{ route('backups.create') }}">@csrf<button class="btn-secondary btn-sm">{{ __('Создать сейчас') }}</button></form></div>
        <p class="mb-2 text-xs text-slate-500">{{ __('Автоматически: ежедневно в 03:00 (cron). Хранится последних') }} {{ config('backup.keep') }}.</p>
        <ul class="divide-y text-sm dark:divide-slate-800">
        @forelse($backups as $b)
            <li class="py-2" x-data="{ r: false }"><div class="flex items-center justify-between gap-2"><span class="min-w-0 truncate">{{ $b['name'] }}</span>
                <span class="whitespace-nowrap text-xs text-slate-400">{{ number_format($b['size'] / 1024, 0) }} KB</span></div>
                <div class="mt-1 flex gap-3 text-xs"><a class="link" href="{{ route('backups.download', $b['name']) }}">{{ __('Скачать') }}</a>
                    @if(auth()->user()->isSuperAdmin())<button type="button" @click="r = !r" class="text-rose-600">{{ __('Восстановить') }}</button>@endif</div>
                @if(auth()->user()->isSuperAdmin())
                <form x-cloak x-show="r" method="POST" action="{{ route('backups.restore', $b['name']) }}" class="mt-2 space-y-1">@csrf
                    <p class="text-xs text-rose-600">{{ __('Все текущие данные будут заменены. Перед восстановлением создаётся копия текущего состояния. Введите RESTORE для подтверждения.') }}</p>
                    <input name="confirm" class="input !py-1" placeholder="RESTORE"><button class="btn-danger btn-sm">{{ __('Восстановить базу') }}</button></form>
                @endif</li>
        @empty<li class="py-3 text-slate-400">—</li>@endforelse
        </ul>
    </div>
    @endcan
    <div class="card card-body text-sm"><h2 class="mb-2 font-semibold">Cron (Beget)</h2>
        <code class="block break-all rounded bg-slate-50 p-2 text-xs dark:bg-slate-800">* * * * * /usr/local/bin/php {{ base_path('artisan') }} schedule:run &gt;/dev/null 2&gt;&amp;1</code></div>
</div>
</div>
@endsection
