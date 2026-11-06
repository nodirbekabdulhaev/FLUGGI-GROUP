{{-- Карточка задачи: статус, прогресс, файлы, комментарии и история (ТЗ §22, §3.4) --}}
@php
    use App\Services\Projects\ProjectAccess;
    use App\Services\Projects\TaskService;
    $isAssignee = $task->assignee_id === access()->id();
    $canMove = $open && ($task->can_edit || $isAssignee);
    $statuses = array_filter(ProjectAccess::TASK_STATUSES, fn ($s) => $s !== $task->status
        && ($task->can_edit || ($s !== 'CANCELLED' && ! in_array($task->status, ['DONE', 'CANCELLED'], true))));
    $taskFiles = \App\Models\StoredFile::with('uploadedBy:id,full_name')->where('task_id', $task->id)->orderByDesc('created_at')->get();
@endphp
<x-modal name="task-detail" :title="TaskService::code($task).' · '.$task->title" size="max-w-2xl" :open="true">
    <div class="space-y-5">
        <div class="flex flex-wrap items-center gap-2">
            @include('projects.partials.task-status', ['status' => $task->status])
            @include('projects.partials.overdue', ['days' => $task->overdue_days])
            @include('projects.partials.priority', ['priority' => $task->priority])
            @if ($task->can_edit && $open)
                <div class="ml-auto flex gap-1">
                    <button type="button" class="btn btn-outline btn-sm" @click="$dispatch('open-modal', 'task-edit')"><x-icon name="edit" /> {{ t('common.edit') }}</button>
                    <form method="POST" action="/tasks/{{ $task->id }}" data-confirm="{{ t('tasks.deleteConfirm', ['title' => $task->title]) }}">
                        @csrf @method('DELETE')
                        <button class="btn btn-ghost btn-icon" aria-label="{{ t('common.delete') }}"><x-icon name="trash" /></button>
                    </form>
                </div>
            @endif
        </div>

        @if ($canMove)
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-sm text-muted-foreground">{{ t('tasks.moveTo') }}:</span>
                @foreach ($statuses as $s)
                    <form method="POST" action="/tasks/{{ $task->id }}/move">@csrf
                        <input type="hidden" name="status" value="{{ $s }}">
                        <button class="btn btn-sm {{ $s === 'DONE' ? 'btn-accent' : 'btn-outline' }}">{{ t('tasks.status.'.$s) }}</button>
                    </form>
                @endforeach
            </div>
        @endif

        <dl class="grid grid-cols-[auto,1fr] gap-x-4 gap-y-1.5 text-sm">
            <dt class="muted">{{ t('tasks.fields.project') }}</dt><dd>{{ \App\Support\Format::code('P', $task->project->number) }} · {{ $task->project->name }}</dd>
            <dt class="muted">{{ t('tasks.fields.assignee') }}</dt>
            <dd>{{ $task->assignee?->full_name }}@if ($task->waiting_for_role) ({{ t('tasks.waitingRole', ['role' => t('specialties.'.$task->template_role)]) }})@endif</dd>
            <dt class="muted">{{ t('tasks.fields.creator') }}</dt><dd>{{ $task->creator?->full_name }}</dd>
            <dt class="muted">{{ t('tasks.fields.startDate') }}</dt><dd>{{ d($task->start_date) }}</dd>
            <dt class="muted">{{ t('tasks.fields.deadline') }}</dt><dd>{{ dt($task->deadline) }}</dd>
            <dt class="muted">{{ t('tasks.fields.rework') }}</dt><dd>{{ $task->rework_count }}</dd>
        </dl>

        <form method="POST" action="/tasks/{{ $task->id }}" class="grid gap-2" x-data="{ v: {{ (int) $task->progress_pct }} }">
            @csrf @method('PATCH')
            <label for="task-progress" class="text-sm font-medium">{{ t('tasks.fields.progress') }}: <span x-text="v"></span>%</label>
            <div class="flex items-center gap-3">
                <input id="task-progress" type="range" name="progress_pct" min="0" max="100" step="5" x-model="v" class="flex-1 accent-current" @disabled(! $canMove || $task->status === 'DONE')>
                @if ($canMove && $task->status !== 'DONE')<button class="btn btn-outline btn-sm">{{ t('common.save') }}</button>@endif
            </div>
        </form>

        @if ($task->description)<p class="whitespace-pre-wrap rounded-md bg-muted/50 p-3 text-sm">{{ $task->description }}</p>@endif

        <section class="grid gap-2">
            <h3 class="text-sm font-semibold">{{ t('tasks.files') }}</h3>
            @include('projects.partials.files', ['files' => $taskFiles, 'owner' => ['task_id' => $task->id]])
        </section>

        <section class="grid gap-3">
            <h3 class="text-sm font-semibold">{{ t('tasks.comments') }}</h3>
            @forelse ($task->comments as $c)
                <div class="rounded-md border p-3 text-sm">
                    <p class="mb-1 text-xs text-muted-foreground">{{ $c->author?->full_name }} · {{ dt($c->created_at) }}</p>
                    <p class="whitespace-pre-wrap">{{ $c->body }}</p>
                </div>
            @empty
                <p class="text-sm text-muted-foreground">{{ t('tasks.noComments') }}</p>
            @endforelse
            <form method="POST" action="/tasks/{{ $task->id }}/comments" class="grid gap-2">
                @csrf
                <textarea name="body" rows="2" class="input" required placeholder="{{ t('tasks.commentPlaceholder') }}" aria-label="{{ t('tasks.commentPlaceholder') }}"></textarea>
                <button class="btn btn-primary btn-sm justify-self-end">{{ t('tasks.send') }}</button>
            </form>
        </section>

        <section class="grid gap-2">
            <h3 class="text-sm font-semibold">{{ t('tasks.history') }}</h3>
            <ol class="grid gap-1.5 text-sm">
                @foreach ($task->history as $h)
                    <li class="flex flex-wrap items-center gap-1.5">
                        <span class="text-xs text-muted-foreground">{{ dt($h->created_at) }} · {{ $h->changedBy?->full_name }}</span>
                        @if ($h->from_status)@include('projects.partials.task-status', ['status' => $h->from_status]) <x-icon name="chevron-right" class="size-3" />@endif
                        @include('projects.partials.task-status', ['status' => $h->to_status])
                    </li>
                @endforeach
            </ol>
        </section>
    </div>
</x-modal>
@if ($task->can_edit && $open)
    <x-modal name="task-edit" :title="TaskService::code($task).' · '.t('tasks.fields.title')" :open="$errors->any()">
        @include('projects.partials.task-form', ['action' => '/tasks/'.$task->id, 'method' => 'PATCH', 'task' => $task])
    </x-modal>
@endif
