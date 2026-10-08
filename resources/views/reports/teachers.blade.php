@extends('layouts.app')
@section('title', __('KPI преподавателей'))
@section('actions')@include('reports._bar', ['type' => 'teachers'])@endsection
@section('content')
@include('partials.period')
<div class="card overflow-x-auto"><table class="min-w-full text-sm">
    <thead class="bg-slate-50 dark:bg-slate-800/50"><tr><th class="th">{{ __('Преподаватель') }}</th><th class="th text-right">{{ __('Групп') }}</th><th class="th text-right">{{ __('Учеников') }}</th><th class="th text-right">{{ __('Занятий') }}</th><th class="th text-right">{{ __('Посещаемость') }}</th><th class="th text-right">{{ __('Ср. по группам') }}</th><th class="th text-right">{{ __('Отмен') }}</th><th class="th text-right">{{ __('Начислено') }}</th></tr></thead>
    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
    @forelse($rows as $t)<tr><td class="td font-medium"><a class="link" href="{{ route('teachers.show', $t['id']) }}">{{ $t['name'] }}</a></td><td class="td text-right">{{ $t['groups'] }}</td><td class="td text-right">{{ $t['students'] }}</td><td class="td text-right">{{ $t['lessons'] }}</td>
        <td class="td text-right font-semibold">{{ $t['attendance'] === null ? '—' : $t['attendance'].'%' }}</td><td class="td text-right">{{ $t['avg_group_attendance'] === null ? '—' : $t['avg_group_attendance'].'%' }}</td><td class="td text-right">{{ $t['cancelled'] }}</td><td class="td text-right">{{ money($t['salary'], false) }}</td></tr>
    @empty<tr><td colspan="8" class="px-3 py-8 text-center text-slate-400">—</td></tr>@endforelse
    </tbody></table></div>
@endsection
