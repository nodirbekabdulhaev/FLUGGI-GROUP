@extends('layouts.app')
@section('title', t('attendance.title'))
@section('content')
    @php
        use App\Domain\People;
        $tone = ['PRESENT' => 'green', 'LATE' => 'yellow', 'ABSENT' => 'red', 'DAY_OFF' => 'gray', 'VACATION' => 'blue', 'SICK' => 'blue'];
        $q = '/attendance?from='.$from.'&to='.$to;
    @endphp
    <x-page-header :title="t('attendance.title')" :subtitle="t('attendance.subtitle')">
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <input type="date" name="from" value="{{ $from }}" class="input w-auto" aria-label="{{ t('attendance.from') }}">
            <input type="date" name="to" value="{{ $to }}" class="input w-auto" aria-label="{{ t('attendance.to') }}">
            <button class="btn btn-outline">{{ t('period.apply') }}</button>
        </form>
        @if ($manage)
            <button type="button" class="btn btn-primary" @click="$dispatch('open-modal', 'att-new')"><x-icon name="plus" /> {{ t('attendance.edit') }}</button>
        @endif
    </x-page-header>
    @if ($today['tracked'])<div class="mb-4 max-w-md">@include('people.partials.attendance-card')</div>@endif
    <x-tabs :items="[$q.'&tab=summary' => t('attendance.summaryTitle'), $q.'&tab=records' => t('attendance.records').' ('.$records->count().')']" :active="$q.'&tab='.$tab" />
    <div class="card">
        @if ($tab === 'summary')
            @if (empty($summary))
                <x-empty icon="calendar" :title="t('attendance.empty')" />
            @else
                <div class="overflow-x-auto"><table class="table">
                    <thead><tr><th>{{ t('attendance.employee') }}</th>@foreach (['present', 'lateCount', 'lateTotal', 'absent', 'vacation', 'sick', 'workHours'] as $k)<th class="text-right">{{ t('attendance.'.$k) }}</th>@endforeach</tr></thead>
                    <tbody>
                    @foreach ($summary as $s)
                        <tr>
                            <td class="font-medium">{{ $s['user']->full_name }}</td>
                            <td class="text-right tabular-nums">{{ $s['present'] }}</td>
                            <td @class(['text-right tabular-nums', 'text-warning' => $s['late']])>{{ $s['late'] }}</td>
                            <td class="text-right tabular-nums">{{ $s['lateMinutes'] }}</td>
                            <td @class(['text-right tabular-nums', 'text-danger' => $s['absent']])>{{ $s['absent'] }}</td>
                            <td class="text-right tabular-nums">{{ $s['vacation'] }}</td>
                            <td class="text-right tabular-nums">{{ $s['sick'] }}</td>
                            <td class="text-right tabular-nums">{{ $s['workHours'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
            @endif
        @elseif ($records->isEmpty())
            <x-empty icon="calendar" :title="t('attendance.empty')">{{ t('attendance.noMark') }}</x-empty>
        @else
            <div class="overflow-x-auto"><table class="table">
                <thead><tr><th>{{ t('attendance.date') }}</th><th>{{ t('attendance.employee') }}</th><th>{{ t('attendance.statusLabel') }}</th><th>{{ t('attendance.in') }}</th><th>{{ t('attendance.out') }}</th>
                    <th class="text-right">{{ t('attendance.lateMin') }}</th><th class="text-right">{{ t('attendance.hours') }}</th><th>{{ t('attendance.comment') }}</th>@if ($manage)<th></th>@endif</tr></thead>
                <tbody>
                @foreach ($records as $r)
                    <tr>
                        <td class="whitespace-nowrap">{{ d($r->date) }}</td>
                        <td>{{ $r->user?->full_name }}</td>
                        <td><x-badge :color="$tone[$r->status]">{{ t('attendance.status.'.$r->status) }}</x-badge></td>
                        <td class="tabular-nums">{{ $r->check_in ? People::tashkentTime($r->check_in) : '—' }}</td>
                        <td class="tabular-nums">{{ $r->check_out ? People::tashkentTime($r->check_out) : '—' }}</td>
                        <td class="text-right tabular-nums">{{ $r->late_minutes ?: '—' }}</td>
                        <td class="text-right tabular-nums">{{ $r->work_minutes ? number_format($r->work_minutes / 60, 1, ',', '') : '—' }}</td>
                        <td class="max-w-56 truncate text-muted-foreground">{{ $r->comment }}@if ($r->editedBy)<span class="block text-xs">✎ {{ $r->editedBy->full_name }}</span>@endif</td>
                        @if ($manage)<td class="text-right"><a href="{{ $q }}&tab=records&edit={{ $r->id }}" class="btn btn-ghost btn-icon" aria-label="{{ t('attendance.edit') }}"><x-icon name="edit" /></a></td>@endif
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
    </div>
    @if ($manage)
        @foreach (array_filter(['att-new' => null, 'att-edit' => $editing ?? null], fn ($v, $k) => $k === 'att-new' || $v, ARRAY_FILTER_USE_BOTH) as $mid => $rec)
            <x-modal :name="$mid" :title="t('attendance.editTitle')" :open="$rec ? true : $errors->any()">
                <form method="POST" action="/attendance" class="space-y-4" x-data="{ status: @js(old('status', $rec?->status ?? 'PRESENT')) }">
                    @csrf @method('PUT')
                    <input type="hidden" name="from" value="{{ $from }}"><input type="hidden" name="to" value="{{ $to }}">
                    @if ($rec)
                        <input type="hidden" name="user_id" value="{{ $rec->user_id }}"><input type="hidden" name="date" value="{{ $rec->date->format('Y-m-d') }}">
                        <p class="text-sm"><span class="font-medium">{{ $rec->user?->full_name }}</span> · {{ d($rec->date) }}</p>
                    @else
                        <x-select name="user_id" :id="$mid.'-user'" :label="t('attendance.employee')" placeholder="—" :options="$people" required />
                        <x-field name="date" type="date" :id="$mid.'-date'" :label="t('attendance.date')" :value="\App\Support\Format::today()->toDateString()" required />
                    @endif
                    <div>
                        <label class="label" for="{{ $mid }}-status">{{ t('attendance.statusLabel') }}</label>
                        <select id="{{ $mid }}-status" name="status" class="input" x-model="status">
                            @foreach (\App\Services\People\PeopleService::ATTENDANCE_STATUSES as $st)<option value="{{ $st }}">{{ t('attendance.status.'.$st) }}</option>@endforeach
                        </select>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2" x-show="status === 'PRESENT' || status === 'LATE'">
                        <x-field name="check_in" type="time" :id="$mid.'-in'" :label="t('attendance.in')" :value="$rec?->check_in ? People::tashkentTime($rec->check_in) : null" />
                        <x-field name="check_out" type="time" :id="$mid.'-out'" :label="t('attendance.out')" :value="$rec?->check_out ? People::tashkentTime($rec->check_out) : null" />
                    </div>
                    <x-field name="comment" :id="$mid.'-comment'" :label="t('attendance.comment')" :value="$rec?->comment" />
                    <div class="flex justify-end gap-2">
                        <a href="{{ $q }}&tab={{ $tab }}" class="btn btn-outline">{{ t('common.cancel') }}</a>
                        <button class="btn btn-primary">{{ t('common.save') }}</button>
                    </div>
                </form>
            </x-modal>
        @endforeach
    @endif
@endsection
