@extends('layouts.app')
@section('title', t('leads.title'))
@section('content')
    @include('crm.partials.errors')
    <x-page-header :title="t('leads.title')" :subtitle="t('leads.subtitle')">
        @includeIf('analytics.partials.export-menu', ['entity' => 'leads'])
        @include('import.partials.button', ['entity' => 'leads'])
        @if ($bulk && $bulk['from'])
            <button type="button" class="btn btn-outline" x-data x-on:click="$dispatch('lead-bulk-manager')"><x-icon name="user" /> {{ t('leads.bulk.transferManager') }}</button>
        @endif
        @if (can('lead.create'))
            <button type="button" class="btn btn-primary" x-data x-on:click="$dispatch('open-modal', 'lead-create')"><x-icon name="plus" /> {{ t('leads.add') }}</button>
        @endif
    </x-page-header>

    @if ($result = session('bulkResult'))
        <div class="mb-4 rounded-md border border-success/30 bg-success-soft p-3 text-sm" role="status">
            <p class="font-medium">{{ $result['message'] }}</p>
            @if ($result['deals'] || $result['clients'] || $result['meetings'] || $result['todos'])
                <p class="mt-1 text-muted-foreground">{{ t('leads.bulk.doneExtra', ['deals' => $result['deals'], 'clients' => $result['clients'], 'meetings' => $result['meetings'], 'todos' => $result['todos']]) }}</p>
            @endif
            @if ($result['link'])<a href="{{ $result['link'] }}" class="link mt-1 inline-block">{{ t('leads.bulk.showMoved') }}</a>@endif
        </div>
    @endif

    <div @if ($bulk) x-data="leadBulk(@js(['pageIds' => $leads->pluck('id')->values(), 'total' => $leads->total(), 'previewUrl' => route('crm.leads.bulkPreview'), 'filter' => array_filter($f, fn ($v) => $v !== null && $v !== '')]))" x-on:lead-bulk-manager.window="openManager()" @endif>
    <div class="card">
        <form method="GET" class="grid gap-3 border-b p-4">
            @if (! empty($f['batch']))
                <input type="hidden" name="batch" value="{{ $f['batch'] }}">
                <p><x-badge color="blue">{{ t('leads.bulk.batchFilter') }}</x-badge></p>
            @endif
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
            @if ($bulk)
                <div class="flex flex-wrap items-center gap-x-4 gap-y-2 border-b px-4 py-2 text-sm">
                    <label class="flex items-center gap-2"><input type="checkbox" :checked="pageAll" x-on:change="togglePage($event.target.checked)"> {{ t('leads.bulk.selectPage') }}</label>
                    @if ($leads->total() > $leads->count())
                        <button type="button" class="link" x-show="pageAll && !all" x-cloak x-on:click="all = true">{{ t('leads.bulk.selectAll', ['count' => $leads->total()]) }}</button>
                        <span x-show="all" x-cloak class="font-medium">{{ t('leads.bulk.selectedAll', ['count' => $leads->total()]) }}</span>
                    @endif
                </div>
            @endif
            <div class="hidden overflow-x-auto md:block">
                <table class="table">
                    <thead><tr>
                        @if ($bulk)<th class="w-10"><input type="checkbox" aria-label="{{ t('leads.bulk.selectPage') }}" :checked="pageAll" x-on:change="togglePage($event.target.checked)"></th>@endif
                        <th>{{ t('crm.fields.title') }}</th><th>{{ t('crm.fields.stage') }}</th><th>{{ t('crm.fields.score') }}</th>
                        <th>{{ t('crm.fields.budget') }}</th><th>{{ t('crm.fields.source') }}</th><th>{{ t('crm.fields.owner') }}</th><th>{{ t('crm.fields.createdAt') }}</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($leads as $l)
                        <tr>
                            @if ($bulk)<td><input type="checkbox" value="{{ $l->id }}" x-model="selected" x-on:change="all = false" aria-label="{{ t('leads.bulk.selectRow') }}: {{ $l->title }}"></td>@endif
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
                    <li class="flex items-start">
                        @if ($bulk)<label class="flex shrink-0 items-center self-stretch pl-4"><input type="checkbox" value="{{ $l->id }}" x-model="selected" x-on:change="all = false" aria-label="{{ t('leads.bulk.selectRow') }}: {{ $l->title }}"></label>@endif
                        <a href="{{ route('crm.leads.show', $l->id) }}" class="grid min-w-0 flex-1 gap-1.5 p-4 active:bg-muted/50">
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
    @if ($bulk)
        @include('crm.leads.bulk')
    @endif
    </div>

    @if (can('lead.create'))
        <x-modal name="lead-create" :title="t('leads.createTitle')" size="max-w-2xl" :open="$errors->any() && old('_form') === 'lead-create'">
            @include('crm.leads.form', ['lead' => null])
        </x-modal>
    @endif
@endsection
