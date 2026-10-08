@extends('layouts.app')
@section('title', 'Telegram')
@section('actions')
    <form method="POST" action="{{ route('telegram.process') }}">@csrf<button class="btn-primary">{{ __('Отправить очередь сейчас') }}</button></form>
@endsection
@section('content')
<div class="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4">
    <x-stat :label="__('Бот')" :value="$enabled ? __('Подключён') : __('Не настроен')" :tone="$enabled ? 'good' : 'bad'" :hint="$botUsername ? '@'.$botUsername : 'TELEGRAM_BOT_TOKEN в .env'" />
    <x-stat :label="__('В очереди')" :value="$stats['pending'] ?? 0" /><x-stat :label="__('Отправлено')" :value="$stats['sent'] ?? 0" tone="good" /><x-stat :label="__('Ошибки')" :value="$stats['failed'] ?? 0" :tone="($stats['failed'] ?? 0) ? 'bad' : null" />
</div>
<div class="grid gap-4 xl:grid-cols-3">
  <div class="space-y-4">
    <div class="card card-body">
        <h2 class="mb-2 text-sm font-semibold">{{ __('Привязать Telegram') }}</h2>
        <p class="mb-3 text-xs text-slate-500">{{ __('Связь идёт по числовому Telegram ID (не по username). Сформируйте персональную ссылку и отправьте человеку — после нажатия «Start» аккаунт будет привязан.') }}</p>
        <form method="POST" action="{{ route('telegram.link') }}" class="space-y-2">@csrf
            <select name="type" class="input">@foreach($types as $k => [$c, $label])<option value="{{ $k }}">{{ __($label) }}</option>@endforeach</select>
            <input type="number" name="id" class="input" placeholder="ID" required>
            <button class="btn-secondary w-full">{{ __('Получить ссылку') }}</button>
        </form>
        @if($link)
            <div class="mt-3 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-900 dark:bg-emerald-900/20 dark:text-emerald-200">
                <div class="font-medium">{{ $link['name'] }}</div>
                @if($link['url'])<a class="break-all underline" href="{{ $link['url'] }}" target="_blank" rel="noopener">{{ $link['url'] }}</a>@else<div>/start {{ $link['token'] }}</div><div class="text-xs">{{ __('Задайте TELEGRAM_BOT_USERNAME, чтобы получать готовую ссылку.') }}</div>@endif
            </div>
        @endif
    </div>
    <div class="card card-body"><h2 class="mb-2 text-sm font-semibold">{{ __('Привязанные аккаунты') }}</h2>
        <ul class="divide-y text-sm dark:divide-slate-800">@forelse($accounts as $a)<li class="flex items-center justify-between py-2"><span>{{ $a->linkable?->full_name ?? $a->linkable?->name ?? '—' }} <span class="text-xs text-slate-400">{{ class_basename($a->linkable_type) }}</span></span>
            <form method="POST" action="{{ route('telegram.test') }}">@csrf<input type="hidden" name="chat_id" value="{{ $a->chat_id }}"><button class="text-xs text-brand-600">{{ __('тест') }}</button></form></li>@empty<li class="py-2 text-slate-400">—</li>@endforelse</ul></div>
  </div>
  <div class="card xl:col-span-2">
    <form method="GET" class="flex flex-wrap gap-2 border-b p-3 dark:border-slate-800">
        <select name="status" class="input !w-auto"><option value="">{{ __('Все статусы') }}</option>@foreach(['pending' => 'В очереди', 'sent' => 'Отправлено', 'failed' => 'Ошибка'] as $k => $v)<option value="{{ $k }}" @selected(request('status') === $k)>{{ __($v) }}</option>@endforeach</select>
        <select name="type" class="input !w-auto"><option value="">{{ __('Все типы') }}</option>@foreach(\App\Models\Notification::TYPES as $k => $v)<option value="{{ $k }}" @selected(request('type') === $k)>{{ __($v) }}</option>@endforeach</select>
        <button class="btn-secondary">{{ __('Применить') }}</button>
    </form>
    <ul class="divide-y dark:divide-slate-800">
    @forelse($items as $n)
        <li class="p-4 text-sm">
            <div class="flex flex-wrap items-center gap-2"><span class="text-xs text-slate-500">{{ $n->created_at->format('d.m H:i') }}</span><x-badge>{{ __(\App\Models\Notification::TYPES[$n->type] ?? $n->type) }}</x-badge>
                <x-badge :color="['pending'=>'amber','sent'=>'emerald','failed'=>'rose'][$n->status]">{{ $n->status }}</x-badge><span class="text-xs text-slate-400">chat {{ $n->chat_id }} · {{ __('попыток') }} {{ $n->attempts }}</span>
                @if($n->status === 'failed' || $n->status === 'pending' && $n->error)<form method="POST" action="{{ route('telegram.retry', $n) }}" class="ml-auto">@csrf<button class="btn-secondary btn-sm">{{ __('Повторить') }}</button></form>@endif</div>
            <div class="mt-1 whitespace-pre-line text-slate-700 dark:text-slate-300">{{ $n->body }}</div>
            @if($n->error)<div class="mt-1 text-xs text-rose-600">{{ $n->error }}</div>@endif
        </li>
    @empty<li class="p-10 text-center text-slate-400">{{ __('Сообщений нет') }}</li>@endforelse
    </ul>
    <div class="border-t p-3 dark:border-slate-800">{{ $items->links() }}</div>
  </div>
</div>
@endsection
