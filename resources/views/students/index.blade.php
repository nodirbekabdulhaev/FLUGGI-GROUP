@extends('layouts.app')
@section('title', __('Ученики'))
@section('actions')
    <a href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}" class="btn-secondary"><x-icon name="download" class="h-4 w-4" /> CSV</a>
    @can('students.manage')<a href="{{ route('students.create') }}" class="btn-primary"><x-icon name="plus" class="h-4 w-4" /> {{ __('Добавить ученика') }}</a>@endcan
@endsection
@section('content')
@php $sortLink = fn($col) => request()->fullUrlWithQuery(['sort' => $col, 'dir' => ($sort === $col && $dir === 'desc') ? 'asc' : 'desc']); @endphp
<div class="card" x-data="{ f: window.innerWidth >= 768 }">
    <button type="button" @click="f = !f" class="flex w-full items-center justify-between border-b px-4 py-3 text-sm font-medium md:hidden dark:border-slate-800">{{ __('Фильтры') }} <span x-text="f ? '▲' : '▼'"></span></button>
    <form method="GET" x-show="f" class="grid gap-2 border-b border-slate-200 p-3 dark:border-slate-800 sm:grid-cols-3 lg:grid-cols-6">
        <input type="search" name="q" value="{{ request('q') }}" class="input sm:col-span-3 lg:col-span-2" placeholder="{{ __('ФИО, телефон, ID, Telegram…') }}">
        <select name="status" class="input"><option value="">{{ __('Все статусы') }}</option>@foreach(\App\Models\Student::STATUSES as $k => $v)<option value="{{ $k }}" @selected(request('status') === $k)>{{ __($v) }}</option>@endforeach</select>
        <select name="course_id" class="input"><option value="">{{ __('Все курсы') }}</option>@foreach(\App\Support\Lookup::courses() as $id => $n)<option value="{{ $id }}" @selected(request('course_id') == $id)>{{ $n }}</option>@endforeach</select>
        <select name="group_id" class="input"><option value="">{{ __('Все группы') }}</option>@foreach(\App\Support\Lookup::groups() as $id => $n)<option value="{{ $id }}" @selected(request('group_id') == $id)>{{ $n }}</option>@endforeach</select>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="debtors" value="1" @checked(request()->boolean('debtors')) class="rounded border-slate-300 text-brand-600"> {{ __('Только должники') }}</label>
        <input type="date" name="from" value="{{ request('from') }}" class="input" title="{{ __('Регистрация с') }}">
        <input type="date" name="to" value="{{ request('to') }}" class="input" title="{{ __('Регистрация по') }}">
        <div class="flex gap-2"><button class="btn-secondary">{{ __('Применить') }}</button>@if(request()->query())<a href="{{ route('students.index') }}" class="btn-ghost">{{ __('Сбросить') }}</a>@endif</div>
    </form>
    <div class="overflow-x-auto">
    <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
        <thead class="bg-slate-50 dark:bg-slate-800/50"><tr>
            <th class="th"><a href="{{ $sortLink('id') }}">ID</a></th><th class="th"></th><th class="th"><a href="{{ $sortLink('first_name') }}">{{ __('ФИО') }}</a></th><th class="th">{{ __('Телефон') }}</th>
            <th class="th">{{ __('Родитель') }}</th><th class="th">{{ __('Курс') }}</th><th class="th">{{ __('Группа') }}</th><th class="th">{{ __('Филиал') }}</th>
            <th class="th text-right">{{ __('Баланс') }}</th><th class="th">{{ __('Посещ.') }}</th><th class="th"><a href="{{ $sortLink('status') }}">{{ __('Статус') }}</a></th>
        </tr></thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
        @forelse($students as $s)
            @php $balance = $s->balance; $att = $s->att_total ? round($s->att_ok * 100 / $s->att_total) : null; $e = $s->activeEnrollment; @endphp
            <tr class="hover:bg-slate-50/60 dark:hover:bg-slate-800/40">
                <td class="td text-slate-400">#{{ $s->id }}</td>
                <td class="td w-10">@if($s->photo_path)<img src="{{ route('files.show', ['student-photo', $s->id]) }}" class="h-8 w-8 rounded-full object-cover" alt="" loading="lazy">@else<div class="flex h-8 w-8 items-center justify-center rounded-full bg-brand-100 text-xs font-semibold text-brand-700">{{ mb_strtoupper(mb_substr($s->first_name, 0, 1)) }}</div>@endif</td>
                <td class="td"><a href="{{ route('students.show', $s) }}" class="link font-medium">{{ $s->full_name }}</a></td>
                <td class="td whitespace-nowrap">{{ $s->phone }}</td>
                <td class="td">{{ $s->guardians->first()?->full_name }}</td>
                <td class="td">{{ $e?->group?->course?->name }}</td>
                <td class="td">{{ $e?->group?->name }}</td>
                <td class="td">{{ $s->branch?->name }}</td>
                <td class="td whitespace-nowrap text-right font-medium {{ $balance > 0 ? 'text-rose-600' : ($balance < 0 ? 'text-emerald-600' : 'text-slate-400') }}">{{ $balance > 0 ? '−'.money($balance, false) : ($balance < 0 ? '+'.money(-$balance, false) : '0') }}</td>
                <td class="td">{{ $att === null ? '—' : $att.'%' }}</td>
                <td class="td"><x-badge :color="['active'=>'emerald','frozen'=>'blue','completed'=>'indigo','expelled'=>'rose','archived'=>'slate'][$s->status] ?? 'slate'">{{ __(\App\Models\Student::STATUSES[$s->status] ?? $s->status) }}</x-badge></td>
            </tr>
        @empty
            <tr><td colspan="11" class="px-3 py-10 text-center text-sm text-slate-400">{{ __('Нет данных') }}</td></tr>
        @endforelse
        </tbody>
    </table></div>
    <div class="border-t border-slate-200 p-3 dark:border-slate-800">{{ $students->links() }}</div>
</div>
@endsection
