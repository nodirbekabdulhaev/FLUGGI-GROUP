@extends('layouts.app')
@section('title', t('sales.payments.title'))
@section('content')
    <x-page-header :title="t('sales.payments.title')" :subtitle="t('sales.payments.subtitle')" />
    <div class="card overflow-hidden">
        @component('sales.partials.filters', ['filtered' => collect($f)->filter()->isNotEmpty()])
            <x-select name="status" class="sm:w-52" :label="t('sales.status')" :placeholder="t('sales.allStatuses')" :value="$f['status'] ?? ''"
                      :options="collect(\App\Services\Sales\PaymentsService::STATUSES)->mapWithKeys(fn ($s) => [$s => t('sales.paymentStatus.'.$s)])->all()" />
            <x-select name="type" class="sm:w-48" :label="t('sales.payments.type')" :placeholder="t('sales.payments.all')" :value="$f['type'] ?? ''"
                      :options="collect(\App\Services\Sales\PaymentsService::TYPES)->mapWithKeys(fn ($s) => [$s => t('sales.paymentType.'.$s)])->all()" />
            <div><label class="label" for="pf-from">{{ t('sales.dateFrom') }}</label><input id="pf-from" type="date" name="date_from" class="input" value="{{ $f['date_from'] ?? '' }}"></div>
            <div><label class="label" for="pf-to">{{ t('sales.dateTo') }}</label><input id="pf-to" type="date" name="date_to" class="input" value="{{ $f['date_to'] ?? '' }}"></div>
        @endcomponent
        @if ($items->isEmpty())
            <x-empty icon="wallet" :title="t('sales.payments.empty')">{{ t('sales.payments.emptyText') }}</x-empty>
        @else
            <div class="hidden overflow-x-auto md:block">
                <table class="table">
                    <thead><tr><th>{{ t('sales.payments.payment') }}</th><th>{{ t('sales.clientDeal') }}</th><th>{{ t('sales.payments.type') }}</th><th>{{ t('sales.status') }}</th><th class="text-right">{{ t('sales.payments.amount') }}</th><th>{{ t('sales.date') }}</th></tr></thead>
                    <tbody>
                        @foreach ($items as $p)
                            <tr>
                                <td><a href="{{ url('/sales/deals/'.$p->deal_id) }}" class="font-medium hover:underline">{{ \App\Services\Sales\Numbers::payment($p->number) }}</a><span class="block text-xs text-muted-foreground">{{ t('sales.paymentMethod.'.$p->method) }}</span></td>
                                <td>{{ $p->client?->name }}<span class="block text-xs text-muted-foreground">{{ \App\Services\Sales\Numbers::deal($p->deal?->number) }}@if ($p->project) · {{ \App\Services\Sales\Numbers::project($p->project->number) }}@endif</span></td>
                                <td>{{ t('sales.paymentType.'.$p->type) }}</td>
                                <td>@include('sales.partials.badge', ['kind' => 'paymentStatus', 'status' => $p->status])</td>
                                <td @class(['whitespace-nowrap text-right font-medium tabular-nums', 'text-danger' => $p->type === 'REFUND'])>{{ $p->type === 'REFUND' ? '− ' : '' }}{{ money($p->amount, $p->currency) }}</td>
                                <td class="whitespace-nowrap text-muted-foreground">{{ dt($p->paid_at ?? $p->created_at) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <ul class="divide-y md:hidden">
                @foreach ($items as $p)
                    <li><a href="{{ url('/sales/deals/'.$p->deal_id) }}" class="flex items-start gap-3 p-4">
                        <div class="min-w-0 flex-1">
                            <p class="font-medium">{{ \App\Services\Sales\Numbers::payment($p->number) }} · {{ $p->client?->name }}</p>
                            <p class="text-sm text-muted-foreground">{{ t('sales.paymentType.'.$p->type) }} · {{ $p->type === 'REFUND' ? '− ' : '' }}{{ money($p->amount, $p->currency) }}</p>
                        </div>
                        @include('sales.partials.badge', ['kind' => 'paymentStatus', 'status' => $p->status])
                    </a></li>
                @endforeach
            </ul>
            <div class="border-t px-3 py-2">{{ $items->links('vendor.pagination.fluggi') }}</div>
        @endif
    </div>
@endsection
