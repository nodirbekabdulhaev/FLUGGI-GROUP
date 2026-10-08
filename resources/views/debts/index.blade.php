@extends('layouts.app')
@section('title', __('Должники'))
@section('actions')
    <a href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}" class="btn-secondary"><x-icon name="download" class="h-4 w-4" /> CSV</a>
    <a href="{{ request()->fullUrlWithQuery(['export' => 'pdf']) }}" class="btn-secondary">PDF</a>
@endsection
@section('content')
@php $sortLink = fn($col) => request()->fullUrlWithQuery(['sort' => $col, 'dir' => ($sort === $col && $dir === 'desc') ? 'asc' : 'desc']); @endphp
<div class="mb-3 grid grid-cols-2 gap-3 md:grid-cols-4"><x-stat :label="__('Общий долг')" :value="money($total)" tone="bad" /><x-stat :label="__('Должников')" :value="$count" /></div>
<div class="card">
    <form method="GET" class="grid gap-2 border-b p-3 dark:border-slate-800 sm:grid-cols-3 lg:grid-cols-6">
        <input type="search" name="q" value="{{ request('q') }}" class="input" placeholder="{{ __('Ученик / телефон') }}">
        <select name="course_id" class="input"><option value="">{{ __('Все курсы') }}</option>@foreach($courses as $id => $n)<option value="{{ $id }}" @selected(request('course_id') == $id)>{{ $n }}</option>@endforeach</select>
        <select name="group_id" class="input"><option value="">{{ __('Все группы') }}</option>@foreach($groups as $id => $n)<option value="{{ $id }}" @selected(request('group_id') == $id)>{{ $n }}</option>@endforeach</select>
        <input type="number" name="min" value="{{ request('min') }}" class="input" placeholder="{{ __('Долг от') }}">
        <input type="date" name="from" value="{{ request('from') }}" class="input" title="{{ __('След. оплата с') }}"><input type="date" name="to" value="{{ request('to') }}" class="input" title="{{ __('След. оплата по') }}">
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="overdue" value="1" @checked(request()->boolean('overdue')) class="rounded border-slate-300 text-brand-600"> {{ __('Просроченные') }}</label>
        <div class="flex gap-2"><button class="btn-secondary">{{ __('Применить') }}</button>@if(request()->query())<a href="{{ route('debts.index') }}" class="btn-ghost">{{ __('Сбросить') }}</a>@endif</div>
    </form>
    <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
        <thead class="bg-slate-50 dark:bg-slate-800/50"><tr><th class="th">{{ __('Ученик') }}</th><th class="th">{{ __('Телефон') }}</th><th class="th">{{ __('Родитель') }}</th><th class="th">{{ __('Группа') }}</th><th class="th text-right"><a href="{{ $sortLink('balance') }}">{{ __('Долг') }}</a></th><th class="th"><a href="{{ $sortLink('last_payment_at') }}">{{ __('Последняя оплата') }}</a></th><th class="th"><a href="{{ $sortLink('next_payment_date') }}">{{ __('След. оплата') }}</a></th><th class="th">{{ __('Менеджер') }}</th><th class="th">{{ __('Филиал') }}</th><th class="th"></th></tr></thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
        @forelse($debts as $d)
            @php $over = $d->next_payment_date && $d->next_payment_date->isPast(); @endphp
            <tr class="hover:bg-slate-50/60 dark:hover:bg-slate-800/40">
                <td class="td"><a class="link font-medium" href="{{ route('students.show', $d->student_id) }}">{{ $d->student?->full_name }}</a></td>
                <td class="td whitespace-nowrap">{{ $d->student?->phone }}</td>
                <td class="td">{{ $d->student?->guardians->first()?->full_name }} <span class="text-xs text-slate-400">{{ $d->student?->guardians->first()?->phone }}</span></td>
                <td class="td">{{ $d->group?->name }}</td>
                <td class="td whitespace-nowrap text-right font-semibold text-rose-600">{{ money($d->balance, false) }}</td>
                <td class="td whitespace-nowrap">{{ fdate($d->last_payment_at) }}</td>
                <td class="td whitespace-nowrap {{ $over ? 'font-medium text-rose-600' : '' }}">{{ fdate($d->next_payment_date) }}</td>
                <td class="td">{{ $d->student?->manager?->name }}</td><td class="td">{{ $d->branch?->name }}</td>
                <td class="td text-right">@can('payments.create')<a href="{{ route('payments.create', ['student_id' => $d->student_id]) }}" class="btn-secondary btn-sm">{{ __('Оплата') }}</a>@endcan</td>
            </tr>
        @empty<tr><td colspan="10" class="px-3 py-10 text-center text-sm text-slate-400">🎉 {{ __('Должников нет') }}</td></tr>@endforelse
        </tbody></table></div>
    <div class="border-t p-3 dark:border-slate-800">{{ $debts->links() }}</div>
</div>
@endsection
