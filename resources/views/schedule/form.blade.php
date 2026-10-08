@extends('layouts.app')
@section('title', $schedule ? __('Изменить расписание') : __('Новое расписание'))
@section('content')
@php
  $groups = \App\Models\Group::whereIn('status', ['enrolling', 'active'])->get(['id', 'name', 'teacher_id', 'room_id']);
  $v = fn($k, $d = null) => old($k, $values[$k] ?? $d);
@endphp
<form method="POST" action="{{ $schedule ? route('schedule.update', $schedule) : route('schedule.store') }}" class="card card-body max-w-3xl"
      x-data="scheduleForm({{ $schedule ? 'true' : 'false' }}, @js($v('weekdays', $schedule ? [$values['weekday']] : [])), {{ $schedule?->id ?? 'null' }}, @js($groups))">
    @csrf @if($schedule) @method('PUT') @endif
    <div class="grid gap-4 sm:grid-cols-2">
        <div class="sm:col-span-2"><label class="label">{{ __('Группа') }}</label>
            <select name="group_id" x-model="f.group_id" @change="pick()" class="input" required {{ $schedule ? 'disabled' : '' }}>
                <option value="">—</option>@foreach($groups as $g)<option value="{{ $g->id }}">{{ $g->name }}</option>@endforeach</select>
            @if($schedule)<input type="hidden" name="group_id" value="{{ $schedule->group_id }}">@endif</div>
        <div><label class="label">{{ __('Преподаватель') }}</label>
            <select name="teacher_id" x-model="f.teacher_id" @change="check()" class="input"><option value="">—</option>@foreach(\App\Support\Lookup::teachers() as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></div>
        <div><label class="label">{{ __('Аудитория') }}</label>
            <select name="room_id" x-model="f.room_id" @change="check()" class="input"><option value="">—</option>@foreach(\App\Support\Lookup::rooms() as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></div>
        <div class="sm:col-span-2"><label class="label">{{ __('Дни недели') }}</label>
            <div class="flex flex-wrap gap-2">
            @if($schedule)
                <input type="hidden" name="weekday" value="{{ $schedule->weekday }}"><x-badge color="indigo">{{ __(\App\Models\Schedule::WEEKDAYS[$schedule->weekday]) }}</x-badge>
            @else
                @foreach(\App\Models\Schedule::WEEKDAYS as $d => $name)
                <label class="cursor-pointer"><input type="checkbox" name="weekdays[]" value="{{ $d }}" x-model="f.weekdays" @change="check()" class="peer sr-only"><span class="inline-block rounded-lg border px-3 py-1.5 text-sm peer-checked:border-brand-600 peer-checked:bg-brand-600 peer-checked:text-white dark:border-slate-700">{{ mb_substr(__($name), 0, 3) }}</span></label>
                @endforeach
            @endif</div></div>
        <div><label class="label">{{ __('Начало') }}</label><input type="time" name="start_time" x-model="f.start_time" @change="check()" class="input" required></div>
        <div><label class="label">{{ __('Конец') }}</label><input type="time" name="end_time" x-model="f.end_time" @change="check()" class="input" required></div>
        <div><label class="label">{{ __('Дата начала') }}</label><input type="date" name="start_date" x-model="f.start_date" @change="check()" class="input" required></div>
        <div><label class="label">{{ __('Дата окончания') }}</label><input type="date" name="end_date" x-model="f.end_date" @change="check()" class="input"></div>
        @if($schedule)<label class="inline-flex items-center gap-2 text-sm"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($v('is_active', true)) class="rounded border-slate-300 text-brand-600"> {{ __('Активно') }}</label>@endif
    </div>
    <div x-cloak x-show="conflicts.length" class="mt-4 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
        <div class="font-semibold">⚠ {{ __('Конфликт расписания') }}</div><ul class="list-inside list-disc"><template x-for="c in conflicts"><li x-text="c"></li></template></ul>
    </div>
    <p class="mt-3 text-xs text-slate-500">{{ __('Занятия генерируются автоматически на 90 дней вперёд, дальше — ежедневно по cron.') }}</p>
    <div class="mt-4 flex gap-2"><button class="btn-primary" :disabled="conflicts.length > 0">{{ __('Сохранить') }}</button>
        <a href="{{ route('schedule.index') }}" class="btn-secondary">{{ __('Отмена') }}</a></div>
</form>
@if($schedule)
<form method="POST" action="{{ route('schedule.destroy', $schedule) }}" class="mt-3 max-w-3xl" onsubmit="return confirm('{{ __('Удалить расписание и будущие занятия без отметок?') }}')">@csrf @method('DELETE')<button class="btn-ghost text-rose-600">{{ __('Удалить расписание') }}</button></form>
@endif
@endsection
@push('scripts')
<script>
function scheduleForm(editing, weekdays, except, groups) {
    return {
        conflicts: [], timer: null,
        f: { group_id: @js((string) $v('group_id')), teacher_id: @js((string) $v('teacher_id')), room_id: @js((string) $v('room_id')),
             weekdays: weekdays.map(String), start_time: @js($v('start_time')), end_time: @js($v('end_time')), start_date: @js($v('start_date')), end_date: @js($v('end_date')) },
        init() { if (this.f.group_id && !editing) this.pick(true); else this.check(); },
        pick(keep) {
            const g = groups.find(x => String(x.id) === String(this.f.group_id));
            if (g && !editing) { this.f.teacher_id = g.teacher_id ? String(g.teacher_id) : this.f.teacher_id; this.f.room_id = g.room_id ? String(g.room_id) : this.f.room_id; }
            this.check();
        },
        check() {
            clearTimeout(this.timer);
            this.timer = setTimeout(async () => {
                if (!this.f.weekdays.length || !this.f.start_time || !this.f.end_time || !this.f.start_date || this.f.end_time <= this.f.start_time) { this.conflicts = []; return; }
                const r = await fetch('{{ route('schedule.check') }}', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                    body: JSON.stringify({ ...this.f, group_id: this.f.group_id || null, teacher_id: this.f.teacher_id || null, room_id: this.f.room_id || null, end_date: this.f.end_date || null, except }) });
                if (r.ok) this.conflicts = (await r.json()).conflicts;
            }, 250);
        },
    };
}
</script>
@endpush
