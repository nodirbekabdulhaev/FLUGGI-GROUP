{{-- Команда проекта (ТЗ §21): роль, дата назначения, загрузка, задачи, дедлайн, статус --}}
@php($canAssign = $p->can['assign'] && $open)
@php($specialties = collect(\App\Services\Projects\ProjectAccess::SPECIALTIES)->mapWithKeys(fn ($s) => [$s => t('specialties.'.$s)])->all())
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
                        <td>{{ $m->role ? t('specialties.'.$m->role) : '—' }}</td>
                        <td class="whitespace-nowrap">{{ d($m->assigned_at) }}</td>
                        <td>{{ $m->workload_pct === null ? '—' : $m->workload_pct.'%' }}</td>
                        <td>@include('projects.partials.progress', ['c' => $m->task_counts])</td>
                        <td class="whitespace-nowrap">{{ d($m->deadline) }}</td>
                        <td><x-badge :color="['ACTIVE' => 'blue', 'DONE' => 'green', 'REMOVED' => 'gray'][$m->status]">{{ t('projects.team.status.'.$m->status) }}</x-badge></td>
                        @if ($canAssign)
                            <td class="text-right">
                                @if ($m->status === 'ACTIVE')
                                    <form method="POST" action="/projects/{{ $p->id }}/members/{{ $m->id }}" data-confirm="{{ t('projects.team.removeConfirm', ['name' => $m->user?->full_name]) }}">
                                        @csrf @method('PATCH')
                                        <input type="hidden" name="status" value="REMOVED">
                                        <button class="btn btn-ghost btn-sm">{{ t('projects.team.remove') }}</button>
                                    </form>
                                @elseif ($m->status === 'REMOVED')
                                    <form method="POST" action="/projects/{{ $p->id }}/members">
                                        @csrf
                                        <input type="hidden" name="user_id" value="{{ $m->user_id }}">
                                        <input type="hidden" name="role" value="{{ $m->role }}">
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
    <x-modal name="member-add" :title="t('projects.team.add')" :open="$errors->hasAny(['user_id', 'workload_pct'])">
        <form method="POST" action="/projects/{{ $p->id }}/members" class="space-y-4"
              x-data="{ role: '', spec: @js($candidates->mapWithKeys(fn ($c) => [$c->id => $c->employee?->specialty])->all()) }">
            @csrf
            <div>
                <label class="label" for="m-user">{{ t('projects.team.user') }}<span class="text-danger"> *</span></label>
                <select id="m-user" name="user_id" class="input" required @change="role = spec[$event.target.value] || role">
                    <option value="">—</option>
                    @foreach ($candidates as $c)
                        <option value="{{ $c->id }}">{{ $c->full_name }} · {{ $c->employee?->specialty ? t('specialties.'.$c->employee->specialty) : t('roles.'.$c->role->code) }}</option>
                    @endforeach
                </select>
                @error('user_id')<p class="error">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="label" for="m-role">{{ t('projects.team.role') }}</label>
                <select id="m-role" name="role" class="input" x-model="role">
                    <option value="">{{ t('projects.team.noRole') }}</option>
                    @foreach ($specialties as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
                </select>
            </div>
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
@endif
