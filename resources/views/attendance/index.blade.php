@extends('layouts.app')
@section('title', __('Посещаемость'))
@section('content')
@php $ico = ['present' => ['✓', 'bg-emerald-100 text-emerald-700'], 'late' => ['⏱', 'bg-amber-100 text-amber-700'], 'absent' => ['✕', 'bg-rose-100 text-rose-700'], 'excused' => ['У', 'bg-blue-100 text-blue-700']]; @endphp
<form method="GET" class="mb-4 flex flex-wrap items-end gap-2">
    <select name="group_id" class="input !w-auto"><option value="">{{ __('Все группы') }}</option>@foreach($groups as $id => $n)<option value="{{ $id }}" @selected(request('group_id') == $id)>{{ $n }}</option>@endforeach</select>
    <select name="period" class="input !w-auto">@foreach(\App\Support\Period::LABELS as $k => $l)<option value="{{ $k }}" @selected($key === $k)>{{ __($l) }}</option>@endforeach</select>
    <input type="date" name="from" value="{{ $from->toDateString() }}" class="input !w-auto" title="{{ __('С (для произвольного периода)') }}"><input type="date" name="to" value="{{ $to->toDateString() }}" class="input !w-auto">
    <button class="btn-primary">{{ __('Показать') }}</button>
</form>

@if($matrix)
<div class="card overflow-x-auto">
    <table class="min-w-full text-sm">
        <thead class="bg-slate-50 dark:bg-slate-800/50"><tr><th class="th sticky left-0 bg-slate-50 dark:bg-slate-800">{{ __('Ученик') }}</th>
            @foreach($matrix['lessons'] as $l)<th class="th text-center"><a href="{{ route('lessons.show', $l) }}" class="hover:underline">{{ fdate($l->lesson_date, 'd.m') }}</a></th>@endforeach<th class="th text-center">%</th></tr></thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
        @foreach($matrix['students'] as $s)
            @php $rows = ($matrix['marks'][$s->id] ?? collect())->keyBy('lesson_id'); $tot = $rows->count(); $ok = $rows->whereIn('status', ['present', 'late'])->count(); @endphp
            <tr><td class="td sticky left-0 bg-white font-medium dark:bg-slate-900"><a class="link" href="{{ route('students.show', $s->id) }}">{{ $s->full_name }}</a></td>
                @foreach($matrix['lessons'] as $l)
                    @php $m = $rows[$l->id] ?? null; @endphp
                    <td class="px-1 py-1.5 text-center">@if($m)<span title="{{ $m->comment }}" class="inline-flex h-7 w-7 items-center justify-center rounded {{ $ico[$m->status][1] }}">{{ $ico[$m->status][0] }}</span>@else<span class="text-slate-300">·</span>@endif</td>
                @endforeach
                <td class="td text-center font-semibold">{{ $tot ? round($ok * 100 / $tot).'%' : '—' }}</td></tr>
        @endforeach
        </tbody></table>
    @if($matrix['lessons']->isEmpty())<div class="p-8 text-center text-sm text-slate-400">{{ __('Нет занятий в выбранном периоде') }}</div>@endif
</div>
@else
<div class="mb-3 text-sm text-slate-500">{{ __('Общая посещаемость за период') }}: <b class="text-slate-800 dark:text-slate-200">{{ $summary['percent'] === null ? '—' : $summary['percent'].'%' }}</b></div>
<div class="card overflow-x-auto"><table class="min-w-full text-sm">
    <thead class="bg-slate-50 dark:bg-slate-800/50"><tr><th class="th">{{ __('Группа') }}</th><th class="th text-right">{{ __('Занятий') }}</th><th class="th text-right">{{ __('Отметок') }}</th><th class="th text-right">{{ __('Был') }}</th><th class="th text-right">{{ __('Опоздал') }}</th><th class="th text-right">{{ __('Не был') }}</th><th class="th text-right">{{ __('Уваж.') }}</th><th class="th text-right">%</th></tr></thead>
    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
    @forelse($summary['groups'] as $g)
        <tr><td class="td"><a class="link font-medium" href="{{ route('attendance.index', ['group_id' => $g->id, 'period' => $key, 'from' => $from->toDateString(), 'to' => $to->toDateString()]) }}">{{ $g->name }}</a></td>
            <td class="td text-right">{{ $g->lessons }}</td><td class="td text-right">{{ $g->total }}</td><td class="td text-right">{{ $g->present }}</td><td class="td text-right">{{ $g->late }}</td><td class="td text-right">{{ $g->absent }}</td><td class="td text-right">{{ $g->excused }}</td>
            <td class="td text-right font-semibold {{ ($g->percent ?? 100) < 75 ? 'text-rose-600' : '' }}">{{ $g->percent === null ? '—' : $g->percent.'%' }}</td></tr>
    @empty<tr><td colspan="8" class="px-3 py-10 text-center text-slate-400">{{ __('Нет данных') }}</td></tr>@endforelse
    </tbody></table></div>
@endif
@endsection
