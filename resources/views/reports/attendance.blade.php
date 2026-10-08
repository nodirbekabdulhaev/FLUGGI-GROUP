@extends('layouts.app')
@section('title', __('Аналитика посещаемости'))
@section('actions')@include('reports._bar', ['type' => 'attendance'])@endsection
@section('content')
@include('partials.period')
<div class="mb-3 grid grid-cols-2 gap-3 md:grid-cols-4"><x-stat :label="__('Посещаемость')" :value="$d['percent'] === null ? '—' : $d['percent'].'%'" /></div>
@foreach([[__('Группы'), $d['groups']], [__('Преподаватели'), $d['teachers']]] as [$title, $rows])
<h2 class="mb-2 mt-5 text-sm font-semibold">{{ $title }}</h2>
<div class="card overflow-x-auto"><table class="min-w-full text-sm">
    <thead class="bg-slate-50 dark:bg-slate-800/50"><tr><th class="th">{{ __('Название') }}</th><th class="th text-right">{{ __('Занятий') }}</th><th class="th text-right">{{ __('Был') }}</th><th class="th text-right">{{ __('Опоздал') }}</th><th class="th text-right">{{ __('Не был') }}</th><th class="th text-right">{{ __('Уваж.') }}</th><th class="th text-right">%</th></tr></thead>
    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
    @forelse($rows as $g)<tr><td class="td font-medium">{{ $g->name }}</td><td class="td text-right">{{ $g->lessons }}</td><td class="td text-right">{{ $g->present }}</td><td class="td text-right">{{ $g->late }}</td><td class="td text-right">{{ $g->absent }}</td><td class="td text-right">{{ $g->excused }}</td><td class="td text-right font-semibold {{ ($g->percent ?? 100) < 75 ? 'text-rose-600' : '' }}">{{ $g->percent === null ? '—' : $g->percent.'%' }}</td></tr>
    @empty<tr><td colspan="7" class="px-3 py-6 text-center text-slate-400">—</td></tr>@endforelse
    </tbody></table></div>
@endforeach
<h2 class="mb-2 mt-5 text-sm font-semibold">{{ __('Ученики (пропуски и опоздания)') }}</h2>
<div class="card overflow-x-auto"><table class="min-w-full text-sm">
    <thead class="bg-slate-50 dark:bg-slate-800/50"><tr><th class="th">{{ __('Ученик') }}</th><th class="th text-right">{{ __('Отметок') }}</th><th class="th text-right">{{ __('Не был') }}</th><th class="th text-right">{{ __('Опоздал') }}</th><th class="th text-right">%</th></tr></thead>
    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
    @foreach($d['students']->sortBy('percent')->take(50) as $s)<tr><td class="td"><a class="link" href="{{ route('students.show', $s->id) }}">{{ trim($s->first_name.' '.$s->last_name) }}</a></td><td class="td text-right">{{ $s->total }}</td><td class="td text-right">{{ $s->absent }}</td><td class="td text-right">{{ $s->late }}</td><td class="td text-right font-semibold {{ ($s->percent ?? 100) < 75 ? 'text-rose-600' : '' }}">{{ $s->percent === null ? '—' : $s->percent.'%' }}</td></tr>@endforeach
    </tbody></table></div>
@endsection
