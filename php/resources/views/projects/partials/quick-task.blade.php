{{-- «Поставить задачу» в шапке проекта: задача в Kanban проекта. С правом project.assign можно выбрать
     любого сотрудника из кандидатов — он будет добавлен в команду; без права — только команда проекта. --}}
@php
    $quickTeam = $assignees;
    $quickOthers = $p->can['assign'] ? collect($candidates ?? [])->reject(fn ($u) => isset($quickTeam[$u->id]))->pluck('full_name', 'id')->all() : [];
    $quickReopen = old('_form') === 'project-quick-task' && $errors->any();
    $quickValue = (string) ($quickReopen ? old('assignee_id') : (isset($quickTeam[access()->id()]) || isset($quickOthers[access()->id()]) ? access()->id() : ''));
    $quickPrio = collect(\App\Services\Projects\ProjectAccess::PRIORITIES)->mapWithKeys(fn ($v) => [$v => t('crm.priority.'.$v)])->all();
@endphp
<x-modal name="project-quick-task" :title="t('todos.assignTask')" :open="$quickReopen">
    <form method="POST" action="/tasks" class="grid gap-4">
        @csrf
        <input type="hidden" name="_form" value="project-quick-task">
        <input type="hidden" name="project_id" value="{{ $p->id }}">
        <input type="hidden" name="add_member" value="1">
        <x-field name="title" id="pq-title" :label="t('tasks.fields.title')" :value="$quickReopen ? old('title') : ''" maxlength="300" required />
        <x-field name="description" id="pq-desc" type="textarea" rows="3" :label="t('tasks.fields.description')" :value="$quickReopen ? old('description') : ''" />
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="pq-assignee" class="label">{{ t('todos.owner') }}<span class="text-danger"> *</span></label>
                <select id="pq-assignee" name="assignee_id" class="input" required>
                    <option value="">—</option>
                    <optgroup label="{{ t('projects.tabs.team') }}">
                        @foreach ($quickTeam as $id => $name)<option value="{{ $id }}" @selected($quickValue === (string) $id)>{{ $name }}</option>@endforeach
                    </optgroup>
                    @if ($quickOthers)
                        <optgroup label="{{ t('tasks.candidateGroup') }}">
                            @foreach ($quickOthers as $id => $name)<option value="{{ $id }}" @selected($quickValue === (string) $id)>{{ $name }}</option>@endforeach
                        </optgroup>
                    @endif
                </select>
                @error('assignee_id')<p class="error">{{ $message }}</p>@else @if ($quickOthers)<p class="hint">{{ t('tasks.addMemberHint') }}</p>@endif @enderror
            </div>
            <x-select name="priority" id="pq-priority" :label="t('tasks.fields.priority')" :value="$quickReopen ? old('priority') : 'MEDIUM'" :options="$quickPrio" />
            <x-field name="deadline" id="pq-deadline" type="datetime-local" :label="t('tasks.fields.deadline')" :value="$quickReopen ? old('deadline') : ''" class="sm:col-span-2" />
        </div>
        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <button type="button" class="btn btn-outline" @click="$dispatch('close-modal', 'project-quick-task')">{{ t('common.cancel') }}</button>
            <button class="btn btn-primary">{{ t('todos.assignTask') }}</button>
        </div>
    </form>
</x-modal>
