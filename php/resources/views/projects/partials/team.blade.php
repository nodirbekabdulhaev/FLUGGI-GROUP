{{-- Команда проекта (ТЗ §21): позиции (несколько у одного человека), дата назначения, загрузка, задачи, дедлайн, статус --}}
@php($canAssign = $p->can['assign'] && $open)
<div class="grid gap-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-muted-foreground">{{ t('projects.team.leads') }}:
            <span class="text-foreground">{{ $p->rop?->full_name }}</span> ({{ t('roles.ROP') }}) ·
            <span class="text-foreground">{{ $p->manager?->full_name }}</span> ({{ t('roles.MANAGER') }})</p>
        @if ($canAssign)
            <button type="button" class="btn btn-primary btn-sm" @click="$dispatch('open-modal', 'member-add')"><x-icon name="plus" /> {{ t('projects.team.add') }}</button>
        @endif
    </div>
    @if ($p->team->isEmpty())
        <x-empty icon="users" :title="t('projects.team.empty')">@if ($canAssign){{ t('projects.team.emptyText') }}@endif</x-empty>
    @else
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr>
                    <th>{{ t('projects.team.user') }}</th><th>{{ t('projects.team.role') }}</th><th>{{ t('projects.team.assignedAt') }}</th>
                    <th>{{ t('projects.team.workload') }}</th><th>{{ t('projects.team.tasks') }}</th><th>{{ t('projects.team.deadline') }}</th><th>{{ t('projects.fields.status') }}</th>
                    @if ($canAssign)<th></th>@endif
                </tr></thead>
                <tbody>
                @foreach ($p->team as $m)
                    <tr>
                        <td class="font-medium">{{ $m->user?->full_name }}</td>
                        <td>
                            @forelse ($m->positions() as $pos)
                                <x-badge :color="$loop->first ? 'blue' : 'gray'" class="mb-1">{{ t('specialties.'.$pos) }}</x-badge>
                            @empty
                                —
                            @endforelse
                        </td>
                        <td class="whitespace-nowrap">{{ d($m->assigned_at) }}</td>
                        <td>{{ $m->workload_pct === null ? '—' : $m->workload_pct.'%' }}</td>
                        <td>@include('projects.partials.progress', ['c' => $m->task_counts])</td>
                        <td class="whitespace-nowrap">{{ d($m->deadline) }}</td>
                        <td><x-badge :color="['ACTIVE' => 'blue', 'DONE' => 'green', 'REMOVED' => 'gray'][$m->status]">{{ t('projects.team.status.'.$m->status) }}</x-badge></td>
                        @if ($canAssign)
                            <td class="text-right">
                                @if ($m->status === 'ACTIVE')
                                    <div class="flex justify-end gap-1">
                                        <button type="button" class="btn btn-ghost btn-sm" @click="$dispatch('open-modal', 'member-edit-{{ $m->id }}')">{{ t('common.edit') }}</button>
                                        <form method="POST" action="/projects/{{ $p->id }}/members/{{ $m->id }}" data-confirm="{{ t('projects.team.removeConfirm', ['name' => $m->user?->full_name]) }}">
                                            @csrf @method('PATCH')
                                            <input type="hidden" name="status" value="REMOVED">
                                            <button class="btn btn-ghost btn-sm">{{ t('projects.team.remove') }}</button>
                                        </form>
                                    </div>
                                @elseif ($m->status === 'REMOVED')
                                    <form method="POST" action="/projects/{{ $p->id }}/members">
                                        @csrf
                                        <input type="hidden" name="user_id" value="{{ $m->user_id }}">
                                        <input type="hidden" name="roles[]" value="">
                                        @foreach ($m->positions() as $pos)<input type="hidden" name="roles[]" value="{{ $pos }}">@endforeach
                                        <button class="btn btn-ghost btn-sm">{{ t('projects.team.return') }}</button>
                                    </form>
                                @endif
                            </td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@if ($canAssign)
    <x-modal name="member-add" :title="t('projects.team.add')" :open="$errors->hasAny(['user_id', 'workload_pct', 'roles', 'roles.*']) && ! old('_member')">
        <form method="POST" action="/projects/{{ $p->id }}/members" class="space-y-4"
              x-data="{ roles: @js(array_values(array_filter((array) old('roles', [])))), spec: @js($candidates->mapWithKeys(fn ($c) => [$c->id => $c->employee?->specialtyList() ?? []])->all()) }">
            @csrf
            <div>
                <label class="label" for="m-user">{{ t('projects.team.user') }}<span class="text-danger"> *</span></label>
                <select id="m-user" name="user_id" class="input" required @change="roles = [...(spec[$event.target.value] || [])]">
                    <option value="">—</option>
                    @foreach ($candidates as $c)
                        @php($cs = $c->employee?->specialtyList() ?? [])
                        <option value="{{ $c->id }}" @selected(old('user_id') === $c->id)>{{ $c->full_name }} · {{ $cs ? collect($cs)->map(fn ($s) => t('specialties.'.$s))->implode(', ') : t('roles.'.$c->role->code) }}</option>
                    @endforeach
                </select>
                @error('user_id')<p class="error">{{ $message }}</p>@enderror
            </div>
            <x-specialty-picker name="roles" model="roles" id="m-roles" />
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="workload_pct" type="number" min="0" max="100" :label="t('projects.team.workload')" />
                <x-field name="deadline" type="date" :label="t('projects.team.deadline')" />
            </div>
            <div class="flex justify-end gap-2">
                <button type="button" class="btn btn-outline" @click="$dispatch('close-modal', 'member-add')">{{ t('common.cancel') }}</button>
                <button class="btn btn-primary">{{ t('projects.team.add') }}</button>
            </div>
        </form>
    </x-modal>
    @foreach ($p->team->where('status', 'ACTIVE') as $m)
        <x-modal :name="'member-edit-'.$m->id" :title="t('projects.team.edit', ['name' => $m->user?->full_name])" :open="old('_member') === $m->id && $errors->any()">
            <form method="POST" action="/projects/{{ $p->id }}/members/{{ $m->id }}" class="space-y-4">
                @csrf @method('PATCH')
                <input type="hidden" name="_member" value="{{ $m->id }}">
                <x-specialty-picker name="roles" :id="'me-'.$m->id" :selected="old('_member') === $m->id ? array_values(array_filter((array) old('roles', []))) : $m->positions()" />
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field name="workload_pct" type="number" min="0" max="100" :id="'me-'.$m->id.'-load'" :label="t('projects.team.workload')" :value="$m->workload_pct" />
                    <x-field name="deadline" type="date" :id="'me-'.$m->id.'-deadline'" :label="t('projects.team.deadline')" :value="$m->deadline?->format('Y-m-d')" />
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" class="btn btn-outline" @click="$dispatch('close-modal', 'member-edit-{{ $m->id }}')">{{ t('common.cancel') }}</button>
                    <button class="btn btn-primary">{{ t('common.save') }}</button>
                </div>
            </form>
        </x-modal>
    @endforeach
@endif
