@extends('layouts.app')
@section('title', t('clients.title'))
@section('content')
    @include('crm.partials.errors')
    <x-page-header :title="t('clients.title')" :subtitle="t('clients.subtitle')">
        @includeIf('analytics.partials.export-menu', ['entity' => 'clients'])
        @include('import.partials.button', ['entity' => 'clients'])
        @if (can('client.create'))
            <button type="button" class="btn btn-primary" x-data x-on:click="$dispatch('open-modal', 'client-create')"><x-icon name="plus" /> {{ t('clients.add') }}</button>
        @endif
    </x-page-header>
    <div class="card">
        <form method="GET" class="flex flex-col gap-3 border-b p-4 sm:flex-row">
            <div class="relative flex-1">
                <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                <input name="q" value="{{ $f['q'] ?? '' }}" class="input pl-9" placeholder="{{ t('clients.search') }}" aria-label="{{ t('common.search') }}">
            </div>
            <select name="type" class="input sm:w-48" aria-label="{{ t('crm.fields.type') }}" onchange="this.form.submit()">
                <option value="">{{ t('crm.fields.type') }}: {{ t('crm.common.all') }}</option>
                @foreach (\App\Services\Crm\Crm::CLIENT_TYPES as $c)<option value="{{ $c }}" @selected(($f['type'] ?? '') === $c)>{{ t('crm.clientType.'.$c) }}</option>@endforeach
            </select>
        </form>
        @if ($clients->isEmpty())
            @if (array_filter($f))<x-empty :title="t('clients.emptyFiltered')" />@else<x-empty :title="t('clients.emptyTitle')">{{ t('clients.emptyText') }}</x-empty>@endif
        @else
            <div class="hidden overflow-x-auto md:block">
                <table class="table">
                    <thead><tr><th>{{ t('crm.fields.client') }}</th><th>{{ t('crm.fields.phone') }}</th><th>{{ t('clients.deals') }}</th><th>{{ t('crm.fields.owner') }}</th><th>{{ t('crm.fields.createdAt') }}</th></tr></thead>
                    <tbody>
                    @foreach ($clients as $c)
                        <tr>
                            <td><a href="{{ route('crm.clients.show', $c->id) }}" class="block"><span class="font-medium hover:underline">{{ $c->name }}</span>
                                <span class="block text-xs text-muted-foreground">{{ \App\Support\Format::code('C', $c->number) }} · {{ t('crm.clientType.'.$c->type) }}@if ($c->city) · {{ $c->city }}@endif</span></a></td>
                            <td class="text-muted-foreground">{{ $c->phone ?? '—' }}</td>
                            <td>{{ t('clients.dealsCount', ['count' => $c->deals_count, 'open' => $c->open_deals_count]) }}</td>
                            <td class="text-muted-foreground">{{ $c->owner?->full_name }}</td>
                            <td class="text-muted-foreground">{{ d($c->created_at) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <ul class="divide-y md:hidden">
                @foreach ($clients as $c)
                    <li><a href="{{ route('crm.clients.show', $c->id) }}" class="grid gap-1 p-4 active:bg-muted/50">
                        <p class="font-medium">{{ $c->name }}</p>
                        <p class="text-sm text-muted-foreground">{{ t('clients.dealsCount', ['count' => $c->deals_count, 'open' => $c->open_deals_count]) }} · {{ $c->owner?->full_name }}</p>
                    </a></li>
                @endforeach
            </ul>
            <div class="border-t px-3 py-2">{{ $clients->links('vendor.pagination.fluggi') }}</div>
        @endif
    </div>
    @if (can('client.create'))
        <x-modal name="client-create" :title="t('clients.createTitle')" size="max-w-2xl" :open="$errors->any() && old('_form') === 'client-create'">
            @include('crm.clients.form', ['client' => null])
        </x-modal>
    @endif
@endsection
