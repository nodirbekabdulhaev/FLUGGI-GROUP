@extends('layouts.app')
@section('title', __('Зарплаты'))
@section('content')
<div class="mb-3 grid grid-cols-2 gap-3 md:grid-cols-4">
    <x-stat :label="__('Начислено всего')" :value="money($totals['accrued'])" /><x-stat :label="__('Выплачено')" :value="money($totals['paid'])" tone="good" /><x-stat :label="__('Задолженность')" :value="money($totals['debt'])" :tone="$totals['debt'] > 0 ? 'bad' : null" />
</div>
<div class="card">
    <div class="flex flex-wrap items-end justify-between gap-2 border-b p-3 dark:border-slate-800">
        <form method="GET" class="flex items-end gap-2"><div><label class="label">{{ __('Период') }}</label><input type="month" name="period" value="{{ $period }}" onchange="this.form.submit()" class="input !w-auto"></div></form>
        @can('salaries.manage')
        <form method="POST" action="{{ route('salaries.accrue') }}" onsubmit="return confirm('{{ __('Рассчитать начисления за') }} {{ $period }}? {{ __('Существующие будут пересчитаны.') }}')">@csrf<input type="hidden" name="period" value="{{ $period }}"><button class="btn-primary">{{ __('Начислить за') }} {{ $period }}</button></form>
        @endcan
    </div>
    <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
        <thead class="bg-slate-50 dark:bg-slate-800/50"><tr><th class="th">{{ __('Сотрудник') }}</th><th class="th">{{ __('Схема') }}</th><th class="th text-right">{{ __('За') }} {{ $period }}</th><th class="th text-right">{{ __('Начислено всего') }}</th><th class="th text-right">{{ __('Выплачено') }}</th><th class="th text-right">{{ __('Долг') }}</th><th class="th"></th></tr></thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
        @forelse($rows as $r)
            @php $m = $r['model']; @endphp
            <tr x-data="{ pay: false }">
                <td class="td"><div class="font-medium">{{ $m->full_name }}</div><div class="text-xs text-slate-500">{{ __($r['label']) }}</div></td>
                <td class="td text-xs text-slate-600">{{ __(\App\Models\Teacher::PAY_TYPES[$m->pay_type] ?? 'Оклад') }}: {{ number_format($m->rate, 0, '.', ' ') }}{{ $m->pay_type === 'percent' ? '%' : '' }}
                    @if($r['period'])<div class="text-slate-400">{{ $r['period']->note }}</div>@endif</td>
                <td class="td text-right">{{ $r['period'] ? money($r['period']->amount, false) : '—' }}</td>
                <td class="td text-right">{{ money($r['accrued'], false) }}</td><td class="td text-right text-emerald-600">{{ money($r['paid'], false) }}</td>
                <td class="td text-right font-semibold {{ $r['debt'] > 0 ? 'text-rose-600' : 'text-slate-400' }}">{{ money($r['debt'], false) }}</td>
                <td class="td text-right">@can('salaries.manage')
                    <button @click="pay = !pay" class="btn-secondary btn-sm">{{ __('Выплатить') }}</button>
                    <form x-cloak x-show="pay" method="POST" action="{{ route('salaries.pay') }}" class="mt-2 grid min-w-64 gap-1.5 text-left">@csrf
                        <input type="hidden" name="kind" value="{{ $r['kind'] }}"><input type="hidden" name="id" value="{{ $m->id }}">
                        <input type="number" name="amount" min="1" value="{{ max(0, round($r['debt'])) ?: '' }}" class="input" required placeholder="{{ __('Сумма') }}">
                        <select name="method_id" class="input">@foreach($methods as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>
                        <input type="date" name="paid_at" value="{{ today()->toDateString() }}" class="input"><input name="comment" class="input" placeholder="{{ __('Комментарий') }}">
                        <button class="btn-primary btn-sm">{{ __('Записать выплату') }}</button></form>@endcan</td>
            </tr>
        @empty<tr><td colspan="7" class="px-3 py-10 text-center text-slate-400">{{ __('Нет сотрудников') }}</td></tr>@endforelse
        </tbody></table></div>
</div>
<div class="card mt-4"><div class="border-b p-4 text-sm font-semibold dark:border-slate-800">{{ __('Последние выплаты') }}</div>
    <ul class="divide-y text-sm dark:divide-slate-800">@forelse($payments as $p)<li class="flex justify-between px-4 py-2.5"><span>{{ fdate($p->paid_at) }} · {{ $p->payable?->full_name }} <span class="text-slate-400">{{ $p->comment }}</span></span><b>{{ money($p->amount) }}</b></li>@empty<li class="p-6 text-center text-slate-400">—</li>@endforelse</ul></div>
<p class="mt-3 max-w-3xl text-xs text-slate-500">{{ __('Выплата автоматически попадает в расходы (категория «Зарплата») и уменьшает прибыль. Схемы: фикс, за занятие, за ученика, процент от оплат по группам преподавателя.') }}</p>
@endsection
