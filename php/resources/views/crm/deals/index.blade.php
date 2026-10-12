@extends('layouts.app')
@section('title', t('deals.title'))
@section('content')
    @include('crm.partials.errors')
    @php $canCreate = can('deal.create') && can('client.read'); @endphp
    <x-page-header :title="t('deals.title')" :subtitle="t('deals.subtitle')">
        @includeIf('analytics.partials.export-menu', ['entity' => 'deals'])
        @if ($canCreate)
            <button type="button" class="btn btn-primary" x-data x-on:click="$dispatch('open-modal', 'deal-create')"><x-icon name="plus" /> {{ t('deals.add') }}</button>
        @endif
    </x-page-header>

    <div class="card">
        <form method="GET" class="grid gap-3 border-b p-4">
            <div class="relative">
                <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                <input name="q" value="{{ $f['q'] ?? '' }}" class="input pl-9" placeholder="{{ t('deals.search') }}" aria-label="{{ t('common.search') }}">
            </div>
            <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                <select name="status" class="input" aria-label="{{ t('crm.fields.status') }}" onchange="this.form.submit()">
                    <option value="any" @selected($f['status'] === 'any')>{{ t('leads.filters.allStatuses') }}</option>
                    @foreach (\App\Services\Crm\Crm::DEAL_STATUSES as $s)<option value="{{ $s }}" @selected($f['status'] === $s)>{{ t('crm.status.deal.'.$s) }}</option>@endforeach
                </select>
                <select name="stage" class="input" aria-label="{{ t('crm.fields.stage') }}" onchange="this.form.submit()">
                    <option value="">{{ t('crm.fields.stage') }}: {{ t('crm.common.all') }}</option>
                    @foreach (\App\Services\Crm\Crm::stages('DEAL') as $s)<option value="{{ $s->code }}" @selected(($f['stage'] ?? '') === $s->code)>{{ $s->label() }}</option>@endforeach
                </select>
                <select name="service_id" class="input" aria-label="{{ t('crm.fields.service') }}" onchange="this.form.submit()">
                    <option value="">{{ t('crm.fields.service') }}: {{ t('crm.common.all') }}</option>
                    @foreach ($services as $id => $name)<option value="{{ $id }}" @selected(($f['service_id'] ?? '') === $id)>{{ $name }}</option>@endforeach
                </select>
                @if ($owners)
                    <select name="owner_id" class="input" aria-label="{{ t('crm.fields.owner') }}" onchange="this.form.submit()">
                        <option value="">{{ t('crm.fields.owner') }}: {{ t('crm.common.all') }}</option>
                        @foreach ($owners as $id => $name)<option value="{{ $id }}" @selected(($f['owner_id'] ?? '') === $id)>{{ $name }}</option>@endforeach
                    </select>
                @endif
            </div>
            <div class="flex flex-wrap items-end gap-3">
                <div><label class="label text-xs" for="df-min">{{ t('deals.amountFrom') }}</label><input id="df-min" name="amount_min" inputmode="decimal" value="{{ $f['amount_min'] ?? '' }}" class="input w-36"></div>
                <div><label class="label text-xs" for="df-max">{{ t('deals.amountTo') }}</label><input id="df-max" name="amount_max" inputmode="decimal" value="{{ $f['amount_max'] ?? '' }}" class="input w-36"></div>
                <div><label class="label text-xs" for="df-from">{{ t('crm.common.dateFrom') }}</label><input id="df-from" type="date" name="date_from" value="{{ $f['date_from'] ?? '' }}" class="input"></div>
                <div><label class="label text-xs" for="df-to">{{ t('crm.common.dateTo') }}</label><input id="df-to" type="date" name="date_to" value="{{ $f['date_to'] ?? '' }}" class="input"></div>
                <button class="btn btn-outline"><x-icon name="filter" /> {{ t('common.filter') }}</button>
                @if ($filtered)<a href="{{ route('crm.deals.index') }}" class="btn btn-ghost">{{ t('common.reset') }}</a>@endif
            </div>
        </form>

        @if ($deals->isEmpty())
            @if ($filtered)<x-empty :title="t('deals.emptyFiltered')" />@else<x-empty :title="t('deals.emptyTitle')">{{ t('deals.emptyText') }}</x-empty>@endif
        @else
            <div class="hidden overflow-x-auto md:block">
                <table class="table">
                    <thead><tr>
                        <th>{{ t('crm.fields.title') }}</th><th>{{ t('crm.fields.client') }}</th><th>{{ t('crm.fields.stage') }}</th>
                        <th class="text-right">{{ t('crm.fields.amount') }}</th><th>{{ t('crm.fields.probability') }}</th><th>{{ t('crm.fields.owner') }}</th><th>{{ t('crm.fields.expectedCloseDate') }}</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($deals as $d)
                        <tr>
                            <td><a href="{{ route('crm.deals.show', $d->id) }}" class="block"><span class="font-medium hover:underline">{{ $d->title }}</span>
                                <span class="block text-xs text-muted-foreground">{{ \App\Support\Format::code('D', $d->number) }}@if ($d->is_repeat) · {{ t('deals.repeat') }}@endif</span></a></td>
                            <td><a class="link" href="{{ route('crm.clients.show', $d->client_id) }}">{{ $d->client?->name }}</a></td>
                            <td>@if ($d->status === 'OPEN') @include('crm.partials.stage', ['stage' => $d->stage]) @else @include('crm.partials.status', ['status' => $d->status, 'kind' => 'deal']) @endif</td>
                            <td class="whitespace-nowrap text-right font-medium">{{ money($d->amount, $d->currency) }}</td>
                            <td>{{ $d->probability_override ?? $d->stage->probability }}%</td>
                            <td class="text-muted-foreground">{{ $d->owner?->full_name }}</td>
                            <td class="whitespace-nowrap text-muted-foreground">{{ d($d->expected_close_date) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <ul class="divide-y md:hidden">
                @foreach ($deals as $d)
                    <li><a href="{{ route('crm.deals.show', $d->id) }}" class="grid gap-1.5 p-4 active:bg-muted/50">
                        <p class="font-medium">{{ $d->title }}</p>
                        <p class="text-sm text-muted-foreground">{{ $d->client?->name }} · {{ $d->owner?->full_name }}</p>
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            @if ($d->status === 'OPEN') @include('crm.partials.stage', ['stage' => $d->stage]) @else @include('crm.partials.status', ['status' => $d->status, 'kind' => 'deal']) @endif
                            <span class="font-medium">{{ money($d->amount, $d->currency) }}</span>
                        </div>
                    </a></li>
                @endforeach
            </ul>
            <div class="border-t px-3 py-2">{{ $deals->links('vendor.pagination.fluggi') }}</div>
        @endif
    </div>

    @if ($canCreate)
        <x-modal name="deal-create" :title="t('deals.createTitle')" :open="$errors->any() && old('_form') === 'deal-create'">
            @include('crm.deals.form', ['deal' => null])
        </x-modal>
    @endif
@endsection
