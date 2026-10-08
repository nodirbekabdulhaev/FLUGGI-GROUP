@extends('layouts.app')
@section('title', $lesson->group?->name.' · '.fdate($lesson->lesson_date, 'd.m'))
@section('content')
@php $colors = ['planned'=>'amber','held'=>'emerald','cancelled'=>'rose','rescheduled'=>'slate']; $sc = ['present'=>'emerald','late'=>'amber','absent'=>'rose','excused'=>'blue']; @endphp
<div class="grid gap-4 lg:grid-cols-3">
  <div class="space-y-4">
    <div class="card card-body">
        <div class="mb-2 flex items-center justify-between"><a class="link text-lg font-semibold" href="{{ route('groups.show', $lesson->group_id) }}">{{ $lesson->group?->name }}</a>
            <x-badge :color="$colors[$lesson->status]">{{ __(\App\Models\Lesson::STATUSES[$lesson->status]) }}</x-badge></div>
        <dl class="grid grid-cols-3 gap-y-2 text-sm">
            <dt class="text-slate-500">{{ __('Дата') }}</dt><dd class="col-span-2">{{ fdate($lesson->lesson_date) }}, {{ ftime($lesson->start_time) }}–{{ ftime($lesson->end_time) }}</dd>
            <dt class="text-slate-500">{{ __('Аудитория') }}</dt><dd class="col-span-2">{{ $lesson->room?->name ?? '—' }}</dd>
            <dt class="text-slate-500">{{ __('Преподаватель') }}</dt><dd class="col-span-2">{{ $lesson->teacher?->full_name ?? '—' }}</dd>
            <dt class="text-slate-500">{{ __('Курс') }}</dt><dd class="col-span-2">{{ $lesson->group?->course?->name }}</dd>
            @if($lesson->cancel_reason)<dt class="text-slate-500">{{ __('Причина') }}</dt><dd class="col-span-2 text-rose-600">{{ $lesson->cancel_reason }}</dd>@endif
        </dl>
    </div>
    @can('schedule.manage')
    @if(in_array($lesson->status, ['planned']))
    <div class="card card-body space-y-3">
        <form method="POST" action="{{ route('lessons.cancel', $lesson) }}" class="space-y-2" onsubmit="return confirm('{{ __('Отменить занятие?') }}')">@csrf
            <input name="reason" class="input" placeholder="{{ __('Причина отмены') }}"><button class="btn-secondary w-full text-rose-600">{{ __('Отменить занятие') }}</button></form>
        <details><summary class="cursor-pointer text-sm font-medium text-brand-600">{{ __('Перенести') }}</summary>
            <form method="POST" action="{{ route('lessons.reschedule', $lesson) }}" class="mt-2 grid grid-cols-2 gap-2">@csrf
                <input type="date" name="lesson_date" value="{{ $lesson->lesson_date->toDateString() }}" class="input col-span-2" required>
                <input type="time" name="start_time" value="{{ ftime($lesson->start_time) }}" class="input" required><input type="time" name="end_time" value="{{ ftime($lesson->end_time) }}" class="input" required>
                <select name="teacher_id" class="input"><option value="">{{ __('Тот же преподаватель') }}</option>@foreach($teachers as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>
                <select name="room_id" class="input"><option value="">{{ __('Та же аудитория') }}</option>@foreach($rooms as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>
                <button class="btn-primary col-span-2">{{ __('Перенести') }}</button></form></details>
    </div>
    @endif
    @endcan
  </div>

  <div class="card lg:col-span-2" x-data="{ mark(s) { document.querySelectorAll('[data-status]').forEach(el => { if (el.value === s) el.checked = true }) } }">
    @if($canMark)
    <form method="POST" action="{{ route('lessons.mark', $lesson) }}">@csrf
    @endif
        <div class="flex flex-wrap items-center justify-between gap-2 border-b p-4 dark:border-slate-800">
            <h2 class="text-sm font-semibold">{{ __('Посещаемость') }} <span class="font-normal text-slate-400">({{ $students->count() }})</span></h2>
            @if($canMark)<button type="button" @click="mark('present')" class="btn-secondary btn-sm">☑ {{ __('Все присутствуют') }}</button>@endif
        </div>
        @if($canMark)<div class="border-b p-3 dark:border-slate-800"><input name="topic" value="{{ old('topic', $lesson->topic) }}" class="input" placeholder="{{ __('Тема занятия (необязательно)') }}"></div>@endif
        <ul class="divide-y dark:divide-slate-800">
        @forelse($students as $s)
            @php $m = $marks[$s->id] ?? null; $cur = old("rows.{$s->id}.status", $m?->status ?? ($lesson->status === 'held' ? 'absent' : 'present')); @endphp
            <li class="flex flex-wrap items-center gap-2 px-4 py-2.5">
                <a class="min-w-0 flex-1 truncate text-sm font-medium" href="{{ route('students.show', $s->id) }}">{{ $s->full_name }}</a>
                @if($canMark)
                <div class="flex gap-1">
                @foreach(['present' => '✓', 'late' => '⏱', 'absent' => '✕', 'excused' => 'У'] as $st => $ico)
                    <label class="cursor-pointer" title="{{ __(\App\Models\Attendance::STATUSES[$st]) }}"><input data-status type="radio" name="rows[{{ $s->id }}][status]" value="{{ $st }}" @checked($cur === $st) class="peer sr-only">
                        <span class="flex h-9 w-9 items-center justify-center rounded-lg border text-sm peer-checked:text-white dark:border-slate-700 {{ ['present'=>'peer-checked:border-emerald-600 peer-checked:bg-emerald-600','late'=>'peer-checked:border-amber-500 peer-checked:bg-amber-500','absent'=>'peer-checked:border-rose-600 peer-checked:bg-rose-600','excused'=>'peer-checked:border-blue-600 peer-checked:bg-blue-600'][$st] }}">{{ $ico }}</span></label>
                @endforeach
                </div>
                <input name="rows[{{ $s->id }}][comment]" value="{{ old("rows.{$s->id}.comment", $m?->comment) }}" class="input !w-full sm:!w-52 !py-1.5 text-xs" placeholder="{{ __('Комментарий') }}">
                @else
                    @if($m)<x-badge :color="$sc[$m->status]">{{ __(\App\Models\Attendance::STATUSES[$m->status]) }}</x-badge>@else<span class="text-xs text-slate-400">—</span>@endif
                    @if($m?->comment)<span class="text-xs text-slate-500">{{ $m->comment }}</span>@endif
                @endif
            </li>
        @empty<li class="p-8 text-center text-sm text-slate-400">{{ __('В группе нет учеников') }}</li>@endforelse
        </ul>
    @if($canMark)
        <div class="sticky bottom-16 border-t bg-white p-3 dark:border-slate-800 dark:bg-slate-900 lg:bottom-0"><button class="btn-primary w-full sm:w-auto">{{ __('Сохранить посещаемость') }}</button>
            <span class="ml-2 text-xs text-slate-500">✓ {{ __('был') }} · ⏱ {{ __('опоздал') }} · ✕ {{ __('не был') }} · У — {{ __('уваж. причина') }}</span></div>
    </form>
    @endif
  </div>
</div>
@endsection
