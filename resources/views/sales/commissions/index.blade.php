@extends('layouts.app')
@section('title', t('sales.commissions.title'))
@section('content')
    @php
        $approver = can('commission.approve', 'ALL');
        $rows = $items->getCollection()->map(fn ($c) => ['id' => $c->id, 'status' => $c->status, 'amount' => (float) $c->amount_uzs])->values();
    @endphp
    <x-page-header :title="t('sales.commissions.title')" :subtitle="t('sales.commissions.subtitle')" />
    <div class="card overflow-hidden" x-data="{
            rows: @js($rows), selected: [],
            get chosen() { return this.rows.filter((r) => this.selected.includes(r.id)); },
            get sum() { return this.chosen.reduce((s, r) => s + r.amount, 0); },
            get allAccrued() { return this.chosen.length > 0 && this.chosen.every((r) => r.status === 'ACCRUED'); },
            get allApproved() { return this.chosen.length > 0 && this.chosen.every((r) => r.status === 'APPROVED'); },
            toggleAll(on) { this.selected = on ? this.rows.map((r) => r.id) : []; },
            fmt(v) { return v.toLocaleString('ru-RU', { maximumFractionDigits: 2 }) + ' UZS'; },
        }">
        <form method="GET" class="flex flex-col gap-3 border-b p-4 sm:flex-row sm:flex-wrap sm:items-end sm:justify-between">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                <div><label class="label" for="cm-period">{{ t('sales.commissions.period') }}</label><input id="cm-period" type="month" name="period" class="input sm:w-44" value="{{ $f['period'] ?? '' }}"></div>
                <x-select name="status" class="sm:w-48" :label="t('sales.status')" :placeholder="t('sales.commissions.allStatuses')" :value="$f['status'] ?? ''"
                          :options="collect(\App\Services\Sales\CommissionsService::STATUSES)->mapWithKeys(fn ($s) => [$s => t('sales.commissionStatus.'.$s)])->all()" />
                @if (! empty($f['user_id']))<input type="hidden" name="user_id" value="{{ $f['user_id'] }}">@endif
                <button class="btn btn-outline">{{ t('common.filter') }}</button>
            </div>
            <p class="text-left sm:text-right">
                <span class="block text-xs text-muted-foreground">{{ t('sales.commissions.total') }}</span>
                <span class="text-xl font-semibold tabular-nums">{{ money($totalUzs) }}</span>
            </p>
        </form>
        @if ($approver)
            <form method="POST" x-show="chosen.length > 0" x-cloak class="flex flex-wrap items-center gap-3 border-b bg-muted/40 px-4 py-2 text-sm">
                @csrf
                <template x-for="id in selected" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
                <span x-text="@js(t('sales.commissions.selectedShort')).replace('#', chosen.length) + ' · ' + fmt(sum)"></span>
                <button class="btn btn-primary btn-sm" formaction="{{ route('sales.commissions.approve') }}" :disabled="!allAccrued">{{ t('sales.commissions.approve') }}</button>
                <button class="btn btn-outline btn-sm" formaction="{{ route('sales.commissions.pay') }}" :disabled="!allApproved">{{ t('sales.commissions.pay') }}</button>
            </form>
        @endif
        @if ($items->isEmpty())
            <x-empty icon="dollar" :title="t('sales.commissions.empty')">{{ t('sales.commissions.emptyText') }}</x-empty>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead><tr>
                        @if ($approver)<th class="w-10"><input type="checkbox" aria-label="{{ t('sales.commissions.selectAll') }}" :checked="selected.length === rows.length" x-on:change="toggleAll($event.target.checked)"></th>@endif
                        <th>{{ t('sales.employee') }}</th><th>{{ t('sales.commissions.dealPayment') }}</th><th>{{ t('sales.commissions.rule') }}</th>
                        <th class="text-right">{{ t('sales.commissions.base') }}</th><th class="text-right">{{ t('sales.commissions.rate') }}</th><th class="text-right">{{ t('sales.payments.amount') }}</th><th>{{ t('sales.status') }}</th>
                    </tr></thead>
                    <tbody>
                        @foreach ($items as $c)
                            <tr>
                                @if ($approver)<td><input type="checkbox" value="{{ $c->id }}" x-model="selected" aria-label="{{ t('sales.commissions.select', ['name' => $c->user?->full_name, 'number' => \App\Services\Sales\Numbers::payment($c->payment?->number)]) }}"></td>@endif
                                <td>{{ $c->user?->full_name }}<span class="block text-xs text-muted-foreground">{{ t('sales.commissions.role.'.$c->role) }}</span></td>
                                <td><a href="{{ url('/sales/deals/'.$c->deal_id) }}" class="hover:underline">{{ \App\Services\Sales\Numbers::deal($c->deal?->number) }}</a><span class="block text-xs text-muted-foreground">{{ \App\Services\Sales\Numbers::payment($c->payment?->number) }} · {{ $c->period }}</span></td>
                                <td class="max-w-64 text-muted-foreground">{{ $c->rule?->name }}</td>
                                <td class="whitespace-nowrap text-right tabular-nums">{{ money($c->base_amount_uzs) }}</td>
                                <td class="text-right tabular-nums">{{ \App\Services\Sales\Numbers::rate($c->rate) }}{{ $c->rule?->calc_type === 'FIXED_PER_DEAL' ? '' : '%' }}</td>
                                <td @class(['whitespace-nowrap text-right font-semibold tabular-nums', 'text-danger' => (float) $c->amount_uzs < 0])>{{ money($c->amount_uzs) }}</td>
                                <td>@include('sales.partials.badge', ['kind' => 'commissionStatus', 'status' => $c->status])@if ($c->approvedBy)<span class="block text-xs text-muted-foreground">{{ $c->approvedBy->full_name }}</span>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t px-3 py-2">{{ $items->links('vendor.pagination.fluggi') }}</div>
        @endif
    </div>
    <p class="mt-3 text-xs text-muted-foreground">
        {{ t('sales.commissions.rulesHint') }}
        @if (can('commission_rule.manage', 'ALL'))<a href="{{ route('sales.commission-rules.index') }}" class="link">{{ t('sales.commissions.rulesLink') }}</a>@endif
    </p>
@endsection
