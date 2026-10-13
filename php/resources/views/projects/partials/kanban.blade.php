{{-- Kanban проекта (ТЗ §23): перетаскивание карточки меняет статус задачи --}}
@php
    use App\Services\Projects\ProjectAccess;
    $items = $tasks->getCollection();
    $other = $items->whereIn('status', ['BLOCKED', 'CANCELLED']);
    $uid = access()->id();
    $border = ['LOW' => 'border-l-muted-foreground/30', 'MEDIUM' => 'border-l-accent/60', 'HIGH' => 'border-l-warning', 'URGENT' => 'border-l-danger'];
@endphp
<div class="grid gap-4" x-data="fluggiKanban(@js(t('tasks.moved')))">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-xs text-muted-foreground">
            {{ $p->can['createTasks'] ? t('tasks.kanbanHint') : t('tasks.onlyMine') }}
            @if ($tasks->total() > $items->count()) · {{ t('tasks.shownFirst', ['count' => $items->count()]) }}@endif
        </p>
        @if ($p->can['createTasks'] && $open)
            <button type="button" class="btn btn-primary btn-sm" @click="$dispatch('open-modal', 'task-new')"><x-icon name="plus" /> {{ t('tasks.new') }}</button>
        @endif
    </div>
    <div class="-mx-4 flex gap-3 overflow-x-auto px-4 pb-2 sm:mx-0 sm:px-0">
        @foreach (ProjectAccess::KANBAN as $status)
            @php($col = $items->where('status', $status))
            <div class="flex w-72 shrink-0 flex-col rounded-lg bg-muted/60 lg:w-auto lg:min-w-0 lg:flex-1">
                <div class="flex items-center gap-2 border-b border-border/60 p-3">
                    <h3 class="flex-1 truncate text-sm font-semibold">{{ t('tasks.status.'.$status) }}</h3>
                    <span class="rounded-full bg-surface px-2 text-xs">{{ $col->count() }}</span>
                </div>
                <div class="grid min-h-32 flex-1 content-start gap-2 p-2 transition-colors" data-status="{{ $status }}" data-testid="column-{{ $status }}"
                     :class="over === '{{ $status }}' && 'bg-accent-soft'"
                     @dragover.prevent="over = '{{ $status }}'" @dragleave="over = null" @drop.prevent="drop($event, '{{ $status }}', null)">
                    @foreach ($col as $task)
                        @php($draggable = $open && ($task->can_edit || $task->assignee_id === $uid))
                        <div @class(['rounded-md border border-l-4 bg-surface p-3 text-sm shadow-sm', $border[$task->priority] ?? '', 'cursor-grab' => $draggable])
                             @if ($draggable) draggable="true" @dragstart="drag($event, '{{ $task->id }}')" @endif
                             @dragover.prevent.stop="over = '{{ $status }}'" @drop.prevent.stop="drop($event, '{{ $status }}', '{{ $task->id }}')">
                            <a href="/projects/{{ $p->id }}?task={{ $task->id }}" class="font-medium leading-snug hover:underline">{{ $task->title }}</a>
                            <p class="mt-0.5 text-xs text-muted-foreground">{{ \App\Services\Projects\TaskService::code($task) }}</p>
                            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                                <span class="flex items-center gap-1"><x-icon name="user" class="size-3" />{{ $task->assignee?->full_name }}@if ($task->waiting_for_role) · {{ t('tasks.waitingRole', ['role' => t('specialties.'.$task->template_role)]) }}@endif</span>
                                @if ($task->deadline)<span class="flex items-center gap-1"><x-icon name="clock" class="size-3" /> {{ dt($task->deadline) }}</span>@endif
                                @if ($task->comments_count)<span class="flex items-center gap-1"><x-icon name="message" class="size-3" /> {{ $task->comments_count }}</span>@endif
                            </div>
                            @if ($task->overdue_days > 0 || ($task->progress_pct > 0 && $task->status !== 'DONE'))
                                <div class="mt-2 flex items-center gap-2">
                                    @include('projects.partials.overdue', ['days' => $task->overdue_days])
                                    @if ($task->progress_pct > 0 && $task->status !== 'DONE')<span class="text-xs text-muted-foreground">{{ $task->progress_pct }}%</span>@endif
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
    @if ($other->isNotEmpty())
        <section class="grid gap-2">
            <h3 class="text-sm font-semibold">{{ t('tasks.other') }}</h3>
            <ul class="divide-y rounded-lg border bg-surface">
                @foreach ($other as $task)
                    <li class="flex items-center gap-3 px-3 py-2 text-sm">
                        @include('projects.partials.task-status', ['status' => $task->status])
                        <a href="/projects/{{ $p->id }}?task={{ $task->id }}" class="min-w-0 flex-1 truncate hover:underline">{{ $task->title }}</a>
                        <span class="text-xs text-muted-foreground">{{ $task->assignee?->full_name }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>

@if ($p->can['createTasks'] && $open)
    <x-modal name="task-new" :title="t('tasks.new')" :open="$errors->any() && old('project_id') === $p->id && old('_form') !== 'project-quick-task'">
        @include('projects.partials.task-form', ['action' => '/tasks', 'method' => 'POST', 'task' => null])
    </x-modal>
@endif

@push('scripts')
<script>
    // Kanban: карточку перетаскивают в колонку или перед другой карточкой; сервер проверяет права
    window.fluggiKanban = (okText) => ({
        over: null,
        dragging: null,
        drag(e, id) { this.dragging = id; e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', id); },
        async drop(e, status, beforeId) {
            this.over = null;
            const id = this.dragging || e.dataTransfer.getData('text/plain');
            this.dragging = null;
            if (!id || id === beforeId) return;
            try {
                await window.api(`/tasks/${id}/move`, { method: 'POST', body: { status, before_id: beforeId } });
                window.toast(okText);
                window.location.reload();
            } catch (err) {
                window.toast(err.message, 'error');
            }
        },
    });
</script>
@endpush
