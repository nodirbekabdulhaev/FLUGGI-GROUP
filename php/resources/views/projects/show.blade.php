@extends('layouts.app')
@section('title', $project->name)
@section('content')
    @php
        use App\Services\Projects\ProjectAccess;
        use App\Support\Format;
        $p = $project;
        $open = in_array($p->status, ProjectAccess::ACTIVE, true);
        $base = '/projects/'.$p->id;
        $tabLabels = ['tasks' => t('projects.tabs.tasks'), 'team' => t('projects.tabs.team'), 'finance' => t('projects.tabs.finance'), 'files' => t('projects.tabs.files'), 'info' => t('projects.tabs.info'), 'history' => t('projects.tabs.history')];
    @endphp
    <a href="/projects/all" class="mb-4 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"><x-icon name="arrow-left" class="size-4" /> {{ t('projects.back') }}</a>

    <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div class="min-w-0">
            <p class="text-xs text-muted-foreground">{{ Format::code('P', $p->number) }}</p>
            <h1 class="text-xl font-semibold tracking-tight sm:text-2xl">{{ $p->name }}</h1>
            <div class="mt-2 flex flex-wrap items-center gap-2 text-sm">
                @include('projects.partials.project-status', ['status' => $p->status])
                @include('projects.partials.overdue', ['days' => $p->overdue_days])
                <span>{{ $p->client?->name }}</span>
                <span class="text-muted-foreground">·</span>
                <span class="flex items-center gap-1 text-muted-foreground"><x-icon name="user" class="size-3.5" /> {{ $p->rop?->full_name }}</span>
                @include('projects.partials.priority', ['priority' => $p->priority])
            </div>
        </div>
        <div class="flex flex-wrap items-start gap-3">
            @if ($p->can['createTasks'] && $open)
                <button type="button" class="btn btn-primary btn-sm" x-data @click="$dispatch('open-modal', 'project-quick-task')"><x-icon name="list" /> {{ t('todos.assignTask') }}</button>
            @endif
            <div class="grid gap-1 sm:text-right">
                @if ($p->sees_money)<p class="text-2xl font-semibold">{{ money($p->price, $p->currency) }}</p>@endif
                @include('projects.partials.progress', ['c' => $p->task_counts, 'class' => 'w-40 sm:justify-self-end'])
            </div>
            @if ($p->can['update'] || can('project.delete'))
                <div class="relative" x-data="{ menu: false }" @click.outside="menu = false">
                    <button type="button" class="btn btn-outline btn-icon" @click="menu = !menu" aria-label="{{ t('common.actions') }}"><x-icon name="more" /></button>
                    <div x-show="menu" x-cloak class="absolute right-0 z-20 mt-1 w-56 rounded-md border bg-surface py-1 shadow-lg">
                        @if ($p->can['update'])
                            <button type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-muted" @click="menu = false; $dispatch('open-modal', 'project-status')"><x-icon name="chevron-right" /> {{ t('projects.changeStatus') }}</button>
                            <button type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-muted" @click="menu = false; $dispatch('open-modal', 'project-edit')"><x-icon name="edit" /> {{ t('projects.edit') }}</button>
                            @if ($open)
                                <button type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-muted" @click="menu = false; $dispatch('open-modal', 'project-template')"><x-icon name="copy" /> {{ t('projects.applyTemplate') }}</button>
                            @endif
                        @endif
                        @if (can('project.delete'))
                            {{-- Только CEO: проект и задачи скрываются, оплаты и история остаются --}}
                            <form method="POST" action="{{ $base }}" data-confirm="{{ t('projects.deleteConfirm', ['name' => $p->name]) }}" class="border-t">@csrf @method('DELETE')
                                <button class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-danger hover:bg-muted"><x-icon name="trash" /> {{ t('common.delete') }}</button>
                            </form>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>

    @isset($partnerShare)
        <div class="mb-4 grid gap-3 rounded-lg border p-3 text-sm sm:grid-cols-3">
            <div><p class="text-xs text-muted-foreground">{{ t('finance.project.partnerShare', ['pct' => $partnerShare['pct']]) }}</p><p class="text-lg font-semibold tabular-nums">{{ money($partnerShare['shareUzs']) }}</p></div>
            <div><p class="text-xs text-muted-foreground">{{ t('finance.project.partnerPaid') }}</p><p class="text-lg font-semibold tabular-nums">{{ money($partnerShare['paidUzs']) }}</p></div>
            <div><p class="text-xs text-muted-foreground">{{ t('finance.project.partnerRemaining') }}</p><p class="text-lg font-semibold tabular-nums">{{ money($partnerShare['remainingUzs']) }}</p>
                <a class="link text-xs" href="/finance/partner">{{ t('partner.title') }}</a></div>
        </div>
    @endisset

    <nav class="mb-4 flex gap-1 overflow-x-auto border-b">
        @foreach ($tabs as $key)
            <a href="{{ $base }}?tab={{ $key }}" @class(['whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium', 'border-foreground text-foreground' => $tab === $key, 'border-transparent text-muted-foreground hover:text-foreground' => $tab !== $key])>
                {{ $tabLabels[$key] }}
                @if ($key === 'tasks')<span class="ml-1 text-xs text-muted-foreground">{{ $p->task_counts['total'] }}</span>@endif
                @if ($key === 'team')<span class="ml-1 text-xs text-muted-foreground">{{ $p->team->where('status', '<>', 'REMOVED')->count() }}</span>@endif
            </a>
        @endforeach
    </nav>

    @if ($tab === 'tasks')
        @include('projects.partials.kanban')
    @elseif ($tab === 'team')
        <x-card>@include('projects.partials.team')</x-card>
    @elseif ($tab === 'finance')
        <x-card>@include('finance.partials.project-finance')</x-card>
    @elseif ($tab === 'files')
        <x-card>@include('projects.partials.files', ['files' => $files, 'owner' => ['project_id' => $p->id]])</x-card>
    @elseif ($tab === 'info')
        <x-card>
            <dl class="grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-[12rem,1fr]">
                <dt class="muted">{{ t('projects.fields.client') }}</dt><dd>{{ $p->client?->name }}</dd>
                <dt class="muted">{{ t('projects.fields.deal') }}</dt>
                <dd>@if ($p->sees_deal && $p->deal)<a class="link" href="/sales/deals/{{ $p->deal->id }}">{{ Format::code('D', $p->deal->number) }} · {{ $p->deal->title }}</a>@else — @endif</dd>
                <dt class="muted">{{ t('projects.fields.price') }}</dt><dd>{{ $p->sees_money ? money($p->price, $p->currency) : t('projects.noMoney') }}</dd>
                <dt class="muted">{{ t('projects.direction') }}</dt><dd>{{ $p->direction?->name ?? t('projects.noDirection') }}</dd>
                <dt class="muted">{{ t('projects.fields.rop') }}</dt><dd>{{ $p->rop?->full_name }}</dd>
                <dt class="muted">{{ t('projects.fields.manager') }}</dt><dd>{{ $p->manager?->full_name }}</dd>
                <dt class="muted">{{ t('projects.fields.startDate') }}</dt><dd>{{ d($p->start_date) }}</dd>
                <dt class="muted">{{ t('projects.fields.deadline') }}</dt><dd>{{ d($p->deadline) }}</dd>
                <dt class="muted">{{ t('projects.fields.template') }}</dt><dd>{{ $p->template?->name ?? '—' }}</dd>
                <dt class="muted">{{ t('projects.fields.completedAt') }}</dt><dd>{{ $p->completed_at ? dt($p->completed_at) : '—' }}</dd>
            </dl>
            @if ($p->description)<p class="mt-5 whitespace-pre-wrap text-sm">{{ $p->description }}</p>@endif
        </x-card>
    @elseif ($tab === 'history')
        <x-card>@include('projects.partials.timeline')</x-card>
    @endif

    @isset($openTask)
        @include('projects.partials.task-detail', ['task' => $openTask])
    @endisset

    @if ($p->can['update'])
        <x-modal name="project-status" :title="t('projects.changeStatus')">
            <form method="POST" action="{{ $base }}/status" class="space-y-4" x-data="{ status: @js($p->status) }">
                @csrf
                <div>
                    <label class="label" for="ps-status">{{ t('projects.fields.status') }}</label>
                    <select id="ps-status" name="status" class="input" x-model="status">
                        @foreach (ProjectAccess::STATUSES as $s)<option value="{{ $s }}">{{ t('sales.projectStatus.'.$s) }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="label" for="ps-comment" x-text="status === 'CANCELLED' ? @js(t('projects.cancelReason')) : @js(t('projects.comment'))"></label>
                    <textarea id="ps-comment" name="comment" rows="3" class="input" :required="status === 'CANCELLED'"></textarea>
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" class="btn btn-outline" @click="$dispatch('close-modal', 'project-status')">{{ t('common.cancel') }}</button>
                    <button class="btn btn-primary" :disabled="status === @js($p->status)">{{ t('projects.changeStatus') }}</button>
                </div>
            </form>
        </x-modal>

        <x-modal name="project-edit" :title="t('projects.editTitle')" :open="$errors->hasAny(['name', 'deadline', 'start_date', 'direction_id', 'rop_id'])">
            <form method="POST" action="{{ $base }}" class="space-y-4">
                @csrf @method('PATCH')
                <x-field name="name" :label="t('projects.fields.name')" :value="$p->name" required />
                <div class="grid gap-4 sm:grid-cols-3">
                    <x-select name="priority" :label="t('projects.fields.priority')" :value="$p->priority" :options="collect(ProjectAccess::PRIORITIES)->mapWithKeys(fn ($v) => [$v => t('crm.priority.'.$v)])->all()" />
                    <x-field name="start_date" type="date" :label="t('projects.fields.startDate')" :value="$p->start_date?->format('Y-m-d')" />
                    <x-field name="deadline" type="date" :label="t('projects.fields.deadline')" :value="$p->deadline?->format('Y-m-d')" />
                </div>
                @if (access()->can('project.update', 'ALL'))
                    {{-- Направление решает, кто видит проект, — меняет только CEO --}}
                    <x-select name="direction_id" :label="t('projects.direction')" :value="$p->direction_id" :placeholder="t('projects.noDirection')" :options="$directions->pluck('name', 'id')->all()" />
                @endif
                <x-field name="description" type="textarea" rows="4" :label="t('projects.fields.description')" :value="$p->description" />
                <div class="flex justify-end gap-2">
                    <button type="button" class="btn btn-outline" @click="$dispatch('close-modal', 'project-edit')">{{ t('common.cancel') }}</button>
                    <button class="btn btn-primary">{{ t('common.save') }}</button>
                </div>
            </form>
        </x-modal>

        @if ($open)
            <x-modal name="project-template" :title="t('projects.applyTemplate')">
                <p class="mb-4 text-sm text-muted-foreground">{{ t('projects.templateHint') }}</p>
                <form method="POST" action="{{ $base }}/apply-template" class="space-y-4" x-data="{ tpl: '' }">
                    @csrf
                    <div>
                        <label class="label" for="tpl">{{ t('templates.title') }}</label>
                        <select id="tpl" name="template_id" class="input" required x-model="tpl">
                            <option value="">—</option>
                            @foreach ($templates as $tplItem)
                                <option value="{{ $tplItem->id }}">{{ $tplItem->name }} · {{ t('templates.count', ['count' => $tplItem->tasks->count()]) }}</option>
                            @endforeach
                        </select>
                    </div>
                    @foreach ($templates as $tplItem)
                        <ol x-show="tpl === @js($tplItem->id)" x-cloak class="list-decimal pl-5 text-sm text-muted-foreground">
                            @foreach ($tplItem->tasks as $tt)<li>{{ $tt->title }}</li>@endforeach
                        </ol>
                    @endforeach
                    <div class="flex justify-end gap-2">
                        <button type="button" class="btn btn-outline" @click="$dispatch('close-modal', 'project-template')">{{ t('common.cancel') }}</button>
                        <button class="btn btn-primary" :disabled="!tpl">{{ t('projects.applyTemplate') }}</button>
                    </div>
                </form>
            </x-modal>
        @endif
    @endif
    @if ($p->can['createTasks'] && $open)
        @include('projects.partials.quick-task')
    @endif
@endsection
