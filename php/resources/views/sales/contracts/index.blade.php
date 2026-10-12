@extends('layouts.app')
@section('title', t('sales.contracts.title'))
@section('content')
    <x-page-header :title="t('sales.contracts.title')" :subtitle="t('sales.contracts.subtitle')" />
    <div class="card overflow-hidden">
        @component('sales.partials.filters', ['filtered' => collect($f)->filter()->isNotEmpty()])
            <div class="sm:w-64"><label class="label" for="cf-q">{{ t('common.search') }}</label><input id="cf-q" name="q" class="input" value="{{ $f['q'] ?? '' }}" placeholder="{{ t('sales.contracts.searchPlaceholder') }}"></div>
            <x-select name="status" class="sm:w-56" :label="t('sales.status')" :placeholder="t('sales.allStatuses')" :value="$f['status'] ?? ''"
                      :options="collect(\App\Services\Sales\ContractsService::STATUSES)->mapWithKeys(fn ($s) => [$s => t('sales.contractStatus.'.$s)])->all()" />
        @endcomponent
        @if ($items->isEmpty())
            <x-empty icon="file" :title="t('sales.contracts.empty')">{{ t('sales.contracts.emptyText') }}</x-empty>
        @else
            <div class="hidden overflow-x-auto md:block">
                <table class="table">
                    <thead><tr><th>{{ t('sales.contracts.contract') }}</th><th>{{ t('sales.clientDeal') }}</th><th>{{ t('sales.status') }}</th><th class="text-right">{{ t('sales.contracts.amount') }}</th><th class="text-right">{{ t('sales.contracts.paid') }}</th><th>{{ t('sales.contracts.signedAt') }}</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($items as $c)
                            @php($number = \App\Services\Sales\Numbers::contract($c->number))
                            <tr>
                                <td><a href="{{ url('/sales/deals/'.$c->deal_id) }}" class="font-medium hover:underline">{{ $number }}</a><span class="block text-xs text-muted-foreground">{{ t('sales.contracts.from', ['date' => d($c->contract_date)]) }}</span></td>
                                <td>{{ $c->client?->name }}<span class="block text-xs text-muted-foreground">{{ \App\Services\Sales\Numbers::deal($c->deal?->number) }}</span></td>
                                <td>@include('sales.partials.badge', ['kind' => 'contractStatus', 'status' => $c->status])</td>
                                <td class="whitespace-nowrap text-right font-medium tabular-nums">{{ money($c->amount, $c->currency) }}</td>
                                <td class="whitespace-nowrap text-right tabular-nums">{{ money($c->paid_uzs) }}</td>
                                <td class="text-muted-foreground">{{ $c->signed_at ? d($c->signed_at) : '—' }}</td>
                                <td><button type="button" class="btn btn-ghost btn-icon" x-data x-on:click="$dispatch('open-doc', { kind: 'contracts', id: @js($c->id), title: @js(t('sales.contracts.documentTitle').' '.$number) })" aria-label="{{ t('sales.contracts.document') }} {{ $number }}"><x-icon name="file" /></button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <ul class="divide-y md:hidden">
                @foreach ($items as $c)
                    @php($number = \App\Services\Sales\Numbers::contract($c->number))
                    <li class="flex items-start gap-3 p-4">
                        <a href="{{ url('/sales/deals/'.$c->deal_id) }}" class="min-w-0 flex-1">
                            <p class="font-medium">{{ $number }} · {{ $c->client?->name }}</p>
                            <p class="text-sm text-muted-foreground">{{ money($c->amount, $c->currency) }}</p>
                        </a>
                        <div class="flex flex-col items-end gap-1">
                            @include('sales.partials.badge', ['kind' => 'contractStatus', 'status' => $c->status])
                            <button type="button" class="btn btn-ghost btn-sm" x-data x-on:click="$dispatch('open-doc', { kind: 'contracts', id: @js($c->id), title: @js(t('sales.contracts.documentTitle').' '.$number) })"><x-icon name="file" /> {{ t('documents.open') }}</button>
                        </div>
                    </li>
                @endforeach
            </ul>
            <div class="border-t px-3 py-2">{{ $items->links('vendor.pagination.fluggi') }}</div>
        @endif
    </div>
    @include('sales.partials.document-dialog')
@endsection
