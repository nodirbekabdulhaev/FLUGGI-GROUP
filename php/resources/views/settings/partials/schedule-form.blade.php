@php($sid = $schedule?->id ?? 'new')
@php($chosen = $schedule ? $schedule->employees->pluck('user_id')->all() : [])
<form method="POST" action="{{ $schedule ? '/settings/schedules/'.$schedule->id : '/settings/schedules' }}" class="space-y-4">
    @csrf
    @if ($schedule) @method('PUT') @endif
    <div class="grid gap-4 sm:grid-cols-2">
        <x-field name="name" :id="'ws-name-'.$sid" :label="t('schedules.name')" :value="$schedule?->name" required />
        <x-select name="role_code" :id="'ws-role-'.$sid" :label="t('schedules.role')" :value="$schedule?->role_code" :placeholder="t('schedules.noRole')" :options="collect(\App\Services\People\UserService::ROLES)->mapWithKeys(fn ($r) => [$r => t('roles.'.$r)])->all()" />
        <x-field name="start_time" type="time" :id="'ws-start-'.$sid" :label="t('schedules.start')" :value="$schedule?->start_time ?? '09:00'" required />
        <x-field name="end_time" type="time" :id="'ws-end-'.$sid" :label="t('schedules.end')" :value="$schedule?->end_time ?? '18:00'" required />
        <x-field name="grace_minutes" type="number" min="0" max="180" :id="'ws-grace-'.$sid" :label="t('schedules.grace')" :value="$schedule?->grace_minutes ?? 10" />
        <label class="flex h-9 items-center gap-2 self-end text-sm"><input type="checkbox" name="is_active" value="1" @checked($schedule?->is_active ?? true)> {{ t('schedules.active') }}</label>
    </div>
    <fieldset class="grid gap-2">
        <legend class="mb-1 text-sm font-medium">{{ t('schedules.days') }}</legend>
        <div class="flex flex-wrap gap-2">
            @foreach (range(1, 7) as $dnum)
                <label class="flex items-center gap-1.5 rounded-md border px-2.5 py-1.5 text-sm">
                    <input type="checkbox" name="work_days[]" value="{{ $dnum }}" @checked(in_array($dnum, $schedule?->work_days ?? [1, 2, 3, 4, 5], true))> {{ t('schedules.weekdays.'.$dnum) }}
                </label>
            @endforeach
        </div>
        @error('work_days')<p class="error">{{ $message }}</p>@enderror
    </fieldset>
    <fieldset class="grid gap-2">
        <legend class="mb-1 text-sm font-medium">{{ t('schedules.users') }}</legend>
        <p class="text-xs text-muted-foreground">{{ t('schedules.usersHint') }}</p>
        <div class="grid max-h-48 gap-1 overflow-y-auto rounded-md border p-2 sm:grid-cols-2">
            @foreach ($users as $uid => $uname)
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="user_ids[]" value="{{ $uid }}" @checked(in_array($uid, $chosen, true))> {{ $uname }}</label>
            @endforeach
        </div>
    </fieldset>
    <div class="flex justify-end gap-2">
        <button type="button" class="btn btn-outline" @click="$dispatch('close-modal', 'ws-{{ $sid }}')">{{ t('common.cancel') }}</button>
        <button class="btn btn-primary">{{ t('common.save') }}</button>
    </div>
</form>
