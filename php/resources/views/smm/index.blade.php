@extends('layouts.app')
@section('title', t('smm.title'))
@section('content')
    @php
        use App\Services\Smm\SmmIdeaService;
        $base = array_filter(['assignee_id' => $f['assignee_id'] ?? null, 'videographer_id' => $f['videographer_id'] ?? null, 'month' => $f['month'] ?? null, 'q' => $f['q'] ?? null]);
        $qs = fn (array $over) => '/smm?'.http_build_query(array_filter(array_merge($base, $over), fn ($v) => $v !== null && $v !== ''));
        $filtered = ($f['assignee_id'] ?? null) || ($f['videographer_id'] ?? null) || ($f['month'] ?? null) || ($f['q'] ?? null) || ($f['status'] ?? null);
    @endphp
    <x-page-header :title="t('smm.title')" :subtitle="t('smm.subtitle')">
        @if ($canCreate)
            <button type="button" class="btn btn-primary" x-data x-on:click="$dispatch('open-modal', 'smm-new')"><x-icon name="plus" /> {{ t('smm.new') }}</button>
        @endif
    </x-page-header>

    <x-tabs :items="[$qs(['view' => 'kanban']) => t('smm.views.kanban'), $qs(['view' => 'list', 'status' => $f['status'] ?? null]) => t('smm.views.list')]"
            :active="$view === 'kanban' ? $qs(['view' => 'kanban']) : $qs(['view' => 'list', 'status' => $f['status'] ?? null])" />

    <form method="GET" class="mb-4 grid gap-3 sm:flex sm:flex-wrap sm:items-end">
        <input type="hidden" name="view" value="{{ $view }}">
        <input type="search" name="q" value="{{ $f['q'] ?? '' }}" class="input sm:max-w-xs" placeholder="{{ t('smm.search') }}" aria-label="{{ t('smm.search') }}">
        @if ($view === 'list')
            <select name="status" class="input sm:w-48" aria-label="{{ t('smm.fields.status') }}">
                <option value="">{{ t('smm.allStatuses') }}</option>
                @foreach (SmmIdeaService::STATUSES as $s)<option value="{{ $s }}" @selected(($f['status'] ?? '') === $s)>{{ t('smm.status.'.$s) }}</option>@endforeach
            </select>
        @endif
        <select name="assignee_id" class="input sm:w-56" aria-label="{{ t('smm.fields.assignee') }}">
            <option value="">{{ t('smm.allAssignees') }}</option>
            @foreach ($assignees as $u)<option value="{{ $u->id }}" @selected(($f['assignee_id'] ?? '') === $u->id)>{{ $u->full_name }}</option>@endforeach
        </select>
        <select name="videographer_id" class="input sm:w-56" aria-label="{{ t('smm.fields.videographer') }}">
            <option value="">{{ t('smm.allVideographers') }}</option>
            @foreach ($videographers as $u)<option value="{{ $u->id }}" @selected(($f['videographer_id'] ?? '') === $u->id)>{{ $u->full_name }}</option>@endforeach
        </select>
        <input type="month" name="month" value="{{ $f['month'] ?? '' }}" class="input sm:w-44" aria-label="{{ t('smm.month') }}" title="{{ t('smm.monthHint') }}">
        <div class="flex gap-2">
            <button class="btn btn-outline">{{ t('common.search') }}</button>
            @if ($filtered)<a href="/smm?view={{ $view }}" class="btn btn-ghost">{{ t('smm.reset') }}</a>@endif
        </div>
    </form>

    @if ($view === 'kanban')
        @include('smm.partials.kanban')
    @else
        @include('smm.partials.list')
    @endif

    @if ($canCreate)
        <x-modal name="smm-new" :title="t('smm.new')" size="max-w-2xl" :open="$errors->any() && old('_form') === 'smm-new'">
            <form method="POST" action="{{ route('smm.store') }}" class="grid gap-4">
                @csrf
                <input type="hidden" name="_form" value="smm-new">
                <x-field name="title" :label="t('smm.fields.title')" maxlength="300" required />
                <x-field name="scenario" type="textarea" rows="8" :label="t('smm.fields.scenario')" :hint="t('smm.scenarioHint')" maxlength="20000" />
                <x-field name="notes" type="textarea" rows="2" :label="t('smm.fields.notes')" maxlength="5000" />
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-select name="assignee_id" :label="t('smm.fields.assignee')" :options="$assignees->pluck('full_name', 'id')->all()" :value="$defaultAssignee"
                              :placeholder="t('crm.common.select')" :required="! $defaultAssignee" />
                    <x-field name="planned_publish_date" type="date" :label="t('smm.fields.plannedDate')" />
                    <x-select name="videographer_id" :label="t('smm.fields.videographer')" :options="$videographers->pluck('full_name', 'id')->all()"
                              :placeholder="t('smm.noVideographer')" :hint="t('smm.videographerHint')" />
                </div>
                <p class="text-xs text-muted-foreground">{{ t('smm.approvalHint') }}</p>
                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <button type="button" class="btn btn-outline" x-on:click="$dispatch('close-modal', 'smm-new')">{{ t('common.cancel') }}</button>
                    <button class="btn btn-primary">{{ t('smm.createSubmit') }}</button>
                </div>
            </form>
        </x-modal>
    @endif
@endsection
