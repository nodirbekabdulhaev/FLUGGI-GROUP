@extends('layouts.app')
@section('title', t('leads.title'))
@section('content')
    @include('crm.partials.errors')
    <x-page-header :title="t('leads.title')" :subtitle="t('leads.subtitle')">
        @includeIf('analytics.partials.export-menu', ['entity' => 'leads'])
        @if (can('lead.create'))
            <button type="button" class="btn btn-primary" x-data x-on:click="$dispatch('open-modal', 'lead-create')"><x-icon name="plus" /> {{ t('leads.add') }}</button>
        @endif
    </x-page-header>

    <div class="card">
        <form method="GET" class="grid gap-3 border-b p-4">
            <div class="relative">
                <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                <input name="q" value="{{ $f['q'] ?? '' }}" class="input pl-9" placeholder="{{ t('leads.search') }}" aria-label="{{ t('common.search') }}">
            </div>
            <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                <select name="status" class="input" aria-label="{{ t('crm.fields.status') }}" onchange="this.form.submit()">
                    <option value="any" @selected($f['status'] === 'any')>{{ t('leads.filters.allStatuses') }}</option>
                    @foreach (\App\Services\Crm\Crm::LEAD_STATUSES as $s)<option value="{{ $s }}" @selected($f['status'] === $s)>{{ t('crm.status.lead.'.$s) }}</option>@endforeach
                </select>
                <select name="stage" class="input" aria-label="{{ t('crm.fields.stage') }}" onchange="this.form.submit()">
                    <option value="">{{ t('crm.fields.stage') }}: {{ t('crm.common.all') }}</option>
                    @foreach (\App\Services\Crm\Crm::stages('LEAD') as $s)<option value="{{ $s->code }}" @selected(($f['stage'] ?? '') === $s->code)>{{ $s->label() }}</option>@endforeach
                </select>
                <select name="source_id" class="input" aria-label="{{ t('crm.fields.source') }}" onchange="this.form.submit()">
                    <option value="">{{ t('crm.fields.source') }}: {{ t('crm.common.all') }}</option>
                    @foreach ($form['allSources'] as $id => $name)<option value="{{ $id }}" @selected(($f['source_id'] ?? '') === $id)>{{ $name }}</option>@endforeach
                </select>
                <select name="service_id" class="input" aria-label="{{ t('crm.fields.service') }}" onchange="this.form.submit()">
                    <option value="">{{ t('crm.fields.service') }}: {{ t('crm.common.all') }}</option>
                    @foreach ($form['allServices'] as $id => $name)<option value="{{ $id }}" @selected(($f['service_id'] ?? '') === $id)>{{ $name }}</option>@endforeach
                </select>
                <select name="score" class="input" aria-label="{{ t('crm.fields.score') }}" onchange="this.form.submit()">
                    <option value="">{{ t('crm.fields.score') }}: {{ t('crm.common.all') }}</option>
                    @foreach (\App\Services\Crm\Crm::SCORE_LEVELS as $s)<option value="{{ $s }}" @selected(($f['score'] ?? '') === $s)>{{ t('crm.score.'.$s) }}</option>@endforeach
                </select>
                @if ($owners)
                    <select name="owner_id" class="input" aria-label="{{ t('crm.fields.owner') }}" onchange="this.form.submit()">
                        <option value="">{{ t('crm.fields.owner') }}: {{ t('crm.common.all') }}</option>
                        @foreach ($owners as $id => $name)<option value="{{ $id }}" @selected(($f['owner_id'] ?? '') === $id)>{{ $name }}</option>@endforeach
                    </select>
                @endif
            </div>
            <div class="flex flex-wrap items-end gap-3">
                <div><label class="label text-xs" for="lf-from">{{ t('crm.common.dateFrom') }}</label><input id="lf-from" type="date" name="date_from" value="{{ $f['date_from'] ?? '' }}" class="input"></div>
                <div><label class="label text-xs" for="lf-to">{{ t('crm.common.dateTo') }}</label><input id="lf-to" type="date" name="date_to" value="{{ $f['date_to'] ?? '' }}" class="input"></div>
                <button class="btn btn-outline"><x-icon name="filter" /> {{ t('common.filter') }}</button>
                @if ($filtered)<a href="{{ route('crm.leads.index') }}" class="btn btn-ghost">{{ t('common.reset') }}</a>@endif
            </div>
        </form>

        @if ($leads->isEmpty())
            @if ($filtered)
                <x-empty :title="t('leads.emptyFiltered')" />
            @else
                <x-empty :title="t('leads.emptyTitle')">{{ t('leads.emptyText') }}</x-empty>
            @endif
        @else
            <div class="hidden overflow-x-auto md:block">
                <table class="table">
                    <thead><tr>
                        <th>{{ t('crm.fields.title') }}</th><th>{{ t('crm.fields.stage') }}</th><th>{{ t('crm.fields.score') }}</th>
                        <th>{{ t('crm.fields.budget') }}</th><th>{{ t('crm.fields.source') }}</th><th>{{ t('crm.fields.owner') }}</th><th>{{ t('crm.fields.createdAt') }}</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($leads as $l)
                        <tr>
                            <td><a href="{{ route('crm.leads.show', $l->id) }}" class="block">
                                <span class="font-medium hover:underline">{{ $l->title }}</span>
                                <span class="block text-xs text-muted-foreground">{{ \App\Support\Format::code('L', $l->number) }} · {{ $l->contact_name }} {{ $l->phone ?? $l->telegram }}</span>
                            </a></td>
                            <td>@if ($l->status === 'OPEN') @include('crm.partials.stage', ['stage' => $l->stage]) @else @include('crm.partials.status', ['status' => $l->status, 'kind' => 'lead']) @endif</td>
                            <td>@include('crm.partials.score', ['level' => $l->score_level, 'score' => $l->score])</td>
                            <td class="whitespace-nowrap">{{ money($l->budget, $l->currency) }}</td>
                            <td class="text-muted-foreground">{{ $l->source?->label() }}</td>
                            <td class="text-muted-foreground">{{ $l->owner?->full_name }}</td>
                            <td class="whitespace-nowrap text-muted-foreground">{{ dt($l->created_at) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <ul class="divide-y md:hidden">
                @foreach ($leads as $l)
                    <li><a href="{{ route('crm.leads.show', $l->id) }}" class="grid gap-1.5 p-4 active:bg-muted/50">
                        <div class="flex items-start justify-between gap-2"><p class="font-medium">{{ $l->title }}</p>@include('crm.partials.score', ['level' => $l->score_level])</div>
                        <p class="text-sm text-muted-foreground">{{ $l->contact_name }} · {{ $l->phone ?? $l->telegram }}</p>
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            @if ($l->status === 'OPEN') @include('crm.partials.stage', ['stage' => $l->stage]) @else @include('crm.partials.status', ['status' => $l->status, 'kind' => 'lead']) @endif
                            <span>{{ money($l->budget, $l->currency) }}</span>
                        </div>
                    </a></li>
                @endforeach
            </ul>
            <div class="border-t px-3 py-2">{{ $leads->links('vendor.pagination.fluggi') }}</div>
        @endif
    </div>

    @if (can('lead.create'))
        <x-modal name="lead-create" :title="t('leads.createTitle')" size="max-w-2xl" :open="$errors->any() && old('_form') === 'lead-create'">
            @include('crm.leads.form', ['lead' => null])
        </x-modal>
    @endif
@endsection
