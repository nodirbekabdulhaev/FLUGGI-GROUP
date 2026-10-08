@extends('layouts.app')
@section('title', __('Оплаты'))
@section('actions')
    <a href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}" class="btn-secondary"><x-icon name="download" class="h-4 w-4" /> CSV</a>
    <a href="{{ request()->fullUrlWithQuery(['export' => 'pdf']) }}" class="btn-secondary">PDF</a>
    @can('payments.create')<a href="{{ route('payments.create') }}" class="btn-primary"><x-icon name="plus" class="h-4 w-4" /> {{ __('Принять оплату') }}</a>@endcan
@endsection
@section('content')
@php $typeColor = ['payment' => 'emerald', 'refund' => 'rose', 'correction' => 'amber']; @endphp
<div class="mb-3 grid grid-cols-2 gap-3 md:grid-cols-4"><x-stat :label="__('Итого за период')" :value="money($total)" tone="good" :hint="$payments->total().' '.__('операций')" /></div>
<div class="card">
    <form method="GET" class="grid gap-2 border-b p-3 dark:border-slate-800 sm:grid-cols-3 lg:grid-cols-6" x-data="{ k: '{{ $key }}' }">
        <input type="search" name="q" value="{{ request('q') }}" class="input sm:col-span-2" placeholder="{{ __('Ученик: имя, телефон, ID') }}">
        <select name="period" x-model="k" class="input">@foreach(\App\Support\Period::LABELS as $pk => $pl)<option value="{{ $pk }}">{{ __($pl) }}</option>@endforeach</select>
        <input x-show="k === 'custom'" x-cloak type="date" name="from" value="{{ $from->toDateString() }}" class="input"><input x-show="k === 'custom'" x-cloak type="date" name="to" value="{{ $to->toDateString() }}" class="input">
        <select name="method_id" class="input"><option value="">{{ __('Все способы') }}</option>@foreach(\App\Support\Lookup::methods() as $id => $n)<option value="{{ $id }}" @selected(request('method_id') == $id)>{{ $n }}</option>@endforeach</select>
        <select name="type" class="input"><option value="">{{ __('Все типы') }}</option>@foreach(\App\Models\Payment::TYPES as $k => $v)<option value="{{ $k }}" @selected(request('type') === $k)>{{ __($v) }}</option>@endforeach</select>
        <select name="course_id" class="input"><option value="">{{ __('Все курсы') }}</option>@foreach(\App\Support\Lookup::courses() as $id => $n)<option value="{{ $id }}" @selected(request('course_id') == $id)>{{ $n }}</option>@endforeach</select>
        <select name="group_id" class="input"><option value="">{{ __('Все группы') }}</option>@foreach(\App\Support\Lookup::groups() as $id => $n)<option value="{{ $id }}" @selected(request('group_id') == $id)>{{ $n }}</option>@endforeach</select>
        <select name="cashier_id" class="input"><option value="">{{ __('Все кассиры') }}</option>@foreach($cashiers as $id => $n)<option value="{{ $id }}" @selected(request('cashier_id') == $id)>{{ $n }}</option>@endforeach</select>
        <input type="number" name="min" value="{{ request('min') }}" class="input" placeholder="{{ __('Сумма от') }}"><input type="number" name="max" value="{{ request('max') }}" class="input" placeholder="{{ __('до') }}">
        <div class="flex gap-2"><button class="btn-secondary">{{ __('Применить') }}</button>@if(request()->query())<a href="{{ route('payments.index') }}" class="btn-ghost">{{ __('Сбросить') }}</a>@endif</div>
    </form>
    <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
        <thead class="bg-slate-50 dark:bg-slate-800/50"><tr><th class="th">ID</th><th class="th">{{ __('Дата') }}</th><th class="th">{{ __('Ученик') }}</th><th class="th">{{ __('Группа') }}</th><th class="th">{{ __('Способ') }}</th><th class="th">{{ __('Филиал') }}</th><th class="th">{{ __('Кассир') }}</th><th class="th">{{ __('Комментарий') }}</th><th class="th text-right">{{ __('Сумма') }}</th><th class="th"></th></tr></thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
        @forelse($payments as $p)
            <tr class="hover:bg-slate-50/60 dark:hover:bg-slate-800/40">
                <td class="td text-slate-400">#{{ $p->id }}</td><td class="td whitespace-nowrap">{{ $p->paid_at->format('d.m.Y H:i') }}</td>
                <td class="td"><a class="link font-medium" href="{{ route('students.show', $p->student_id) }}">{{ $p->student?->full_name }}</a></td>
                <td class="td">{{ $p->group?->name }}</td><td class="td">{{ $p->method?->name }}</td><td class="td">{{ $p->branch?->name }}</td><td class="td">{{ $p->cashier?->name }}</td><td class="td text-slate-500">{{ $p->comment }}</td>
                <td class="td whitespace-nowrap text-right font-semibold {{ $p->type === 'refund' ? 'text-rose-600' : 'text-emerald-600' }}">{{ $p->type === 'refund' ? '−' : '' }}{{ money($p->amount, false) }} @if($p->type !== 'payment')<x-badge :color="$typeColor[$p->type]">{{ __(\App\Models\Payment::TYPES[$p->type]) }}</x-badge>@endif</td>
                <td class="td whitespace-nowrap text-right">@can('payments.correct')<a href="{{ route('payments.edit', $p) }}" class="btn-ghost btn-sm"><x-icon name="edit" class="h-4 w-4" /></a>
                    <form method="POST" action="{{ route('payments.destroy', $p) }}" class="inline" onsubmit="return confirm('{{ __('Удалить оплату? Долг будет пересчитан.') }}')">@csrf @method('DELETE')<button class="btn-ghost btn-sm text-rose-600"><x-icon name="trash" class="h-4 w-4" /></button></form>@endcan</td>
            </tr>
        @empty<tr><td colspan="10" class="px-3 py-10 text-center text-sm text-slate-400">{{ __('Нет данных') }}</td></tr>@endforelse
        </tbody></table></div>
    <div class="border-t p-3 dark:border-slate-800">{{ $payments->links() }}</div>
</div>
@endsection
