@extends('layouts.app')
@section('title', t('sales.proposals.title'))
@section('content')
    <x-page-header :title="t('sales.proposals.title')" :subtitle="t('sales.proposals.subtitle')" />
    <div class="card overflow-hidden">
        @component('sales.partials.filters', ['filtered' => collect($f)->filter()->isNotEmpty()])
            <div class="sm:w-64"><label class="label" for="pf-q">{{ t('common.search') }}</label><input id="pf-q" name="q" class="input" value="{{ $f['q'] ?? '' }}" placeholder="{{ t('sales.proposals.searchPlaceholder') }}"></div>
            <x-select name="status" class="sm:w-56" :label="t('sales.status')" :placeholder="t('sales.allStatuses')" :value="$f['status'] ?? ''"
                      :options="collect(\App\Services\Sales\ProposalsService::STATUSES)->mapWithKeys(fn ($s) => [$s => t('sales.proposalStatus.'.$s)])->all()" />
        @endcomponent
        @if ($items->isEmpty())
            <x-empty icon="file" :title="t('sales.proposals.empty')">{{ t('sales.proposals.emptyText') }}</x-empty>
        @else
            <div class="hidden overflow-x-auto md:block">
                <table class="table">
                    <thead><tr><th>{{ t('sales.proposals.proposal') }}</th><th>{{ t('sales.clientDeal') }}</th><th>{{ t('sales.status') }}</th><th class="text-right">{{ t('sales.proposals.total') }}</th><th>{{ t('sales.manager') }}</th><th>{{ t('sales.updatedAt') }}</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($items as $p)
                            @php($number = \App\Services\Sales\Numbers::proposal($p->number))
                            <tr>
                                <td><a href="{{ url('/sales/deals/'.$p->deal_id) }}" class="font-medium hover:underline">{{ $number }} · {{ $p->title }}</a><span class="block text-xs text-muted-foreground">v{{ $p->current_version }}</span></td>
                                <td>{{ $p->client?->name }}<span class="block text-xs text-muted-foreground">{{ \App\Services\Sales\Numbers::deal($p->deal?->number) }}</span></td>
                                <td>@include('sales.partials.badge', ['kind' => 'proposalStatus', 'status' => $p->status])</td>
                                <td class="whitespace-nowrap text-right font-medium tabular-nums">{{ money($p->total, $p->currency) }}</td>
                                <td class="text-muted-foreground">{{ $p->manager?->full_name }}</td>
                                <td class="whitespace-nowrap text-muted-foreground">{{ dt($p->updated_at) }}</td>
                                <td><button type="button" class="btn btn-ghost btn-icon" x-data x-on:click="$dispatch('open-doc', { kind: 'proposals', id: @js($p->id), title: @js($number.' · '.$p->title) })" aria-label="{{ t('sales.proposals.document') }} {{ $number }}"><x-icon name="file" /></button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <ul class="divide-y md:hidden">
                @foreach ($items as $p)
                    @php($number = \App\Services\Sales\Numbers::proposal($p->number))
                    <li class="flex items-start gap-3 p-4">
                        <a href="{{ url('/sales/deals/'.$p->deal_id) }}" class="min-w-0 flex-1">
                            <p class="font-medium">{{ $number }} · {{ $p->title }}</p>
                            <p class="text-sm text-muted-foreground">{{ $p->client?->name }} · {{ money($p->total, $p->currency) }}</p>
                        </a>
                        <div class="flex flex-col items-end gap-1">
                            @include('sales.partials.badge', ['kind' => 'proposalStatus', 'status' => $p->status])
                            <button type="button" class="btn btn-ghost btn-sm" x-data x-on:click="$dispatch('open-doc', { kind: 'proposals', id: @js($p->id), title: @js($number.' · '.$p->title) })"><x-icon name="file" /> {{ t('documents.open') }}</button>
                        </div>
                    </li>
                @endforeach
            </ul>
            <div class="border-t px-3 py-2">{{ $items->links('vendor.pagination.fluggi') }}</div>
        @endif
    </div>
    @include('sales.partials.document-dialog')
@endsection
