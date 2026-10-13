{{-- Блок «Задачи» карточки (лид, сделка, клиент, идея SMM): $column — поле связи (lead_id…), $recordId --}}
@php
    use App\Services\Todos\TodoService;
    $recordTodos = TodoService::forRecord($column, $recordId);
    $todoAccess = access();
    $todoDots = ['URGENT' => 'bg-danger', 'HIGH' => 'bg-warning', 'MEDIUM' => 'bg-success', 'LOW' => 'bg-muted-foreground/40'];
@endphp
<div class="grid gap-3">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <p class="text-xs text-muted-foreground">{{ t('todos.assignHint') }}</p>
        <button type="button" class="btn btn-outline btn-sm" x-data x-on:click="$dispatch('open-modal', 'record-task')"><x-icon name="plus" /> {{ t('todos.assignTask') }}</button>
    </div>
    @if ($recordTodos->isEmpty())
        <x-empty icon="list" :title="t('todos.tasksEmpty')">{{ t('todos.tasksEmptyText') }}</x-empty>
    @else
        <ul class="divide-y" data-record-tasks>
            @foreach ($recordTodos as $x)
                @php($canTodo = TodoService::canEdit($todoAccess, $x))
                <li class="flex items-start gap-3 py-3 text-sm">
                    @if ($canTodo)
                        <form method="POST" action="{{ $x->status === 'OPEN' ? route('todos.complete', $x->id) : route('todos.reopen', $x->id) }}" class="pt-0.5">@csrf
                            <input type="checkbox" class="size-4 cursor-pointer" @checked($x->status === 'DONE') onchange="this.form.submit()"
                                   aria-label="{{ $x->status === 'OPEN' ? t('todos.complete', ['title' => $x->title]) : t('todos.reopen') }}">
                        </form>
                    @else
                        <input type="checkbox" class="mt-0.5 size-4" @checked($x->status === 'DONE') disabled aria-label="{{ $x->title }}">
                    @endif
                    <div class="min-w-0 flex-1">
                        <p class="flex items-start gap-2">
                            <span class="mt-1.5 size-2 shrink-0 rounded-full {{ $todoDots[$x->priority] }}"></span>
                            <span @class(['break-words font-medium', 'text-muted-foreground line-through' => $x->status === 'DONE'])>{{ $x->title }}</span>
                        </p>
                        @if ($x->description)<p class="mt-0.5 whitespace-pre-wrap break-words pl-4 text-muted-foreground">{{ $x->description }}</p>@endif
                        <p class="mt-1 flex flex-wrap gap-x-3 gap-y-1 pl-4 text-xs text-muted-foreground">
                            <span class="flex items-center gap-1"><x-icon name="user" class="size-3.5" /> {{ $x->owner?->full_name }}</span>
                            <span @class(['flex items-center gap-1', 'font-medium text-danger' => TodoService::overdue($x)])><x-icon name="calendar" class="size-3.5" /> {{ TodoService::overdue($x) ? t('todos.overdue').' · ' : '' }}{{ TodoService::dueLabel($x->due_at) }}</span>
                            @if ($x->creator_id !== $x->owner_id)<span>{{ t('todos.from') }}: {{ $x->creator?->full_name }}</span>@endif
                            @if ($x->status === 'DONE')<x-badge color="green">{{ t('todos.done') }}@if ($x->completed_at) · {{ dt($x->completed_at) }}@endif</x-badge>@endif
                        </p>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
