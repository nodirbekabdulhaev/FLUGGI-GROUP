{{-- Форма сотрудника (создание/правка) --}}
@php
    $u = $user;
    $mid = $u ? 'emp-edit' : 'emp-new';
    $roles = collect(\App\Services\People\UserService::ROLES)->filter(fn ($r) => $r !== 'CEO' || access()->roleCode === 'CEO')->mapWithKeys(fn ($r) => [$r => t('roles.'.$r)])->all();
    $dirIds = $u ? $u->directions->pluck('direction_id')->all() : [];
    $ms = \App\Services\Crm\MeetingSlots::settings($u?->meeting_slots ? json_decode($u->meeting_slots, true) : null);
    $msDays = array_map('intval', (array) old('meeting_days', $ms['days']));
@endphp
<form method="POST" action="{{ $u ? '/team/employees/'.$u->id : '/team/employees' }}" class="space-y-4" x-data="{ role: @js(old('role_code', $u?->role?->code ?? $fixedRole ?? '')) }">
    @csrf
    @if ($u) @method('PATCH') @endif
    <input type="hidden" name="return_to" value="/team/{{ $slug }}">
    <x-field name="full_name" :id="$mid.'-name'" :label="t('employees.fullName')" :value="$u?->full_name" required />
    <div class="grid gap-4 sm:grid-cols-2">
        <x-field name="email" type="email" :id="$mid.'-email'" :label="t('employees.email')" :value="$u?->email" required />
        <x-field name="phone" type="tel" :id="$mid.'-phone'" :label="t('employees.phone')" :value="$u?->phone" placeholder="+998" />
    </div>
    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <label class="label" for="{{ $mid }}-role">{{ t('employees.role') }}<span class="text-danger"> *</span></label>
            <select id="{{ $mid }}-role" name="role_code" class="input" x-model="role" required>
                <option value="">{{ t('employees.selectRole') }}</option>
                @foreach ($roles as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach
            </select>
            @error('role_code')<p class="error">{{ $message }}</p>@enderror
        </div>
        <div x-show="role === 'ROP' || role === 'MANAGER'">
            <x-select name="team_id" :id="$mid.'-team'" :label="t('employees.team')" :value="$u?->team_id" :placeholder="t('employees.noTeam')" :options="$teams" />
        </div>
    </div>
    <div x-show="role === 'EXECUTOR'">
        <x-specialty-picker name="specialties" :id="$mid.'-spec'" :label="t('employees.specialties')" :hint="t('employees.specialtiesHint')"
            :selected="old('full_name') !== null ? array_values(array_filter((array) old('specialties', []))) : ($u?->employee?->specialtyList() ?? [])" />
    </div>
    <fieldset class="grid gap-2 rounded-md border p-3" x-show="role === 'PROJECT_MANAGER'">
        <legend class="px-1 text-sm font-medium">{{ t('employees.directions') }}</legend>
        <p class="text-xs text-muted-foreground">{{ t('employees.directionsHint') }}</p>
        @foreach ($directions as $dir)
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="direction_ids[]" value="{{ $dir->id }}" @checked(in_array($dir->id, old('direction_ids', $dirIds), true))> {{ $dir->name }}</label>
        @endforeach
    </fieldset>
    <x-field name="position" :id="$mid.'-pos'" :label="t('employees.position')" :value="$u?->employee?->position" />
    <div class="grid gap-3 rounded-md border p-3" x-data="{ meet: @js((bool) old('meeting_participant', $u?->meeting_participant ?? false)) }">
        <input type="hidden" name="meeting_participant" value="0">
        <label class="flex items-start gap-2 text-sm">
            <input type="checkbox" name="meeting_participant" value="1" class="mt-0.5" x-model="meet">
            <span><span class="font-medium">{{ t('employees.meetingParticipant') }}</span><span class="block text-xs text-muted-foreground">{{ t('employees.meetingParticipantHint') }}</span></span>
        </label>
        <div class="grid gap-3" x-show="meet" x-cloak>
            <fieldset>
                <legend class="label">{{ t('employees.meetingDays') }}</legend>
                <div class="flex flex-wrap gap-2">
                    @foreach (range(1, 7) as $wd)
                        <label class="flex items-center gap-1.5 rounded-md border px-2 py-1 text-sm"><input type="checkbox" name="meeting_days[]" value="{{ $wd }}" @checked(in_array($wd, $msDays, true))> {{ t('schedules.weekdays.'.$wd) }}</label>
                    @endforeach
                </div>
                @error('meeting_days')<p class="error">{{ $message }}</p>@enderror
            </fieldset>
            <div class="grid grid-cols-3 gap-3">
                <div><label class="label" for="{{ $mid }}-mfrom">{{ t('employees.meetingFrom') }}</label><input id="{{ $mid }}-mfrom" type="time" step="900" name="meeting_from" class="input" value="{{ old('meeting_from', $ms['from']) }}"></div>
                <div><label class="label" for="{{ $mid }}-mto">{{ t('employees.meetingTo') }}</label><input id="{{ $mid }}-mto" type="time" step="900" name="meeting_to" class="input" value="{{ old('meeting_to', $ms['to']) }}"></div>
                <div><label class="label" for="{{ $mid }}-mslot">{{ t('employees.meetingSlot') }}</label>
                    <select id="{{ $mid }}-mslot" name="meeting_slot" class="input">
                        @foreach (\App\Services\Crm\MeetingSlots::LENGTHS as $len)<option value="{{ $len }}" @selected((int) old('meeting_slot', $ms['slot']) === $len)>{{ t('crm.common.minutes', ['n' => $len]) }}</option>@endforeach
                    </select></div>
            </div>
            @error('meeting_to')<p class="error">{{ $message }}</p>@enderror
            <p class="hint">{{ t('employees.meetingTimeHint') }}</p>
        </div>
    </div>
    <div class="flex justify-end gap-2">
        <a href="/team/{{ $slug }}" class="btn btn-outline" @click.prevent="$dispatch('close-modal', '{{ $mid }}')">{{ t('common.cancel') }}</a>
        <button class="btn btn-primary">{{ $u ? t('common.save') : t('common.create') }}</button>
    </div>
</form>
