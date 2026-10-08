@extends('layouts.app')
@section('title', __('Занятия'))
@section('content')
@php $single = $date->equalTo($to); $colors = ['planned'=>'amber','held'=>'emerald','cancelled'=>'rose','rescheduled'=>'slate']; @endphp
<div class="card">
    <form method="GET" class="flex flex-wrap items-end gap-2 border-b p-3 dark:border-slate-800">
        <div class="flex items-center gap-1">
            <a href="{{ route('lessons.index', array_merge(request()->query(), ['date' => $date->copy()->subDay()->toDateString(), 'to' => null])) }}" class="btn-secondary btn-sm">‹</a>
            <input type="date" name="date" value="{{ $date->toDateString() }}" onchange="this.form.submit()" class="input !w-auto">
            <a href="{{ route('lessons.index', array_merge(request()->query(), ['date' => $date->copy()->addDay()->toDateString(), 'to' => null])) }}" class="btn-secondary btn-sm">›</a>
            <a href="{{ route('lessons.index') }}" class="btn-ghost btn-sm">{{ __('Сегодня') }}</a>
        </div>
        @unless($single)<input type="date" name="to" value="{{ $to->toDateString() }}" class="input !w-auto">@endunless
        <select name="group_id" class="input !w-auto"><option value="">{{ __('Все группы') }}</option>@foreach($groups as $id => $n)<option value="{{ $id }}" @selected(request('group_id') == $id)>{{ $n }}</option>@endforeach</select>
        @if(! auth()->user()->restrictedToOwnGroups())<select name="teacher_id" class="input !w-auto"><option value="">{{ __('Все преподаватели') }}</option>@foreach($teachers as $id => $n)<option value="{{ $id }}" @selected(request('teacher_id') == $id)>{{ $n }}</option>@endforeach</select>@endif
        <select name="status" class="input !w-auto"><option value="">{{ __('Все статусы') }}</option>@foreach(\App\Models\Lesson::STATUSES as $k => $v)<option value="{{ $k }}" @selected(request('status') === $k)>{{ __($v) }}</option>@endforeach</select>
        <button class="btn-secondary">{{ __('Применить') }}</button>
    </form>
    <div class="divide-y dark:divide-slate-800">
    @forelse($lessons as $l)
        <a href="{{ route('lessons.show', $l) }}" class="flex flex-wrap items-center gap-3 px-4 py-3 hover:bg-slate-50 dark:hover:bg-slate-800/50">
            <div class="w-24 shrink-0"><div class="font-semibold">{{ ftime($l->start_time) }}–{{ ftime($l->end_time) }}</div>@unless($single)<div class="text-xs text-slate-500">{{ fdate($l->lesson_date, 'd.m (D)') }}</div>@endunless</div>
            <div class="min-w-0 flex-1"><div class="font-medium">{{ $l->group?->name }}</div><div class="text-xs text-slate-500">{{ $l->teacher?->full_name }} · {{ $l->room?->name }}</div></div>
            @if($l->marked_count)<span class="text-xs text-slate-500">✓ {{ $l->marked_count }}</span>@endif
            <x-badge :color="$colors[$l->status]">{{ __(\App\Models\Lesson::STATUSES[$l->status]) }}</x-badge>
        </a>
    @empty<div class="p-10 text-center text-sm text-slate-400">{{ __('Занятий нет') }}</div>@endforelse
    </div>
</div>
@can('schedule.manage')
<details class="card card-body mt-4 max-w-3xl"><summary class="cursor-pointer text-sm font-medium text-brand-600">+ {{ __('Добавить разовое занятие') }}</summary>
    <form method="POST" action="{{ route('lessons.store') }}" class="mt-3 grid gap-3 sm:grid-cols-3">@csrf
        <select name="group_id" class="input sm:col-span-3" required><option value="">{{ __('Группа…') }}</option>@foreach($groups as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>
        <input type="date" name="lesson_date" value="{{ $date->toDateString() }}" class="input" required><input type="time" name="start_time" class="input" required><input type="time" name="end_time" class="input" required>
        <select name="teacher_id" class="input"><option value="">{{ __('Преподаватель группы') }}</option>@foreach($teachers as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>
        <select name="room_id" class="input"><option value="">{{ __('Аудитория группы') }}</option>@foreach($rooms as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>
        <button class="btn-primary">{{ __('Добавить') }}</button>
    </form></details>
@endcan
@endsection
