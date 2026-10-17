@extends('layouts.app')
@section('title', t('tasks.title'))
@section('content')
    @php
        use App\Services\Projects\TaskService;
        use App\Support\Format;
        $qs = fn (array $over) => '/tasks?'.http_build_query(array_filter(array_merge(['view' => $view, 'q' => $q, 'mine' => $mine ? '1' : '0'], $over), fn ($v) => $v !== null && $v !== ''));
    @endphp
    <x-page-header :title="t('tasks.title')" :subtitle="t('tasks.subtitle')">
        @includeIf('analytics.partials.export-menu', ['entity' => 'tasks'])
    </x-page-header>
    <x-tabs :items="collect(TaskService::VIEWS)->mapWithKeys(fn ($v) => [$qs(['view' => $v]) => t('tasks.views.'.$v)])->all()" :active="$qs([])" />
    <div class="card">
        <form method="GET" class="flex flex-col gap-3 border-b p-4 sm:flex-row sm:items-center">
            <input type="hidden" name="view" value="{{ $view }}">
            <input type="search" name="q" value="{{ $q }}" class="input sm:max-w-xs" placeholder="{{ t('tasks.searchTitle') }}" aria-label="{{ t('tasks.searchTitle') }}">
            @if ($lead)
                <input type="hidden" name="mine" value="0">
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="mine" value="1" @checked($mine) onchange="this.form.submit()"> {{ t('tasks.mine') }}</label>
            @else
                <input type="hidden" name="mine" value="1">
            @endif
            <button class="btn btn-outline">{{ t('common.search') }}</button>
        </form>
        @if ($tasks->isEmpty())
            <x-empty icon="check" :title="t('tasks.empty')">{{ t('tasks.emptyText') }}</x-empty>
        @else
            <div class="hidden overflow-x-auto md:block"><table class="table">
                <thead><tr><th>{{ t('tasks.fields.title') }}</th><th>{{ t('tasks.fields.project') }}</th><th>{{ t('tasks.fields.status') }}</th><th>{{ t('tasks.fields.deadline') }}</th><th>{{ t('tasks.fields.assignee') }}</th><th>{{ t('tasks.fields.priority') }}</th></tr></thead>
                <tbody>
                @foreach ($tasks as $task)
                    <tr>
                        <td><a href="/projects/{{ $task->project_id }}?task={{ $task->id }}" class="font-medium hover:underline">{{ $task->title }}</a><span class="block text-xs text-muted-foreground">{{ TaskService::code($task) }}</span></td>
                        <td><a href="/projects/{{ $task->project_id }}" class="text-muted-foreground hover:underline">{{ Format::code('P', $task->project->number) }} · {{ $task->project->name }}</a></td>
                        <td>@include('projects.partials.task-status', ['status' => $task->status])</td>
                        <td class="whitespace-nowrap">{{ dt($task->deadline) }} @include('projects.partials.overdue', ['days' => $task->overdue_days, 'class' => 'ml-2'])</td>
                        <td class="text-muted-foreground">{{ $task->assignee?->full_name }}</td>
                        <td>@include('projects.partials.priority', ['priority' => $task->priority])</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
            <ul class="divide-y md:hidden">
                @foreach ($tasks as $task)
                    <li><a href="/projects/{{ $task->project_id }}?task={{ $task->id }}" class="grid gap-1.5 p-4 active:bg-muted/50">
                        <div class="flex items-start justify-between gap-3"><p class="font-medium">{{ $task->title }}</p>@include('projects.partials.task-status', ['status' => $task->status])</div>
                        <p class="text-sm text-muted-foreground">{{ $task->project->name }} · {{ dt($task->deadline) }}</p>
                        @include('projects.partials.overdue', ['days' => $task->overdue_days, 'class' => 'w-fit'])
                    </a></li>
                @endforeach
            </ul>
            <div class="border-t px-3 py-2">{{ $tasks->links('vendor.pagination.fluggi') }}</div>
        @endif
    </div>
@endsection
