@extends('layouts.app')
@section('title', $student->full_name)
@section('actions')
    @can('payments.create')<a href="{{ route('payments.create', ['student_id' => $student->id]) }}" class="btn-primary"><x-icon name="cash" class="h-4 w-4" /> {{ __('Принять оплату') }}</a>@endcan
    @can('students.manage')<a href="{{ route('students.edit', $student) }}" class="btn-secondary"><x-icon name="edit" class="h-4 w-4" /> {{ __('Изменить') }}</a>@endcan
    @can('students.delete')
        <form method="POST" action="{{ route('students.destroy', $student) }}" onsubmit="return confirm('{{ __('Удалить ученика? История сохранится.') }}')">@csrf @method('DELETE')<button class="btn-ghost text-rose-600"><x-icon name="trash" class="h-4 w-4" /></button></form>
    @endcan
@endsection
@section('content')
@php
  $colors = ['active'=>'emerald','frozen'=>'blue','completed'=>'indigo','expelled'=>'rose','archived'=>'slate'];
  $canManage = Gate::allows('students.manage');
@endphp
<div class="grid gap-4 xl:grid-cols-3">
  <div class="space-y-4">
    {{-- Basic info --}}
    <div class="card card-body">
        <div class="flex items-start gap-3">
            @if($student->photo_path)<img src="{{ route('files.show', ['student-photo', $student->id]) }}" class="h-16 w-16 rounded-xl object-cover" alt="">@else<div class="flex h-16 w-16 items-center justify-center rounded-xl bg-brand-100 text-2xl font-semibold text-brand-700">{{ mb_strtoupper(mb_substr($student->first_name, 0, 1)) }}</div>@endif
            <div class="min-w-0 flex-1"><div class="text-lg font-semibold">{{ $student->full_name }}</div><div class="text-xs text-slate-500">ID #{{ $student->id }}</div>
                <x-badge :color="$colors[$student->status] ?? 'slate'" class="mt-1">{{ __(\App\Models\Student::STATUSES[$student->status] ?? $student->status) }}</x-badge>
                @if($student->status === 'expelled' && $student->expulsionReason)<div class="mt-1 text-xs text-rose-600">{{ $student->expulsionReason->name }}</div>@endif</div>
        </div>
        <dl class="mt-4 grid grid-cols-3 gap-y-2 text-sm">
            <dt class="text-slate-500">{{ __('Телефон') }}</dt><dd class="col-span-2">{{ $student->phone ?? '—' }}</dd>
            <dt class="text-slate-500">Telegram</dt><dd class="col-span-2">{{ $student->telegram ?? '—' }}</dd>
            <dt class="text-slate-500">{{ __('Дата рождения') }}</dt><dd class="col-span-2">{{ fdate($student->birth_date) }}</dd>
            <dt class="text-slate-500">{{ __('Пол') }}</dt><dd class="col-span-2">{{ ['male' => __('Мужской'), 'female' => __('Женский')][$student->gender] ?? '—' }}</dd>
            <dt class="text-slate-500">{{ __('Адрес') }}</dt><dd class="col-span-2">{{ $student->address ?? '—' }}</dd>
            <dt class="text-slate-500">{{ __('Филиал') }}</dt><dd class="col-span-2">{{ $student->branch?->name ?? '—' }}</dd>
            <dt class="text-slate-500">{{ __('Источник') }}</dt><dd class="col-span-2">{{ $student->source?->name ?? '—' }}</dd>
            <dt class="text-slate-500">{{ __('Менеджер') }}</dt><dd class="col-span-2">{{ $student->manager?->name ?? '—' }}</dd>
            <dt class="text-slate-500">{{ __('Регистрация') }}</dt><dd class="col-span-2">{{ fdate($student->registered_at) }}</dd>
            @if($student->lead)<dt class="text-slate-500">{{ __('Лид') }}</dt><dd class="col-span-2">@can('leads.view')<a class="link" href="{{ route('leads.show', $student->lead_id) }}">#{{ $student->lead_id }}</a>@else #{{ $student->lead_id }}@endcan</dd>@endif
        </dl>
        @if($student->notes)<p class="mt-3 rounded-lg bg-slate-50 p-2 text-sm dark:bg-slate-800">{{ $student->notes }}</p>@endif

        @if($canManage)
        <details class="mt-4"><summary class="cursor-pointer text-sm font-medium text-brand-600">{{ __('Сменить статус') }}</summary>
            <form method="POST" action="{{ route('students.status', $student) }}" class="mt-2 space-y-2" x-data="{ st: '{{ $student->status }}' }">@csrf
                <select name="status" x-model="st" class="input">@foreach(\App\Models\Student::STATUSES as $k => $v)<option value="{{ $k }}">{{ __($v) }}</option>@endforeach</select>
                <select name="expulsion_reason_id" x-show="st === 'expelled'" class="input"><option value="">{{ __('Причина отчисления…') }}</option>@foreach($reasons as $r)<option value="{{ $r->id }}">{{ $r->name }}</option>@endforeach</select>
                <button class="btn-secondary w-full">{{ __('Применить') }}</button>
            </form></details>
        @endif
    </div>

    {{-- Parents --}}
    <div class="card card-body">
        <h2 class="mb-2 text-sm font-semibold">{{ __('Родители') }}</h2>
        <ul class="divide-y text-sm dark:divide-slate-800">
            @forelse($student->guardians as $g)
                <li class="flex items-center justify-between py-2"><div><a class="link font-medium" href="{{ route('guardians.show', $g->id) }}">{{ $g->full_name }}</a>
                    <div class="text-xs text-slate-500">{{ $g->pivot->relation }} {{ $g->phone }} {{ $g->telegram }}</div></div>
                    @if($canManage)<form method="POST" action="{{ route('students.guardians.detach', [$student, $g->id]) }}" onsubmit="return confirm('{{ __('Отвязать?') }}')">@csrf @method('DELETE')<button class="text-xs text-rose-600">✕</button></form>@endif</li>
            @empty<li class="py-2 text-slate-400">—</li>@endforelse
        </ul>
        @include('partials.tg-link', ['type' => 'student', 'entity' => $student])
        @if($canManage)
        <details class="mt-2"><summary class="cursor-pointer text-sm font-medium text-brand-600">+ {{ __('Добавить родителя') }}</summary>
            <form method="POST" action="{{ route('students.guardians.attach', $student) }}" class="mt-2 space-y-2">@csrf
                <select name="guardian_id" class="input"><option value="">{{ __('Новый родитель…') }}</option>@foreach($allGuardians as $g)<option value="{{ $g->id }}">{{ $g->full_name }} {{ $g->phone }}</option>@endforeach</select>
                <input name="full_name" class="input" placeholder="{{ __('ФИО (для нового)') }}">
                <div class="grid grid-cols-2 gap-2"><input name="phone" class="input" placeholder="{{ __('Телефон') }}"><input name="relation" class="input" placeholder="{{ __('Мама / Папа…') }}"></div>
                <button class="btn-secondary w-full">{{ __('Добавить') }}</button>
            </form></details>
        @endif
    </div>
  </div>

  <div class="space-y-4 xl:col-span-2">
    {{-- Finance + attendance summary --}}
    <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
        @if($finance)
            <x-stat :label="__('Начислено')" :value="money($finance['charged'])" />
            <x-stat :label="__('Оплачено')" :value="money($finance['paid'])" tone="good" />
            <x-stat :label="$finance['balance'] > 0 ? __('Долг') : __('Баланс')" :value="money(abs($finance['balance']))" :tone="$finance['balance'] > 0 ? 'bad' : 'good'" :hint="$finance['balance'] < 0 ? __('переплата') : null" />
        @endif
        <x-stat :label="__('Посещаемость')" :value="$att['percent'] === null ? '—' : $att['percent'].'%'" :hint="$att['visited'].' / '.$att['total'].' · '.__('пропущено').' '.$att['missed']" />
    </div>

    {{-- Learning --}}
    <div class="card">
        <div class="flex items-center justify-between border-b p-4 dark:border-slate-800"><h2 class="text-sm font-semibold">{{ __('Обучение') }}</h2></div>
        <div class="overflow-x-auto"><table class="min-w-full text-sm">
            <thead class="bg-slate-50 dark:bg-slate-800/50"><tr><th class="th">{{ __('Группа') }}</th><th class="th">{{ __('Период') }}</th>
                @if($canFinance)<th class="th text-right">{{ __('Стоимость') }}</th><th class="th text-right">{{ __('Долг') }}</th><th class="th">{{ __('След. оплата') }}</th>@endif<th class="th">{{ __('Статус') }}</th>@if($canManage)<th class="th"></th>@endif</tr></thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
            @forelse($enrollments as $e)
                <tr>
                    <td class="td"><a class="link font-medium" href="{{ route('groups.show', $e->group_id) }}">{{ $e->group?->name }}</a><div class="text-[11px] text-slate-500">{{ $e->group?->course?->name }}@if($e->group?->teacher) · {{ $e->group->teacher->full_name }}@endif</div>@if($e->transferred_from_id)<div class="text-[11px] text-slate-400">↩ {{ __('переведён') }}</div>@endif</td>
                    <td class="td whitespace-nowrap">{{ fdate($e->joined_at) }} — {{ $e->left_at ? fdate($e->left_at) : '…' }}</td>
                    @if($canFinance)
                    <td class="td text-right whitespace-nowrap">{{ money($e->charge(), false) }}@if($e->discount > 0)<div class="text-[11px] text-slate-400">−{{ money($e->discount, false) }}</div>@endif</td>
                    <td class="td text-right font-medium {{ ($e->debt?->balance ?? 0) > 0 ? 'text-rose-600' : 'text-slate-400' }}">{{ money($e->debt?->balance ?? 0, false) }}</td>
                    <td class="td whitespace-nowrap">{{ fdate($e->next_payment_date) }}</td>
                    @endif
                    <td class="td"><x-badge :color="['active'=>'emerald','frozen'=>'blue','completed'=>'indigo','left'=>'rose','transferred'=>'slate'][$e->status] ?? 'slate'">{{ __(\App\Models\GroupStudent::STATUSES[$e->status] ?? $e->status) }}</x-badge></td>
                    @if($canManage)
                    <td class="td text-right" x-data="{ o: false }">
                        @if($e->isCurrent())
                        <div class="relative inline-block text-left"><button @click="o = !o" class="btn-ghost btn-sm">⋯</button>
                            <div x-cloak x-show="o" @click.outside="o = false" class="absolute right-0 z-20 mt-1 w-64 space-y-1 rounded-xl border bg-white p-2 text-sm shadow-xl dark:border-slate-700 dark:bg-slate-900">
                                <form method="POST" action="{{ route('enrollments.action', [$e, $e->status === 'frozen' ? 'unfreeze' : 'freeze']) }}">@csrf<button class="w-full rounded px-2 py-1.5 text-left hover:bg-slate-100 dark:hover:bg-slate-800">{{ $e->status === 'frozen' ? __('Вернуть в обучение') : __('Заморозить') }}</button></form>
                                <form method="POST" action="{{ route('enrollments.action', [$e, 'renew']) }}">@csrf<button class="w-full rounded px-2 py-1.5 text-left hover:bg-slate-100 dark:hover:bg-slate-800">{{ __('Продлить (доп. начисление)') }}</button></form>
                                <form method="POST" action="{{ route('enrollments.action', [$e, 'complete']) }}" onsubmit="return confirm('{{ __('Завершить обучение?') }}')">@csrf<button class="w-full rounded px-2 py-1.5 text-left hover:bg-slate-100 dark:hover:bg-slate-800">{{ __('Завершить обучение') }}</button></form>
                                <form method="POST" action="{{ route('enrollments.action', [$e, 'leave']) }}" onsubmit="return confirm('{{ __('Убрать из группы?') }}')">@csrf<button class="w-full rounded px-2 py-1.5 text-left text-rose-600 hover:bg-slate-100 dark:hover:bg-slate-800">{{ __('Убрать из группы') }}</button></form>
                                <form method="POST" action="{{ route('enrollments.transfer', $e) }}" class="space-y-1 border-t pt-2 dark:border-slate-700">@csrf
                                    <select name="group_id" class="input !py-1 text-xs" required><option value="">{{ __('Перевести в группу…') }}</option>@foreach($groupsOpen as $g)@if($g->id !== $e->group_id)<option value="{{ $g->id }}">{{ $g->name }}</option>@endif @endforeach</select>
                                    <button class="btn-secondary btn-sm w-full">{{ __('Перевести') }}</button></form>
                                @if($canFinance)
                                <form method="POST" action="{{ route('enrollments.update', $e) }}" class="space-y-1 border-t pt-2 dark:border-slate-700">@csrf @method('PUT')
                                    <div class="grid grid-cols-2 gap-1"><input type="number" min="0" name="price" value="{{ $e->price }}" class="input !py-1 text-xs" title="{{ __('Цена') }}"><input type="number" min="0" name="discount" value="{{ $e->discount }}" class="input !py-1 text-xs" title="{{ __('Скидка') }}"></div>
                                    <input type="date" name="next_payment_date" value="{{ $e->next_payment_date?->toDateString() }}" class="input !py-1 text-xs">
                                    <button class="btn-secondary btn-sm w-full">{{ __('Сохранить цену/дату') }}</button></form>
                                @endif
                            </div></div>
                        @endif
                    </td>
                    @endif
                </tr>
            @empty<tr><td colspan="8" class="px-3 py-6 text-center text-slate-400">{{ __('Не записан в группы') }}</td></tr>@endforelse
            </tbody></table></div>
        @if($canManage && ! in_array($student->status, ['expelled', 'archived']))
        <form method="POST" action="{{ route('enrollments.store') }}" class="grid gap-2 border-t p-4 dark:border-slate-800 sm:grid-cols-5">@csrf
            <input type="hidden" name="student_id" value="{{ $student->id }}">
            <select name="group_id" class="input sm:col-span-2" required><option value="">{{ __('Записать в группу…') }}</option>@foreach($groupsOpen as $g)<option value="{{ $g->id }}">{{ $g->name }} — {{ $g->course?->name }}</option>@endforeach</select>
            <input type="number" min="0" name="price" class="input" placeholder="{{ __('Цена (по группе)') }}"><input type="number" min="0" name="discount" class="input" placeholder="{{ __('Скидка') }}">
            <button class="btn-primary">{{ __('Записать') }}</button>
        </form>
        @endif
    </div>

    @if($canFinance)
    <div class="card">
        <div class="flex items-center justify-between border-b p-4 dark:border-slate-800"><h2 class="text-sm font-semibold">{{ __('Оплаты') }}</h2>@can('payments.create')<a href="{{ route('payments.create', ['student_id' => $student->id]) }}" class="link text-sm">+ {{ __('Принять оплату') }}</a>@endcan</div>
        <div class="overflow-x-auto"><table class="min-w-full text-sm"><tbody class="divide-y divide-slate-100 dark:divide-slate-800">
            @forelse($payments as $p)
                <tr><td class="td whitespace-nowrap text-slate-500">{{ $p->paid_at->format('d.m.Y H:i') }}</td><td class="td">{{ $p->group?->name }}</td><td class="td">{{ $p->method?->name }}</td>
                    <td class="td">{{ $p->comment }}</td><td class="td text-xs text-slate-400">{{ $p->cashier?->name }}</td>
                    <td class="td text-right font-medium whitespace-nowrap {{ $p->type === 'refund' ? 'text-rose-600' : 'text-emerald-600' }}">{{ $p->type === 'refund' ? '−' : '+' }}{{ money($p->amount, false) }} @if($p->type !== 'payment')<x-badge color="amber">{{ __(\App\Models\Payment::TYPES[$p->type]) }}</x-badge>@endif</td></tr>
            @empty<tr><td class="px-3 py-6 text-center text-slate-400">{{ __('Оплат нет') }}</td></tr>@endforelse
        </tbody></table></div>
    </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-2">
        <div class="card card-body">
            <h2 class="mb-2 text-sm font-semibold">{{ __('Последние занятия') }}</h2>
            <ul class="divide-y text-sm dark:divide-slate-800">
                @forelse($recentAtt as $a)
                    <li class="flex items-center justify-between py-1.5"><span>{{ fdate($a->lesson?->lesson_date, 'd.m') }} · {{ $a->lesson?->group?->name }}</span>
                        <x-badge :color="['present'=>'emerald','late'=>'amber','absent'=>'rose','excused'=>'blue'][$a->status]">{{ __(\App\Models\Attendance::STATUSES[$a->status]) }}</x-badge></li>
                @empty<li class="py-2 text-slate-400">—</li>@endforelse
            </ul>
        </div>
        <div class="card card-body">
            <h2 class="mb-2 text-sm font-semibold">{{ __('История') }}</h2>
            <ol class="max-h-72 space-y-3 overflow-y-auto border-l pl-4 text-sm dark:border-slate-700">
                @forelse($events as $ev)
                    <li class="relative"><span class="absolute -left-[21px] top-1.5 h-2 w-2 rounded-full bg-brand-600"></span>
                        <div class="text-xs text-slate-500">{{ $ev->created_at->format('d.m.Y H:i') }} · {{ $ev->user?->name ?? 'system' }}</div><div>{{ $ev->title }}</div></li>
                @empty<li class="text-slate-400">—</li>@endforelse
            </ol>
        </div>
    </div>
  </div>
</div>
@endsection
