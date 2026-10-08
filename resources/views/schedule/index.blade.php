@extends('layouts.app')
@section('title', __('Расписание'))
@section('actions')@can('schedule.manage')<a href="{{ route('schedule.create') }}" class="btn-primary"><x-icon name="plus" class="h-4 w-4" /> {{ __('Добавить в расписание') }}</a>@endcan @endsection
@section('content')
<form method="GET" class="mb-4 flex flex-wrap gap-2">
    <select name="group_id" onchange="this.form.submit()" class="input !w-auto"><option value="">{{ __('Все группы') }}</option>@foreach($groups as $id => $n)<option value="{{ $id }}" @selected(request('group_id') == $id)>{{ $n }}</option>@endforeach</select>
    <select name="teacher_id" onchange="this.form.submit()" class="input !w-auto"><option value="">{{ __('Все преподаватели') }}</option>@foreach($teachers as $id => $n)<option value="{{ $id }}" @selected(request('teacher_id') == $id)>{{ $n }}</option>@endforeach</select>
    <select name="room_id" onchange="this.form.submit()" class="input !w-auto"><option value="">{{ __('Все аудитории') }}</option>@foreach($rooms as $id => $n)<option value="{{ $id }}" @selected(request('room_id') == $id)>{{ $n }}</option>@endforeach</select>
    @if(request()->query())<a href="{{ route('schedule.index') }}" class="btn-ghost">{{ __('Сбросить') }}</a>@endif
</form>
<div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4 2xl:grid-cols-7">
@foreach(\App\Models\Schedule::WEEKDAYS as $d => $name)
    <div class="card {{ now()->dayOfWeekIso === $d ? 'ring-2 ring-brand-500/40' : '' }}">
        <div class="border-b px-3 py-2 text-sm font-semibold dark:border-slate-800">{{ __($name) }}</div>
        <div class="space-y-2 p-2">
        @forelse($byDay[$d] ?? [] as $s)
            <div class="rounded-lg bg-slate-50 p-2 text-sm dark:bg-slate-800">
                <div class="flex justify-between"><b>{{ ftime($s->start_time) }}–{{ ftime($s->end_time) }}</b>
                    @can('schedule.manage')<a href="{{ route('schedule.edit', $s) }}" class="link text-xs">{{ __('изм.') }}</a>@endcan</div>
                <a href="{{ route('groups.show', $s->group_id) }}" class="link">{{ $s->group?->name }}</a>
                <div class="text-xs text-slate-500">{{ $s->teacher?->full_name }} · {{ $s->room?->name }}</div>
            </div>
        @empty<div class="py-3 text-center text-xs text-slate-300">—</div>@endforelse
        </div>
    </div>
@endforeach
</div>
@endsection
