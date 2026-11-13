{{-- Окно «Поставить задачу» из карточки: дело (todo) со связью $column = $recordId, $back — куда вернуться --}}
@php
    $taskOwners = \App\Services\Todos\TodoService::assignees(access());
    $reopenTask = old('_form') === 'record-task' && $errors->any();
@endphp
<x-modal name="record-task" :title="t('todos.assignTask')" :open="$reopenTask">
    <form method="POST" action="{{ route('todos.store') }}" class="grid gap-4">
        @csrf
        <input type="hidden" name="_form" value="record-task">
        <input type="hidden" name="{{ $column }}" value="{{ $recordId }}">
        <input type="hidden" name="_back" value="{{ $back }}">
        <input type="hidden" name="kind" value="TASK">
        <x-field name="title" id="rt-task-title" :label="t('todos.title')" :value="$reopenTask ? old('title') : ''" maxlength="300" required />
        <x-field name="description" id="rt-task-desc" type="textarea" rows="3" :label="t('todos.description')" :value="$reopenTask ? old('description') : ''" maxlength="4000" />
        <div class="grid gap-4 sm:grid-cols-2">
            <x-select name="owner_id" id="rt-task-owner" :label="t('todos.owner')" :options="$taskOwners" :value="$reopenTask ? old('owner_id') : access()->id()" required />
            <x-select name="priority" id="rt-task-priority" :label="t('todos.priority')" :value="$reopenTask ? old('priority') : 'MEDIUM'"
                      :options="collect(\App\Services\Todos\TodoService::PRIORITIES)->mapWithKeys(fn ($p) => [$p => t('crm.priority.'.$p)])->all()" />
            <x-field name="due_at" id="rt-task-due" type="datetime-local" :label="t('todos.due')" :value="$reopenTask ? old('due_at') : ''" class="sm:col-span-2" />
        </div>
        <p class="hint">{{ t('todos.assignHint') }}</p>
        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <button type="button" class="btn btn-outline" x-on:click="$dispatch('close-modal', 'record-task')">{{ t('todos.cancel') }}</button>
            <button class="btn btn-primary">{{ t('todos.assignTask') }}</button>
        </div>
    </form>
</x-modal>
