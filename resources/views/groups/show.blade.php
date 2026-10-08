@extends('layouts.app')
@section('title', $group->name)
@section('actions')
    @can('schedule.manage')<a href="{{ route('schedule.create', ['group_id' => $group->id]) }}" class="btn-secondary"><x-icon name="calendar" class="h-4 w-4" /> {{ __('Расписание') }}</a>@endcan
    @can('groups.manage')<a href="{{ route('groups.edit', $group) }}" class="btn-secondary"><x-icon name="edit" class="h-4 w-4" /> {{ __('Изменить') }}</a>@endcan
    @can('groups.delete')<form method="POST" action="{{ route('groups.destroy', $group) }}" onsubmit="return confirm('{{ __('Удалить группу?') }}')">@csrf @method('DELETE')<button class="btn-ghost text-rose-600"><x-icon name="trash" class="h-4 w-4" /></button></form>@endcan
@endsection
@section('content')
<div class="grid grid-cols-2 gap-3 md:grid-cols-4">
    <x-stat :label="__('Курс')" :value="$group->course?->name ?? '—'" :hint="$group->branch?->name" />
    <x-stat :label="__('Преподаватель')" :value="$group->teacher?->full_name ?? '—'" :hint="$group->room?->name" />
    <x-stat :label="__('Места')" :value="($group->max_students - $free).' / '.$group->max_students" :hint="$free.' '.__('свободно')" />
    <x-stat :label="__('Посещаемость')" :value="$attPercent === null ? '—' : $attPercent.'%'" :hint="__('Проведено').': '.($lessonStats['held'] ?? 0).' · '.__('Отменено').': '.($lessonStats['cancelled'] ?? 0)" />
</div>
<div class="mt-4 grid gap-4 xl:grid-cols-3">
  <div class="card xl:col-span-2">
    <div class="flex items-center justify-between border-b p-4 dark:border-slate-800"><h2 class="text-sm font-semibold">{{ __('Ученики') }}</h2>
        <a class="link text-xs" href="{{ request()->fullUrlWithQuery(['history' => $showAll ? null : 1]) }}">{{ $showAll ? __('Только текущие') : __('Показать историю') }}</a></div>
    <div class="overflow-x-auto"><table class="min-w-full text-sm"><tbody class="divide-y divide-slate-100 dark:divide-slate-800">
    @forelse($enrollments as $e)
        <tr><td class="td"><a class="link font-medium" href="{{ route('students.show', $e->student_id) }}">{{ $e->student?->full_name }}</a><div class="text-xs text-slate-500">{{ $e->student?->phone }}</div></td>
            <td class="td whitespace-nowrap text-slate-500">{{ fdate($e->joined_at) }}@if($e->left_at) — {{ fdate($e->left_at) }}@endif</td>
            @if($canFinance)<td class="td text-right {{ ($e->debt?->balance ?? 0) > 0 ? 'font-medium text-rose-600' : 'text-slate-400' }}">{{ ($e->debt?->balance ?? 0) > 0 ? money($e->debt->balance) : '—' }}</td>@endif
            <td class="td"><x-badge :color="['active'=>'emerald','frozen'=>'blue','completed'=>'indigo','left'=>'rose','transferred'=>'slate'][$e->status] ?? 'slate'">{{ __(\App\Models\GroupStudent::STATUSES[$e->status]) }}</x-badge></td></tr>
    @empty<tr><td class="px-3 py-8 text-center text-slate-400">{{ __('В группе пока нет учеников') }}</td></tr>@endforelse
    </tbody></table></div>
    @if($canManage && in_array($group->status, ['enrolling', 'active']))
    <form method="POST" action="{{ route('enrollments.store') }}" class="grid gap-2 border-t p-4 dark:border-slate-800 sm:grid-cols-5">@csrf
        <input type="hidden" name="group_id" value="{{ $group->id }}">
        <select name="student_id" class="input sm:col-span-2" required><option value="">{{ __('Добавить ученика…') }}</option>@foreach($candidates as $s)<option value="{{ $s->id }}">{{ $s->full_name }} {{ $s->phone }}</option>@endforeach</select>
        <input type="number" min="0" name="price" class="input" placeholder="{{ __('Цена') }}: {{ money($group->effectivePrice(), false) }}"><input type="number" min="0" name="discount" class="input" placeholder="{{ __('Скидка') }}">
        <button class="btn-primary">{{ __('Добавить') }}</button>
    </form>
    <div class="px-4 pb-4 text-xs text-slate-500">{{ __('Нет в списке?') }} <a class="link" href="{{ route('students.create', ['group_id' => $group->id]) }}">{{ __('Создать нового ученика') }}</a></div>
    @endif
  </div>

  <div class="space-y-4">
    <div class="card card-body"><h2 class="mb-2 text-sm font-semibold">{{ __('Расписание') }}</h2>
        <ul class="space-y-1 text-sm">@forelse($schedules as $s)
            <li class="flex items-center justify-between"><span><b>{{ __(\App\Models\Schedule::WEEKDAYS[$s->weekday]) }}</b> {{ ftime($s->start_time) }}–{{ ftime($s->end_time) }}</span>
                @can('schedule.manage')<a href="{{ route('schedule.edit', $s) }}" class="link text-xs">{{ __('изм.') }}</a>@endcan</li>
        @empty<li class="text-slate-400">—</li>@endforelse</ul>
        <div class="mt-2 text-xs text-slate-500">{{ $group->start_date ? fdate($group->start_date) : '…' }} — {{ $group->end_date ? fdate($group->end_date) : '…' }} · <x-badge :color="['enrolling'=>'amber','active'=>'emerald','completed'=>'indigo','archived'=>'slate'][$group->status]">{{ __(\App\Models\Group::STATUSES[$group->status]) }}</x-badge></div></div>
    <div class="card card-body"><h2 class="mb-2 text-sm font-semibold">{{ __('Ближайшие занятия') }}</h2>
        <ul class="space-y-1 text-sm">@forelse($upcoming as $l)<li><a class="link" href="{{ route('lessons.show', $l) }}">{{ fdate($l->lesson_date, 'd.m (D)') }} {{ ftime($l->start_time) }}</a> @if($l->status !== 'planned')<x-badge color="rose">{{ __(\App\Models\Lesson::STATUSES[$l->status]) }}</x-badge>@endif</li>@empty<li class="text-slate-400">—</li>@endforelse</ul></div>
    <div class="card card-body"><h2 class="mb-2 text-sm font-semibold">{{ __('Прошедшие') }}</h2>
        <ul class="space-y-1 text-sm">@forelse($past as $l)<li class="flex justify-between"><a class="link" href="{{ route('lessons.show', $l) }}">{{ fdate($l->lesson_date, 'd.m') }} {{ ftime($l->start_time) }}</a><x-badge :color="['held'=>'emerald','cancelled'=>'rose','planned'=>'amber','rescheduled'=>'slate'][$l->status]">{{ __(\App\Models\Lesson::STATUSES[$l->status]) }}</x-badge></li>@empty<li class="text-slate-400">—</li>@endforelse</ul>
        <a class="link mt-2 inline-block text-xs" href="{{ route('attendance.index', ['group_id' => $group->id, 'period' => 'month']) }}">{{ __('Журнал посещаемости') }} →</a></div>
  </div>
</div>
@endsection
