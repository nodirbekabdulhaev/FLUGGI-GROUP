@extends('layouts.app')
@section('title', $lead->full_name)
@section('actions')
    @can('leads.manage')<a href="{{ route('leads.edit', $lead) }}" class="btn-secondary"><x-icon name="edit" class="h-4 w-4" /> {{ __('Изменить') }}</a>@endcan
    @can('leads.delete')
        <form method="POST" action="{{ route('leads.destroy', $lead) }}" onsubmit="return confirm('{{ __('Удалить лид?') }}')">@csrf @method('DELETE')<button class="btn-ghost text-rose-600"><x-icon name="trash" class="h-4 w-4" /></button></form>
    @endcan
@endsection
@section('content')
<div class="grid gap-4 lg:grid-cols-3">
    <div class="space-y-4 lg:col-span-1">
        <div class="card card-body">
            <div class="mb-3 flex items-start justify-between">
                <div><div class="text-lg font-semibold">{{ $lead->full_name }}</div><div class="text-xs text-slate-500">ID #{{ $lead->id }}</div></div>
                <x-badge :color="$lead->status?->color ?? 'slate'">{{ $lead->status?->name }}</x-badge>
            </div>
            <dl class="grid grid-cols-3 gap-y-2 text-sm">
                <dt class="text-slate-500">{{ __('Телефон') }}</dt><dd class="col-span-2"><a class="link" href="tel:{{ $lead->phone }}">{{ $lead->phone }}</a></dd>
                @if($lead->telegram)<dt class="text-slate-500">Telegram</dt><dd class="col-span-2">{{ $lead->telegram }}</dd>@endif
                @if($lead->instagram)<dt class="text-slate-500">Instagram</dt><dd class="col-span-2">{{ $lead->instagram }}</dd>@endif
                @if($lead->age)<dt class="text-slate-500">{{ __('Возраст') }}</dt><dd class="col-span-2">{{ $lead->age }}</dd>@endif
                @if($lead->parent_name || $lead->parent_phone)<dt class="text-slate-500">{{ __('Родитель') }}</dt><dd class="col-span-2">{{ $lead->parent_name }} {{ $lead->parent_phone }}</dd>@endif
                <dt class="text-slate-500">{{ __('Источник') }}</dt><dd class="col-span-2">{{ $lead->source?->name ?? '—' }}</dd>
                <dt class="text-slate-500">{{ __('Курс') }}</dt><dd class="col-span-2">{{ $lead->course?->name ?? '—' }}</dd>
                <dt class="text-slate-500">{{ __('Филиал') }}</dt><dd class="col-span-2">{{ $lead->branch?->name ?? '—' }}</dd>
                <dt class="text-slate-500">{{ __('Менеджер') }}</dt><dd class="col-span-2">{{ $lead->manager?->name ?? '—' }}</dd>
                <dt class="text-slate-500">{{ __('Создан') }}</dt><dd class="col-span-2">{{ $lead->created_at->format('d.m.Y H:i') }}</dd>
                <dt class="text-slate-500">{{ __('Последний контакт') }}</dt><dd class="col-span-2">{{ $lead->last_contact_at?->format('d.m.Y H:i') ?? '—' }}</dd>
                <dt class="text-slate-500">{{ __('Следующий контакт') }}</dt><dd class="col-span-2 {{ $lead->next_contact_at?->isPast() && ! $lead->isConverted() ? 'font-medium text-rose-600' : '' }}">{{ $lead->next_contact_at?->format('d.m.Y H:i') ?? '—' }}</dd>
                @if($lead->utm_source || $lead->campaign)<dt class="text-slate-500">UTM</dt><dd class="col-span-2 break-words text-xs text-slate-600">{{ collect([$lead->campaign, $lead->utm_source, $lead->utm_medium, $lead->utm_campaign, $lead->utm_content, $lead->utm_term])->filter()->join(' / ') }}</dd>@endif
            </dl>
            @if($lead->comment)<p class="mt-3 rounded-lg bg-slate-50 p-2 text-sm dark:bg-slate-800">{{ $lead->comment }}</p>@endif
        </div>

        @can('leads.manage')
        <div class="card card-body">
            @if($lead->isConverted())
                <p class="text-sm">✅ {{ __('Конвертирован в ученика') }}: <a class="link font-medium" href="{{ route('students.show', $lead->converted_student_id) }}">{{ $lead->student?->full_name }}</a></p>
            @else
                <form method="POST" action="{{ route('leads.status', $lead) }}" class="space-y-2">@csrf
                    <label class="label">{{ __('Сменить статус') }}</label>
                    <select name="status_id" class="input">@foreach($statuses as $s)<option value="{{ $s->id }}" @selected($s->id === $lead->status_id)>{{ $s->name }}</option>@endforeach</select>
                    <input name="comment" class="input" placeholder="{{ __('Комментарий (необязательно)') }}">
                    <button class="btn-secondary w-full">{{ __('Обновить статус') }}</button>
                </form>
                @can('students.manage')
                <details class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50/60 p-3 dark:border-emerald-900 dark:bg-emerald-900/10">
                    <summary class="cursor-pointer text-sm font-semibold text-emerald-700">🎓 {{ __('Создать ученика') }}</summary>
                    <form method="POST" action="{{ route('leads.convert', $lead) }}" class="mt-3 space-y-2">@csrf
                        <p class="text-xs text-slate-500">{{ __('Имя, телефон, родитель, источник, менеджер, филиал будут перенесены автоматически. Лид сохранится со статусом «Ученик».') }}</p>
                        <div><label class="label">{{ __('Записать в группу (необязательно)') }}</label>
                            <select name="group_id" class="input"><option value="">—</option>@foreach($groups as $g)<option value="{{ $g->id }}">{{ $g->name }} ({{ money($g->effectivePrice(), false) }})</option>@endforeach</select></div>
                        <div class="grid grid-cols-2 gap-2">
                            <div><label class="label">{{ __('Цена') }}</label><input type="number" min="0" name="price" class="input" placeholder="{{ __('по группе') }}"></div>
                            <div><label class="label">{{ __('Скидка') }}</label><input type="number" min="0" name="discount" class="input" value="0"></div>
                        </div>
                        <button class="btn-primary w-full">{{ __('Создать ученика') }}</button>
                    </form>
                </details>
                @endcan
            @endif
        </div>
        @endcan
    </div>

    <div class="space-y-4 lg:col-span-2">
        @can('leads.manage')
        <form method="POST" action="{{ route('leads.note', $lead) }}" class="card card-body space-y-2">@csrf
            <div class="flex gap-2">
                <select name="type" class="input !w-auto"><option value="call">📞 {{ __('Звонок') }}</option><option value="note">📝 {{ __('Заметка') }}</option></select>
                <input name="body" class="input" placeholder="{{ __('Что произошло? Напр.: позвонил, записал на пробный') }}" required>
            </div>
            <div class="flex flex-wrap items-center gap-2"><label class="text-xs text-slate-500">{{ __('Следующий контакт') }}</label><input type="datetime-local" name="next_contact_at" class="input !w-auto !py-1.5"><button class="btn-primary btn-sm ml-auto">{{ __('Добавить') }}</button></div>
        </form>
        @endcan
        <div class="card card-body">
            <h2 class="mb-3 text-sm font-semibold">{{ __('История') }}</h2>
            <ol class="relative space-y-4 border-l border-slate-200 pl-5 dark:border-slate-700">
                @forelse($lead->notes as $n)
                    <li class="relative">
                        <span class="absolute -left-[26px] top-1 h-2.5 w-2.5 rounded-full {{ $n->type === 'system' ? 'bg-slate-300' : 'bg-brand-600' }}"></span>
                        <div class="text-xs text-slate-500">{{ $n->created_at->format('d.m H:i') }} · {{ $n->user?->name ?? 'system' }}</div>
                        <div class="text-sm {{ $n->type === 'system' ? 'text-slate-600 dark:text-slate-400' : '' }}">{{ $n->type === 'call' ? '📞 ' : '' }}{{ $n->body }}</div>
                    </li>
                @empty <li class="text-sm text-slate-400">{{ __('Нет записей') }}</li>
                @endforelse
            </ol>
        </div>
    </div>
</div>
@endsection
