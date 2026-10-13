@extends('layouts.app')
@section('title', t('payroll.title'))
@section('content')
    @php
        use App\Services\People\PeopleService;
        use Brick\Math\BigDecimal;
        $tone = ['DRAFT' => 'gray', 'APPROVED' => 'blue', 'PAID' => 'green'];
        $total = $entries->reduce(fn ($s, $e) => $s->plus($e->final_salary), BigDecimal::zero());
        $num = fn ($v) => rtrim(rtrim((string) $v, '0'), '.');
    @endphp
    <x-page-header :title="t('payroll.title')" :subtitle="$manage ? t('payroll.subtitle') : t('payroll.ownSubtitle')">
        <form method="GET"><input type="month" name="period" value="{{ $period }}" class="input w-44" aria-label="{{ t('payroll.period') }}" onchange="this.form.submit()"></form>
        @if ($manage)
            <form method="POST" action="/finance/payroll/calculate">@csrf<input type="hidden" name="period" value="{{ $period }}">
                <button class="btn btn-primary"><x-icon name="refresh" /> {{ t('payroll.calculate') }}</button></form>
        @endif
    </x-page-header>
    <div class="card" x-data="{ selected: [] }">
        @if ($manage && $entries->isNotEmpty())
            <div class="flex flex-wrap items-center gap-3 border-b bg-muted/40 px-4 py-2 text-sm" x-show="selected.length" x-cloak>
                <span x-text="selected.length"></span>
                <form method="POST" action="/finance/payroll/approve">@csrf
                    <template x-for="id in selected" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
                    <button class="btn btn-primary btn-sm">{{ t('payroll.approve') }}</button></form>
                <form method="POST" action="/finance/payroll/pay">@csrf
                    <template x-for="id in selected" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
                    <button class="btn btn-outline btn-sm">{{ t('payroll.pay') }}</button></form>
            </div>
        @endif
        @if ($entries->isEmpty())
            <x-empty icon="wallet" :title="t('payroll.empty')">{{ $manage ? t('payroll.emptyCeo') : t('payroll.emptyOwn') }}</x-empty>
        @else
            <div class="overflow-x-auto"><table class="table">
                <thead><tr>
                    @if ($manage)<th class="w-10"><input type="checkbox" aria-label="{{ t('payroll.selectAll') }}" @change="selected = $event.target.checked ? @js($entries->pluck('id')) : []"></th>@endif
                    <th>{{ t('payroll.employee') }}</th>
                    @foreach (['base', 'pieceRate', 'kpiBonus', 'commission', 'otherBonus', 'penalty', 'final'] as $k)<th class="text-right">{{ t('payroll.'.$k) }}</th>@endforeach
                    <th>{{ t('payroll.statusLabel') }}</th>@if ($manage)<th></th>@endif
                </tr></thead>
                <tbody>
                @foreach ($entries as $e)
                    <tr>
                        @if ($manage)<td><input type="checkbox" value="{{ $e->id }}" x-model="selected" aria-label="{{ t('payroll.select', ['name' => $e->user?->full_name]) }}"></td>@endif
                        <td><span class="font-medium">{{ $e->user?->full_name }}</span><span class="block text-xs text-muted-foreground">{{ $e->period }}@if ($e->kpi_pct !== null) · {{ t('payroll.kpi') }} {{ $e->kpi_pct }}%@endif</span></td>
                        <td class="whitespace-nowrap text-right tabular-nums">
                            @if (PeopleService::hasFixedSalary($e->user?->role?->code ?? ''))
                                {{ money($e->base_salary) }}
                                @if ($e->attendance_pct !== null && $e->base_full !== null)
                                    <span class="block text-xs text-muted-foreground">{{ t('payroll.attendanceOf', ['pct' => $num($e->attendance_pct), 'full' => money($e->base_full)]) }}</span>
                                @endif
                            @else
                                —
                            @endif
                        </td>
                        <td class="whitespace-nowrap text-right tabular-nums">{{ (float) $e->piece_rate ? money($e->piece_rate) : '—' }}</td>
                        <td class="whitespace-nowrap text-right tabular-nums">{{ money($e->kpi_bonus) }}</td>
                        <td class="whitespace-nowrap text-right tabular-nums">{{ money($e->commission) }}</td>
                        <td class="whitespace-nowrap text-right tabular-nums">{{ money($e->other_bonus) }}</td>
                        <td @class(['whitespace-nowrap text-right tabular-nums', 'text-danger' => (float) $e->penalty])>{{ (float) $e->penalty ? '− '.money($e->penalty) : money(0) }}</td>
                        <td class="whitespace-nowrap text-right font-semibold tabular-nums">{{ money($e->final_salary) }}</td>
                        <td><x-badge :color="$tone[$e->status]">{{ t('payroll.status.'.$e->status) }}</x-badge></td>
                        @if ($manage)<td class="text-right">@if ($e->status === 'DRAFT')<a href="/finance/payroll?period={{ $period }}&edit={{ $e->id }}" class="btn btn-ghost btn-icon" aria-label="{{ t('payroll.edit') }}"><x-icon name="edit" /></a>@endif</td>@endif
                    </tr>
                @endforeach
                </tbody>
            </table></div>
            @if ($entries->count() > 1)<p class="border-t px-4 py-3 text-right text-sm">{{ t('payroll.total') }}: <span class="font-semibold tabular-nums">{{ money($total) }}</span></p>@endif
        @endif
    </div>
    @if ($manage)<p class="mt-3 text-xs text-muted-foreground">{{ t('payroll.calculateHint') }}</p>@endif

    @if ($manage)
        {{-- Оклады при 100 % посещаемости: при расчёте месяца умножаются на посещаемость (полдня = 50 %) --}}
        <x-card class="mt-6" :title="t('payroll.salariesTitle')" :subtitle="t('payroll.salariesHint')">
            @if ($salaries->isEmpty())
                <x-empty icon="users" :title="t('payroll.salariesEmpty')" />
            @else
                <form method="POST" action="{{ route('payroll.salaries') }}" class="space-y-3">
                    @csrf @method('PUT')
                    <input type="hidden" name="period" value="{{ $period }}">
                    <div class="divide-y">
                        @foreach ($salaries as $u)
                            <div class="flex flex-col gap-2 py-2 sm:flex-row sm:items-center sm:justify-between">
                                <div class="min-w-0"><span class="font-medium">{{ $u->full_name }}</span>
                                    <span class="block text-xs text-muted-foreground">{{ t('roles.'.$u->role->code) }}</span></div>
                                <input name="salaries[{{ $u->id }}]" inputmode="decimal" class="input w-full sm:w-48 text-right tabular-nums"
                                       value="{{ old('salaries.'.$u->id, $u->employee?->base_salary !== null ? $num($u->employee->base_salary) : '') }}"
                                       placeholder="4000000" aria-label="{{ t('payroll.salaryFor', ['name' => $u->full_name]) }}">
                            </div>
                        @endforeach
                    </div>
                    @error('salaries.*')<p class="error">{{ $message }}</p>@enderror
                    <div class="flex justify-end"><button class="btn btn-primary">{{ t('common.save') }}</button></div>
                </form>
            @endif
        </x-card>
    @endif

    @isset($editing)
        @php($fixed = PeopleService::hasFixedSalary($editing->user->role->code))
        <x-modal name="payroll-edit" :title="t('payroll.editTitle', ['period' => $editing->period, 'name' => $editing->user->full_name])" :open="true">
            <form method="POST" action="/finance/payroll/{{ $editing->id }}" class="space-y-4">
                @csrf @method('PATCH')
                <div class="grid gap-4 sm:grid-cols-2">
                    @if ($fixed)
                        <x-field name="base_salary" inputmode="decimal" :label="t('payroll.base')" :value="$num($editing->base_salary)" />
                    @else
                        <x-field name="piece_rate_view" :label="t('payroll.pieceRate')" :hint="t('payroll.pieceRateHint')" :value="money($editing->piece_rate)" disabled />
                    @endif
                    <x-field name="kpi_bonus_target" inputmode="decimal" :label="t('payroll.kpiBonusTarget')" :hint="t('payroll.kpiBonusTargetHint')" :value="$editing->user->employee?->kpi_bonus_target !== null ? $num($editing->user->employee->kpi_bonus_target) : null" />
                    <x-field name="kpi_bonus" inputmode="decimal" :label="t('payroll.kpiBonus').($editing->kpi_pct !== null ? ' (KPI '.$editing->kpi_pct.'%)' : '')" :hint="t('payroll.kpiBonusAutoHint')" :value="$num($editing->kpi_bonus)" />
                    <x-field name="commission_view" :label="t('payroll.commission')" :value="money($editing->commission)" disabled />
                    <x-field name="other_bonus" inputmode="decimal" :label="t('payroll.otherBonus')" :value="$num($editing->other_bonus)" />
                    <x-field name="penalty" inputmode="decimal" :label="t('payroll.penalty')" :value="$num($editing->penalty)" />
                    <x-field name="comment" class="sm:col-span-2" :label="t('payroll.comment')" :value="$editing->comment" />
                </div>
                @if ($fixed)
                    <p class="text-xs text-muted-foreground">{{ t('payroll.baseManualHint') }}</p>
                @else
                    <input type="hidden" name="base_salary" value="0">
                    <p class="text-xs text-muted-foreground">{{ t('payroll.noFixedHint') }}</p>
                @endif
                <div class="flex justify-end gap-2">
                    <a href="/finance/payroll?period={{ $period }}" class="btn btn-outline">{{ t('common.cancel') }}</a>
                    <button class="btn btn-primary">{{ t('common.save') }}</button>
                </div>
            </form>
        </x-modal>
    @endisset
@endsection
