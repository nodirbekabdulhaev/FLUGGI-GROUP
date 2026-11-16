{{-- Форма шаблона проекта: задачи — динамические строки (Alpine) --}}
@php
    use App\Services\Projects\ProjectAccess;
    $rows = $template ? $template->tasks->map(fn ($x) => ['title' => $x->title, 'role' => $x->role ?? '', 'start_offset_days' => $x->start_offset_days, 'duration_days' => $x->duration_days, 'priority' => $x->priority])->values()->all()
        : [['title' => '', 'role' => '', 'start_offset_days' => 0, 'duration_days' => 1, 'priority' => 'MEDIUM']];
@endphp
<form method="POST" action="{{ $template ? '/settings/templates/'.$template->id : '/settings/templates' }}" class="space-y-4"
      x-data="{ rows: @js($rows), add() { this.rows.push({ title: '', role: '', start_offset_days: 0, duration_days: 1, priority: 'MEDIUM' }) } }">
    @csrf
    @if ($template) @method('PUT') @endif
    <div class="grid gap-4 sm:grid-cols-[1fr,1fr,auto] sm:items-end">
        <x-field name="name" :id="'tpl-name-'.($template?->id ?? 'new')" :label="t('templates.name')" :value="$template?->name" required />
        <x-select name="service_id" :id="'tpl-svc-'.($template?->id ?? 'new')" :label="t('templates.service')" :value="$template?->service_id" :placeholder="t('templates.noService')" :options="$services" />
        <label class="flex h-9 items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked($template?->is_active ?? true)> {{ t('templates.active') }}</label>
    </div>
    <div class="grid gap-2">
        <p class="text-sm font-medium">{{ t('templates.tasks') }}</p>
        <p class="text-xs text-muted-foreground">{{ t('templates.hint') }}</p>
        <template x-for="(r, i) in rows" :key="i">
            <div class="grid gap-2 rounded-md border p-2 sm:grid-cols-[1fr,9rem,5rem,5rem,7rem,auto] sm:border-0 sm:p-0">
                <input class="input" :name="`tasks[${i}][title]`" x-model="r.title" required placeholder="{{ t('templates.taskTitle') }}" aria-label="{{ t('templates.taskTitle') }}">
                <select class="input" :name="`tasks[${i}][role]`" x-model="r.role" aria-label="{{ t('templates.role') }}">
                    <option value="">{{ t('templates.anyRole') }}</option>
                    @foreach (ProjectAccess::SPECIALTIES as $s)<option value="{{ $s }}">{{ t('specialties.'.$s) }}</option>@endforeach
                </select>
                <input class="input" type="number" min="0" max="365" :name="`tasks[${i}][start_offset_days]`" x-model="r.start_offset_days" title="{{ t('templates.offset') }}" aria-label="{{ t('templates.offset') }}">
                <input class="input" type="number" min="1" max="365" :name="`tasks[${i}][duration_days]`" x-model="r.duration_days" title="{{ t('templates.duration') }}" aria-label="{{ t('templates.duration') }}">
                <select class="input" :name="`tasks[${i}][priority]`" x-model="r.priority" aria-label="{{ t('tasks.fields.priority') }}">
                    @foreach (ProjectAccess::PRIORITIES as $pr)<option value="{{ $pr }}">{{ t('crm.priority.'.$pr) }}</option>@endforeach
                </select>
                <button type="button" class="btn btn-ghost btn-icon" :disabled="rows.length === 1" @click="rows.splice(i, 1)" aria-label="{{ t('templates.removeTask') }}"><x-icon name="trash" /></button>
            </div>
        </template>
        <button type="button" class="btn btn-outline btn-sm justify-self-start" @click="add()"><x-icon name="plus" /> {{ t('templates.addTask') }}</button>
        @error('tasks')<p class="error">{{ $message }}</p>@enderror
    </div>
    <div class="flex justify-end gap-2">
        <button type="button" class="btn btn-outline" @click="$dispatch('close-modal', '{{ $modal }}')">{{ t('common.cancel') }}</button>
        <button class="btn btn-primary">{{ t('common.save') }}</button>
    </div>
</form>
