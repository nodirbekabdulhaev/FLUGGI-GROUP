@extends('layouts.app')
@section('title', t('accounting.payroll.title'))
@section('content')
    @php
        use Brick\Math\BigDecimal;
        $num = fn ($v) => rtrim(rtrim((string) $v, '0'), '.');
        $drafts = $rows->where('status', 'DRAFT')->count();
        $payUrl = fn ($r) => '/accounting/operations?'.http_build_query(array_filter(['new' => 'OUT', 'p_category' => 'SALARY', 'p_period' => $period, 'p_person' => $r->person_id,
            'p_amount' => BigDecimal::of($r->remaining)->isPositive() ? (string) $r->remaining : null, 'p_account' => $payAccount?->id]));
    @endphp
    <x-page-header :title="t('accounting.payroll.title')" :subtitle="t('accounting.payroll.subtitle')">
        <form method="GET"><input type="month" name="period" value="{{ $period }}" class="input w-44" aria-label="{{ t('accounting.fields.month') }}" onchange="this.form.submit()"></form>
        @if ($canManage)
            <form method="POST" action="/accounting/payroll/calculate">@csrf<input type="hidden" name="period" value="{{ $period }}">
                <button class="btn btn-outline"><x-icon name="refresh" /> {{ t('accounting.payroll.calculate') }}</button></form>
            @if ($drafts)
                <form method="POST" action="/accounting/payroll/approve" onsubmit="return confirm(@js(t('accounting.payroll.approveConfirm')))">@csrf<input type="hidden" name="period" value="{{ $period }}">
                    <button class="btn btn-primary"><x-icon name="check" /> {{ t('accounting.payroll.approveAll') }}</button></form>
            @endif
        @endif
    </x-page-header>
    @include('accounting.partials.tabs', ['active' => '/accounting/payroll'])

    <p class="mb-4 text-sm text-muted-foreground">{{ t('accounting.payroll.ratesLine', ['ndfl' => $settings['ndflRate'], 'inps' => $settings['inpsRate']]) }}
        {{ $settings['inpsWithinNdfl'] ? t('accounting.payroll.inpsWithin') : t('accounting.payroll.inpsOnTop') }}</p>

    @if ($rows->isEmpty())
        <div class="card"><x-empty icon="wallet" :title="t('accounting.payroll.empty')">{{ $canManage ? t('accounting.payroll.emptyText') : '' }}</x-empty></div>
    @else
        <x-table>
            <thead><tr>
                <th>{{ t('accounting.people.fullName') }}</th>
                @foreach (['gross', 'ndfl', 'inps', 'net', 'paid', 'remaining'] as $k)<th class="text-right">{{ t('accounting.payroll.'.$k) }}</th>@endforeach
                <th>{{ t('accounting.payroll.status') }}</th><th></th>
            </tr></thead>
            <tbody>
            @foreach ($rows as $r)
                <tr>
                    <td><span class="font-medium">{{ $r->person?->full_name }}</span>@if ($r->person?->position)<span class="block text-xs text-muted-foreground">{{ $r->person->position }}</span>@endif</td>
                    <td class="whitespace-nowrap text-right tabular-nums">{{ money($r->gross) }}</td>
                    <td class="whitespace-nowrap text-right tabular-nums">{{ money($r->ndfl) }}</td>
                    <td class="whitespace-nowrap text-right tabular-nums">{{ money($r->inps) }}</td>
                    <td class="whitespace-nowrap text-right font-medium tabular-nums">{{ money($r->net) }}</td>
                    <td class="whitespace-nowrap text-right tabular-nums">{{ money($r->paid) }}</td>
                    <td @class(['whitespace-nowrap text-right font-semibold tabular-nums', 'text-warning' => BigDecimal::of($r->remaining)->isPositive(), 'text-danger' => BigDecimal::of($r->remaining)->isNegative()])>{{ money($r->remaining) }}</td>
                    <td><x-badge :color="$r->status === 'APPROVED' ? 'green' : 'gray'">{{ t('accounting.payroll.statuses.'.$r->status) }}</x-badge></td>
                    <td class="whitespace-nowrap text-right">
                        @if ($canManage)
                            @if ($r->status === 'DRAFT')
                                <a href="/accounting/payroll?period={{ $period }}&edit={{ $r->id }}" class="btn btn-ghost btn-icon" aria-label="{{ t('common.edit') }}"><x-icon name="edit" /></a>
                                <form method="POST" action="/accounting/payroll/approve" class="inline">@csrf<input type="hidden" name="period" value="{{ $period }}"><input type="hidden" name="ids[]" value="{{ $r->id }}">
                                    <button class="btn btn-ghost btn-sm">{{ t('accounting.payroll.approve') }}</button></form>
                            @endif
                            @if (BigDecimal::of($r->remaining)->isPositive())
                                <a href="{{ $payUrl($r) }}" class="btn btn-outline btn-sm">{{ t('accounting.payroll.pay') }}</a>
                            @endif
                        @endif
                    </td>
                </tr>
            @endforeach
            <tr class="font-semibold">
                <td>{{ t('accounting.payroll.total') }}</td>
                @foreach (['gross', 'ndfl', 'inps', 'net', 'paid', 'remaining'] as $k)<td class="whitespace-nowrap text-right tabular-nums">{{ money($totals[$k]) }}</td>@endforeach
                <td colspan="2"></td>
            </tr>
            </tbody>
        </x-table>
    @endif

    @if ($paidOnly->isNotEmpty())
        <x-card class="mt-4" :title="t('accounting.payroll.paidWithoutAccrual')" :padding="false">
            <ul class="divide-y text-sm">
                @foreach ($paidOnly as $p)
                    <li class="flex justify-between gap-3 px-4 py-2.5"><span>{{ $p->full_name }}</span><span class="tabular-nums">{{ money($p->paid) }}</span></li>
                @endforeach
            </ul>
        </x-card>
    @endif
    <p class="mt-3 text-xs text-muted-foreground">{{ t('accounting.payroll.hint') }}</p>

    @if ($editing)
        <x-modal name="acc-payroll-edit" :title="t('accounting.payroll.editTitle', ['name' => $editing->person?->full_name, 'period' => $editing->period])" :open="true">
            <form method="POST" action="/accounting/payroll/{{ $editing->id }}" class="space-y-4">
                @csrf @method('PATCH')
                <x-field name="gross" inputmode="decimal" :label="t('accounting.payroll.gross')" :hint="t('accounting.payroll.grossHint', ['salary' => money($editing->person?->monthly_salary)])" :value="$num($editing->gross)" required />
                <div class="flex justify-end gap-2">
                    <a href="/accounting/payroll?period={{ $period }}" class="btn btn-outline">{{ t('common.cancel') }}</a>
                    <button class="btn btn-primary">{{ t('common.save') }}</button>
                </div>
            </form>
        </x-modal>
    @endif
@endsection
