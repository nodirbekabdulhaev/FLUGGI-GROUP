{{-- Форма задачи (создание/изменение). $task — null для новой --}}
@php($prio = collect(\App\Services\Projects\ProjectAccess::PRIORITIES)->mapWithKeys(fn ($v) => [$v => t('crm.priority.'.$v)])->all())
<form method="POST" action="{{ $action }}" class="space-y-4">
    @csrf
    @if ($method !== 'POST') @method($method) @endif
    @if (! $task)<input type="hidden" name="project_id" value="{{ $p->id }}">@endif
    <x-field name="title" :label="t('tasks.fields.title')" :value="$task?->title" required />
    <div class="grid gap-4 sm:grid-cols-2">
        <x-select name="assignee_id" :label="t('tasks.fields.assignee')" :value="$task?->assignee_id" placeholder="—" :options="$assignees" required />
        <x-select name="priority" :label="t('tasks.fields.priority')" :value="$task?->priority ?? 'MEDIUM'" :options="$prio" />
        <x-field name="start_date" type="date" :label="t('tasks.fields.startDate')" :value="$task?->start_date?->format('Y-m-d')" />
        <x-field name="deadline" type="datetime-local" :label="t('tasks.fields.deadline')" :value="\App\Support\Format::toLocalInput($task?->deadline)" />
    </div>
    <x-field name="description" type="textarea" rows="4" :label="t('tasks.fields.description')" :value="$task?->description" />
    <div class="flex justify-end gap-2">
        <button type="button" class="btn btn-outline" @click="$dispatch('close-modal', '{{ $task ? 'task-edit' : 'task-new' }}')">{{ t('common.cancel') }}</button>
        <button class="btn btn-primary">{{ $task ? t('common.save') : t('tasks.new') }}</button>
    </div>
</form>
