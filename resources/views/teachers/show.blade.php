@extends('layouts.app')
@section('title', $teacher->full_name)
@section('actions')@can('teachers.manage')<a href="{{ route('teachers.edit', $teacher->id) }}" class="btn-secondary"><x-icon name="edit" class="h-4 w-4" /> {{ __('Изменить') }}</a>@endcan @endsection
@section('content')
<form method="GET" class="mb-4 flex flex-wrap gap-1.5">
    @foreach(['month' => 'Текущий месяц', 'last_month' => 'Прошлый месяц', 'year' => 'Текущий год'] as $k => $l)<button name="period" value="{{ $k }}" class="{{ $key === $k ? 'btn-primary' : 'btn-secondary' }} btn-sm">{{ __($l) }}</button>@endforeach
</form>
<div class="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-7">
    <x-stat :label="__('Групп')" :value="$kpi['groups'] ?? 0" /><x-stat :label="__('Учеников')" :value="$kpi['students'] ?? 0" />
    <x-stat :label="__('Занятий проведено')" :value="$kpi['lessons'] ?? 0" /><x-stat :label="__('Посещаемость')" :value="($kpi['attendance'] ?? null) === null ? '—' : $kpi['attendance'].'%'" />
    <x-stat :label="__('Ср. посещаемость групп')" :value="($kpi['avg_group_attendance'] ?? null) === null ? '—' : $kpi['avg_group_attendance'].'%'" />
    <x-stat :label="__('Отмен')" :value="$kpi['cancelled'] ?? 0" />
    @can('salaries.view')<x-stat :label="__('Начислено')" :value="money($kpi['salary'] ?? 0)" />@endcan
</div>
<div class="mt-4 grid gap-4 lg:grid-cols-3">
    <div class="card card-body"><dl class="grid grid-cols-3 gap-y-2 text-sm">
        <dt class="text-slate-500">{{ __('Телефон') }}</dt><dd class="col-span-2">{{ $teacher->phone ?? '—' }}</dd>
        <dt class="text-slate-500">Telegram</dt><dd class="col-span-2">{{ $teacher->telegram ?? '—' }}</dd>
        <dt class="text-slate-500">{{ __('Предмет') }}</dt><dd class="col-span-2">{{ $teacher->subject?->name ?? '—' }}</dd>
        <dt class="text-slate-500">{{ __('Филиал') }}</dt><dd class="col-span-2">{{ $teacher->branch?->name ?? '—' }}</dd>
        <dt class="text-slate-500">{{ __('Оплата') }}</dt><dd class="col-span-2">{{ __(\App\Models\Teacher::PAY_TYPES[$teacher->pay_type]) }}: {{ number_format($teacher->rate, 0, '.', ' ') }}{{ $teacher->pay_type === 'percent' ? '%' : '' }}</dd>
        <dt class="text-slate-500">{{ __('С нами с') }}</dt><dd class="col-span-2">{{ fdate($teacher->hired_at) }}</dd>
        <dt class="text-slate-500">{{ __('Вход в систему') }}</dt><dd class="col-span-2">{{ $teacher->user?->email ?? $teacher->user?->phone ?? '—' }}</dd></dl></div>
    <div class="card lg:col-span-2"><div class="border-b p-4 text-sm font-semibold dark:border-slate-800">{{ __('Группы') }}</div>
        <ul class="divide-y dark:divide-slate-800">@forelse($groups as $g)<li class="flex items-center justify-between p-3 text-sm"><a class="link font-medium" href="{{ route('groups.show', $g) }}">{{ $g->name }}</a><span class="text-slate-500">{{ $g->course?->name }} · {{ $g->students_count }}/{{ $g->max_students }}</span></li>@empty<li class="p-4 text-sm text-slate-400">—</li>@endforelse</ul></div>
</div>
@endsection
