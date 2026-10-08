@extends('layouts.app')
@section('title', __('Лиды'))
@section('actions')
    <a href="{{ route('leads.funnel') }}" class="btn-secondary">{{ __('Воронка') }}</a>
    <a href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}" class="btn-secondary"><x-icon name="download" class="h-4 w-4" /> CSV</a>
    @can('leads.manage')<a href="{{ route('leads.create') }}" class="btn-primary"><x-icon name="plus" class="h-4 w-4" /> {{ __('Добавить лид') }}</a>@endcan
@endsection
@section('content')
@php
 $statuses = \App\Support\Lookup::statuses(); $sources = \App\Support\Lookup::sources(); $courses = \App\Support\Lookup::courses();
 $sortLink = fn($col) => request()->fullUrlWithQuery(['sort' => $col, 'dir' => ($sort === $col && $dir === 'desc') ? 'asc' : 'desc']);
@endphp
<div class="card">
    <form method="GET" class="grid gap-2 border-b border-slate-200 p-3 dark:border-slate-800 sm:grid-cols-3 lg:grid-cols-6">
        <input type="search" name="q" value="{{ request('q') }}" class="input sm:col-span-3 lg:col-span-2" placeholder="{{ __('Имя, телефон, ID, Telegram…') }}">
        <select name="status_id" class="input"><option value="">{{ __('Все статусы') }}</option>@foreach($statuses as $id => $n)<option value="{{ $id }}" @selected(request('status_id') == $id)>{{ $n }}</option>@endforeach</select>
        <select name="source_id" class="input"><option value="">{{ __('Все источники') }}</option>@foreach($sources as $id => $n)<option value="{{ $id }}" @selected(request('source_id') == $id)>{{ $n }}</option>@endforeach</select>
        <select name="course_id" class="input"><option value="">{{ __('Все курсы') }}</option>@foreach($courses as $id => $n)<option value="{{ $id }}" @selected(request('course_id') == $id)>{{ $n }}</option>@endforeach</select>
        @can('leads.view_all')<select name="manager_id" class="input"><option value="">{{ __('Все менеджеры') }}</option>@foreach(\App\Support\Lookup::managers() as $id => $n)<option value="{{ $id }}" @selected(request('manager_id') == $id)>{{ $n }}</option>@endforeach</select>@endcan
        <input type="date" name="from" value="{{ request('from') }}" class="input" title="{{ __('С даты') }}">
        <input type="date" name="to" value="{{ request('to') }}" class="input" title="{{ __('По дату') }}">
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="overdue" value="1" @checked(request()->boolean('overdue')) class="rounded border-slate-300 text-brand-600"> {{ __('Просрочен контакт') }}</label>
        <div class="flex gap-2"><button class="btn-secondary">{{ __('Применить') }}</button>@if(request()->query())<a href="{{ route('leads.index') }}" class="btn-ghost">{{ __('Сбросить') }}</a>@endif</div>
    </form>
    <div class="overflow-x-auto">
    <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
        <thead class="bg-slate-50 dark:bg-slate-800/50"><tr>
            <th class="th">ID</th><th class="th"><a href="{{ $sortLink('first_name') }}">{{ __('Имя') }}</a></th><th class="th">{{ __('Телефон') }}</th><th class="th">{{ __('Источник') }}</th>
            <th class="th">{{ __('Курс') }}</th><th class="th">{{ __('Филиал') }}</th><th class="th">{{ __('Менеджер') }}</th><th class="th">{{ __('Статус') }}</th>
            <th class="th"><a href="{{ $sortLink('next_contact_at') }}">{{ __('След. контакт') }}</a></th><th class="th"><a href="{{ $sortLink('created_at') }}">{{ __('Создан') }}</a></th>
        </tr></thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
        @forelse($leads as $l)
            <tr class="hover:bg-slate-50/60 dark:hover:bg-slate-800/40">
                <td class="td text-slate-400">#{{ $l->id }}</td>
                <td class="td"><a href="{{ route('leads.show', $l) }}" class="link font-medium">{{ $l->full_name }}</a></td>
                <td class="td whitespace-nowrap"><a href="tel:{{ $l->phone }}">{{ $l->phone }}</a></td>
                <td class="td">{{ $l->source?->name }}</td><td class="td">{{ $l->course?->name }}</td><td class="td">{{ $l->branch?->name }}</td><td class="td">{{ $l->manager?->name }}</td>
                <td class="td"><x-badge :color="$l->status?->color ?? 'slate'">{{ $l->status?->name }}</x-badge></td>
                <td class="td whitespace-nowrap {{ $l->next_contact_at && $l->next_contact_at->isPast() && ! $l->isConverted() ? 'font-medium text-rose-600' : '' }}">{{ $l->next_contact_at?->format('d.m H:i') ?? '—' }}</td>
                <td class="td whitespace-nowrap text-slate-500">{{ $l->created_at->format('d.m.Y') }}</td>
            </tr>
        @empty
            <tr><td colspan="10" class="px-3 py-10 text-center text-sm text-slate-400">{{ __('Нет данных') }}</td></tr>
        @endforelse
        </tbody>
    </table></div>
    <div class="border-t border-slate-200 p-3 dark:border-slate-800">{{ $leads->links() }}</div>
</div>
@endsection
