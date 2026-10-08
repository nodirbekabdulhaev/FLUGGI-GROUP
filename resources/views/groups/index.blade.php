@extends('layouts.app')
@section('title', __('Группы'))
@section('actions')@can('groups.manage')<a href="{{ route('groups.create') }}" class="btn-primary"><x-icon name="plus" class="h-4 w-4" /> {{ __('Добавить группу') }}</a>@endcan @endsection
@section('content')
<div class="card" x-data="{ f: window.innerWidth >= 768 }">
    <button type="button" @click="f = !f" class="flex w-full items-center justify-between border-b px-4 py-3 text-sm font-medium md:hidden dark:border-slate-800">{{ __('Фильтры') }} <span x-text="f ? '▲' : '▼'"></span></button>
    <form method="GET" x-show="f" class="grid gap-2 border-b p-3 dark:border-slate-800 sm:grid-cols-3 lg:grid-cols-5">
        <input type="search" name="q" value="{{ request('q') }}" class="input" placeholder="{{ __('Название группы…') }}">
        <select name="status" class="input"><option value="">{{ __('Все (кроме архива)') }}</option>@foreach(\App\Models\Group::STATUSES as $k => $v)<option value="{{ $k }}" @selected(request('status') === $k)>{{ __($v) }}</option>@endforeach</select>
        <select name="course_id" class="input"><option value="">{{ __('Все курсы') }}</option>@foreach(\App\Support\Lookup::courses() as $id => $n)<option value="{{ $id }}" @selected(request('course_id') == $id)>{{ $n }}</option>@endforeach</select>
        <select name="teacher_id" class="input"><option value="">{{ __('Все преподаватели') }}</option>@foreach(\App\Support\Lookup::teachers() as $id => $n)<option value="{{ $id }}" @selected(request('teacher_id') == $id)>{{ $n }}</option>@endforeach</select>
        <div class="flex gap-2"><button class="btn-secondary">{{ __('Применить') }}</button>@if(request()->query())<a href="{{ route('groups.index') }}" class="btn-ghost">{{ __('Сбросить') }}</a>@endif</div>
    </form>
    <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
        <thead class="bg-slate-50 dark:bg-slate-800/50"><tr><th class="th">{{ __('Группа') }}</th><th class="th">{{ __('Курс') }}</th><th class="th">{{ __('Преподаватель') }}</th><th class="th">{{ __('Филиал') }}</th><th class="th">{{ __('Аудитория') }}</th><th class="th">{{ __('Учеников') }}</th><th class="th">{{ __('Период') }}</th><th class="th">{{ __('Статус') }}</th></tr></thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
        @forelse($groups as $g)
            <tr class="hover:bg-slate-50/60 dark:hover:bg-slate-800/40">
                <td class="td"><a class="link font-medium" href="{{ route('groups.show', $g) }}">{{ $g->name }}</a></td><td class="td">{{ $g->course?->name }}</td><td class="td">{{ $g->teacher?->full_name }}</td>
                <td class="td">{{ $g->branch?->name }}</td><td class="td">{{ $g->room?->name }}</td>
                <td class="td"><span class="{{ $g->students_count >= $g->max_students ? 'font-semibold text-amber-600' : '' }}">{{ $g->students_count }}/{{ $g->max_students }}</span></td>
                <td class="td whitespace-nowrap text-slate-500">{{ fdate($g->start_date, 'd.m.y') }} — {{ fdate($g->end_date, 'd.m.y') }}</td>
                <td class="td"><x-badge :color="['enrolling'=>'amber','active'=>'emerald','completed'=>'indigo','archived'=>'slate'][$g->status]">{{ __(\App\Models\Group::STATUSES[$g->status]) }}</x-badge></td></tr>
        @empty<tr><td colspan="8" class="px-3 py-10 text-center text-sm text-slate-400">{{ __('Нет данных') }}</td></tr>@endforelse
        </tbody></table></div>
    <div class="border-t p-3 dark:border-slate-800">{{ $groups->links() }}</div>
</div>
@if($errors->has('delete'))<div class="mt-3 rounded-lg border border-rose-200 bg-rose-50 px-4 py-2 text-sm text-rose-800">{{ $errors->first('delete') }}</div>@endif
@endsection
