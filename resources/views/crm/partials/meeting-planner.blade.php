{{--
  Участники, ответственный и слоты встречи (внутри формы). $pid — префикс id полей; $planner — начальные
  значения (date, time, duration, selected, responsible, exclude, extra); $options — сотрудники «Участвует во встречах»;
  $listen — событие окна, которым форма перенастраивается (окно изменения встречи); $init — доп. x-init в области планировщика.
--}}
@php
    $listen ??= null;
    $init ??= null;
    $config = $planner + [
        'url' => route('crm.meetings.slots'),
        'options' => $options,
        'me' => access()->id(),
        'labels' => [
            'busyBy' => t('meetings.busyBy', ['names' => '__N__']),
            'conflict' => t('meetings.conflict', ['names' => '__N__']),
            'free' => t('meetings.free'),
            'past' => t('meetings.past'),
        ],
    ];
@endphp
<div class="grid gap-4" x-data="meetingPlanner(@js($config))" @if ($listen) x-on:{{ $listen }}.window="reset($event.detail)" @endif @if ($init) x-init="{{ $init }}" @endif>
    <input type="hidden" name="starts_at" :value="startsAt">
    <input type="hidden" name="duration_min" :value="duration">

    <fieldset class="grid gap-2">
        <legend class="label">{{ t('meetings.participants') }}</legend>
        <div class="flex flex-wrap gap-2" x-show="people.length">
            <template x-for="p in people" :key="p.id">
                <label class="flex cursor-pointer items-center gap-1.5 rounded-md border px-2 py-1 text-sm" :class="selected.includes(p.id) && 'border-accent bg-accent-soft'">
                    <input type="checkbox" name="participant_ids[]" :value="p.id" x-model="selected"> <span x-text="p.name"></span>
                </label>
            </template>
        </div>
        <p class="hint" x-show="people.length">{{ t('meetings.participantsHint') }}</p>
        <p class="hint" x-show="!people.length">{{ t('meetings.noParticipants') }}</p>
        @error('participant_ids')<p class="error">{{ $message }}</p>@enderror
    </fieldset>

    <div class="grid gap-4 sm:grid-cols-2">
        <div x-show="selected.length">
            <label class="label" for="{{ $pid }}-resp">{{ t('meetings.responsible') }}</label>
            <select id="{{ $pid }}-resp" name="manager_id" class="input" x-model="responsible" :disabled="!selected.length">
                <template x-for="p in chosen" :key="p.id"><option :value="p.id" x-text="p.name" :selected="p.id === responsible"></option></template>
            </select>
            <p class="hint">{{ t('meetings.responsibleHint') }}</p>
            @error('manager_id')<p class="error">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="label" for="{{ $pid }}-date">{{ t('meetings.date') }} <span class="text-danger">*</span></label>
            <input id="{{ $pid }}-date" type="date" class="input" x-model="date" required>
        </div>
    </div>

    <div class="grid gap-2">
        <p class="label">{{ t('meetings.slots') }}</p>
        <p class="hint" x-show="!selected.length || !date">{{ t('meetings.slotsPick') }}</p>
        <p class="text-sm text-muted-foreground" x-show="loading" x-cloak>{{ t('meetings.loading') }}</p>
        <p class="error" x-show="error" x-text="error" x-cloak></p>
        <template x-for="row in rows" :key="row.id">
            <div class="rounded-md border p-2">
                <p class="mb-2 flex flex-wrap items-baseline gap-x-2 text-sm">
                    <span class="font-medium" x-text="row.name"></span>
                    <span class="text-xs text-muted-foreground" x-text="row.dayOff ? @js(t('meetings.dayOff')) : `${row.settings.from}–${row.settings.to}`"></span>
                </p>
                <div class="grid grid-cols-2 gap-1.5 min-[420px]:grid-cols-3 sm:grid-cols-4" x-show="row.slots.length">
                    <template x-for="slot in row.slots" :key="slot.value">
                        <button type="button" class="min-w-0 rounded-md border px-2 py-1.5 text-left text-xs" :disabled="!selectable(slot)" :title="slotTitle(slot)" x-on:click="pick(slot)"
                                :class="{
                                    'border-accent bg-accent text-white': isPicked(slot),
                                    'border-success/40 bg-success-soft text-success hover:border-success': selectable(slot) && !isPicked(slot),
                                    'cursor-not-allowed border-danger/30 bg-danger-soft text-danger': slot.state === 'busy',
                                    'cursor-not-allowed border-warning/30 bg-warning-soft text-warning': slot.state === 'free' && slot.conflicts.length,
                                    'cursor-not-allowed bg-muted text-muted-foreground': slot.state === 'past',
                                }">
                            <span class="block font-medium tabular-nums" x-text="`${slot.time}–${slot.end}`"></span>
                            <span class="block truncate" x-show="slot.state === 'busy'" x-text="busyText(slot)"></span>
                            <template x-for="m in slot.meetings" :key="m.id"><span class="block truncate opacity-80" x-show="m.subject" x-text="`${m.time} · ${m.subject}`"></span></template>
                            <span class="block truncate" x-show="slot.state === 'free' && slot.conflicts.length" x-text="label('conflict', slot.conflicts.join(', '))"></span>
                            <span class="block" x-show="selectable(slot)">{{ t('meetings.free') }}</span>
                            <span class="block" x-show="slot.state === 'past' && !slot.meetings.length">{{ t('meetings.past') }}</span>
                        </button>
                    </template>
                </div>
            </div>
        </template>
    </div>

    <fieldset class="grid gap-2">
        <legend class="label">{{ t('meetings.manualTime') }}</legend>
        <div class="grid grid-cols-2 gap-4">
            <div><label class="label" for="{{ $pid }}-time">{{ t('crm.fields.startsAt') }} <span class="text-danger">*</span></label><input id="{{ $pid }}-time" type="time" class="input" x-model="time" required></div>
            <div><label class="label" for="{{ $pid }}-dur">{{ t('crm.fields.durationMin') }}</label><input id="{{ $pid }}-dur" type="number" min="5" max="600" step="5" class="input" x-model="duration"></div>
        </div>
        @error('starts_at')<p class="error">{{ $message }}</p>@enderror
    </fieldset>
</div>
