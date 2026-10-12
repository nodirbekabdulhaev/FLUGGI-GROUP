@extends('layouts.app')
@section('title', t('projects.views.'.$view))
@section('content')
    @php($showMoney = $projects->getCollection()->contains(fn ($p) => $p->sees_money))
    <x-page-header :title="t('projects.views.'.$view)" :subtitle="t('projects.viewsHint.'.$view)">
        @includeIf('analytics.partials.export-menu', ['entity' => 'projects'])
    </x-page-header>
    <x-tabs :items="['/projects/all' => t('projects.views.all'), '/projects/active' => t('projects.views.active'), '/projects/overdue' => t('projects.views.overdue'), '/projects/completed' => t('projects.views.completed')]" :active="'/projects/'.$view" />

    <div class="card">
        <form method="GET" class="flex flex-col gap-3 border-b p-4 sm:flex-row">
            <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="{{ t('projects.search') }}" aria-label="{{ t('projects.search') }}" class="input sm:max-w-xs">
            @if ($view === 'all')
                <select name="status" class="input sm:w-56" aria-label="{{ t('projects.col.status') }}" onchange="this.form.submit()">
                    <option value="">{{ t('projects.allStatuses') }}</option>
                    @foreach (\App\Services\Projects\ProjectAccess::STATUSES as $s)
                        <option value="{{ $s }}" @selected(($filters['status'] ?? '') === $s)>{{ t('sales.projectStatus.'.$s) }}</option>
                    @endforeach
                </select>
            @endif
            @if ($directions->isNotEmpty())
                <select name="direction" class="input sm:w-56" aria-label="{{ t('projects.direction') }}" onchange="this.form.submit()">
                    <option value="">{{ t('projects.allDirections') }}</option>
                    @foreach ($directions as $dir)
                        <option value="{{ $dir->id }}" @selected(($filters['direction'] ?? '') === $dir->id)>{{ $dir->name }}</option>
                    @endforeach
                </select>
            @endif
            <button class="btn btn-outline">{{ t('common.filter') }}</button>
        </form>

        @if ($projects->isEmpty())
            <x-empty icon="gauge" :title="t('projects.empty')">{{ t('projects.emptyText') }}</x-empty>
        @else
            <div class="hidden overflow-x-auto md:block">
                <table class="table">
                    <thead><tr>
                        <th>{{ t('projects.col.project') }}</th><th>{{ t('projects.col.client') }}</th><th>{{ t('projects.col.status') }}</th>
                        <th>{{ t('projects.col.deadline') }}</th><th>{{ t('projects.col.tasks') }}</th><th>{{ t('projects.col.rop') }}</th>
                        @if ($showMoney)<th class="text-right">{{ t('projects.col.price') }}</th>@endif
                    </tr></thead>
                    <tbody>
                    @foreach ($projects as $p)
                        <tr>
                            <td>
                                <a href="/projects/{{ $p->id }}" class="font-medium hover:underline">{{ \App\Support\Format::code('P', $p->number) }} · {{ $p->name }}</a>
                                <span class="block">@include('projects.partials.priority', ['priority' => $p->priority])@if ($p->direction)<span class="text-xs text-muted-foreground"> · {{ $p->direction->name }}</span>@endif</span>
                            </td>
                            <td>{{ $p->client?->name }}</td>
                            <td>@include('projects.partials.project-status', ['status' => $p->status])</td>
                            <td class="whitespace-nowrap">{{ d($p->deadline) }} @include('projects.partials.overdue', ['days' => $p->overdue_days, 'class' => 'ml-2'])</td>
                            <td>@include('projects.partials.progress', ['c' => $p->task_counts])</td>
                            <td class="text-muted-foreground">{{ $p->rop?->full_name }}</td>
                            @if ($showMoney)<td class="whitespace-nowrap text-right font-medium">{{ $p->sees_money ? money($p->price, $p->currency) : '—' }}</td>@endif
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <ul class="divide-y md:hidden">
                @foreach ($projects as $p)
                    <li>
                        <a href="/projects/{{ $p->id }}" class="grid gap-2 p-4 active:bg-muted/50">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="font-medium">{{ \App\Support\Format::code('P', $p->number) }} · {{ $p->name }}</p>
                                    <p class="text-sm text-muted-foreground">{{ $p->client?->name }} · {{ d($p->deadline) }}</p>
                                </div>
                                @include('projects.partials.project-status', ['status' => $p->status])
                            </div>
                            @include('projects.partials.overdue', ['days' => $p->overdue_days, 'class' => 'w-fit'])
                            @include('projects.partials.progress', ['c' => $p->task_counts])
                        </a>
                    </li>
                @endforeach
            </ul>
            <div class="border-t px-3 py-2">{{ $projects->links('vendor.pagination.fluggi') }}</div>
        @endif
    </div>
@endsection
