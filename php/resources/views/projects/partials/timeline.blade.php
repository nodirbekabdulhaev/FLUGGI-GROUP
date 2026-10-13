{{-- История проекта: действия по проекту и его задачам --}}
@if ($timeline->isEmpty())
    <x-empty :title="t('crm.common.noActivity')" />
@else
    <ol class="relative grid gap-4 border-l pl-5">
        @foreach ($timeline as $a)
            @php($pl = $a->payload ?? [])
            @php($label = \App\Support\Lang::has('crm.activity.'.$a->type) ? t('crm.activity.'.$a->type) : $a->type)
            <li class="relative text-sm">
                <span class="absolute -left-[1.6rem] top-1.5 size-2.5 rounded-full border-2 border-surface bg-accent" aria-hidden="true"></span>
                <p class="text-xs text-muted-foreground">{{ dt($a->created_at) }} · {{ $a->actor?->full_name ?? t('settings.auditSystem') }}</p>
                <p class="mt-0.5">
                    @switch($a->type)
                        @case('project.status_changed')
                            {{ $label }}: {{ t('sales.projectStatus.'.($pl['from'] ?? '')) }} → {{ t('sales.projectStatus.'.($pl['to'] ?? '')) }}@if (! empty($pl['comment'])) («{{ $pl['comment'] }}»)@endif
                            @if (! empty($pl['auto'])) <span class="text-xs text-muted-foreground">({{ t('crm.common.auto') }})</span>@endif
                            @break
                        @case('task.status_changed')
                            {{ $label }} «{{ $pl['title'] ?? '' }}»: @include('projects.partials.task-status', ['status' => $pl['from'] ?? 'TODO']) → @include('projects.partials.task-status', ['status' => $pl['to'] ?? 'TODO'])
                            @break
                        @case('task.created') @case('task.updated') @case('task.deleted') @case('task.commented')
                            {{ $label }} «{{ $pl['title'] ?? '' }}»@if (! empty($pl['assignee'])) — {{ t('tasks.forAssignee', ['name' => $pl['assignee']]) }}@endif
                            @break
                        @case('project.template_applied')
                            {{ $label }} «{{ $pl['template'] ?? '' }}» ({{ $pl['tasks'] ?? 0 }})
                            @break
                        @case('project.member_added') @case('project.member_removed')
                            {{ $label }}: {{ $pl['user'] ?? '' }}
                            @break
                        @default
                            {{ $label }}
                    @endswitch
                </p>
            </li>
        @endforeach
    </ol>
@endif
