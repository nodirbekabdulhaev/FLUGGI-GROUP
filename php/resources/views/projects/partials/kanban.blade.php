{{-- Kanban проекта (ТЗ §23): перетаскивание карточки меняет статус задачи --}}
@php
    use App\Services\Projects\ProjectAccess;
    $items = $tasks->getCollection();
    $other = $items->whereIn('status', ['BLOCKED', 'CANCELLED']);
    $uid = access()->id();
    $colors = \App\Support\BoardColors::for('tasks');
    $canAdd = $p->can['createTasks'] && $open;
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
    {{-- Колонки окрашены в цвета статусов (Настройки → Цвета досок) --}}
    <div class="board" data-board>
        <div class="board-scroll flex items-start gap-3" x-hscroll>
            @foreach (ProjectAccess::KANBAN as $status)
                @php($col = $items->where('status', $status))
                <section class="kanban-col lg:w-auto lg:min-w-[15rem] lg:flex-1" style="--col: {{ $colors[$status] }}">
                    <header class="kanban-head">
                        <span class="kanban-count">{{ $col->count() }}</span>
                        <h3 class="kanban-title">{{ t('tasks.status.'.$status) }}</h3>
                        @if ($canAdd && $status === 'TODO')
                            <button type="button" class="kanban-add-icon" @click="$dispatch('open-modal', 'task-new')" aria-label="{{ t('ui.kanban.addTo', ['name' => t('tasks.status.'.$status)]) }}"><x-icon name="plus" class="size-4" /></button>
                        @endif
                    </header>
                    <div class="kanban-body min-h-32" data-status="{{ $status }}" data-testid="column-{{ $status }}"
                         :class="over === '{{ $status }}' && 'is-over'"
                         @dragover.prevent="over = '{{ $status }}'" @dragleave="over = null" @drop.prevent="drop($event, '{{ $status }}', null)">
                        @if ($col->isEmpty())<p class="kanban-empty">{{ t('ui.kanban.empty') }}</p>@endif
                        @foreach ($col as $task)
                            @php($draggable = $open && ($task->can_edit || $task->assignee_id === $uid))
                            <article @class(['kanban-card', 'cursor-grab' => $draggable])
                                 @if ($draggable) draggable="true" @dragstart="drag($event, '{{ $task->id }}')" @endif
                                 @dragover.prevent.stop="over = '{{ $status }}'" @drop.prevent.stop="drop($event, '{{ $status }}', '{{ $task->id }}')">
                                <a href="/projects/{{ $p->id }}?task={{ $task->id }}" class="font-semibold leading-snug text-foreground hover:underline">{{ $task->title }}</a>
                                <p class="mt-0.5 text-xs text-muted-foreground">{{ \App\Services\Projects\TaskService::code($task) }}</p>
                                <div class="mt-3 flex items-center gap-2 text-xs">
                                    <x-avatar :name="$task->assignee?->full_name" />
                                    <span class="min-w-0 flex-1 truncate text-muted-foreground">{{ $task->assignee?->full_name }}@if ($task->waiting_for_role) · {{ t('tasks.waitingRole', ['role' => t('specialties.'.$task->template_role)]) }}@endif</span>
                                </div>
                                <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                                    @if ($task->deadline)<span @class(['flex items-center gap-1', 'text-danger' => $task->overdue_days > 0])><x-icon name="clock" class="size-3.5" /> {{ dt($task->deadline) }}</span>@endif
                                    @if ($task->comments_count)<span class="flex items-center gap-1"><x-icon name="message" class="size-3.5" /> {{ $task->comments_count }}</span>@endif
                                    @if ($task->progress_pct > 0 && $task->status !== 'DONE')<span>{{ $task->progress_pct }}%</span>@endif
                                    <x-priority :level="$task->priority" class="ml-auto" />
                                </div>
                                @if ($task->overdue_days > 0)
                                    <div class="mt-2">@include('projects.partials.overdue', ['days' => $task->overdue_days])</div>
                                @endif
                            </article>
                        @endforeach
                    </div>
                    @if ($canAdd && $status === 'TODO')
                        <button type="button" class="kanban-add" @click="$dispatch('open-modal', 'task-new')"><x-icon name="plus" class="size-4" /> {{ t('ui.kanban.add') }}</button>
                    @endif
                </section>
            @endforeach
        </div>
    </div>
    @if ($other->isNotEmpty())
        <section class="grid gap-2">
            <h3 class="text-sm font-semibold">{{ t('tasks.other') }}</h3>
            <ul class="card divide-y divide-border/70">
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
