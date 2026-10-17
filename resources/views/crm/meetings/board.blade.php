@extends('layouts.app')
@section('title', t('meetings.slotsTitle'))
@section('content')
    @php
        $day = \Carbon\CarbonImmutable::parse($date);
        $nav = fn ($d) => route('crm.meetings.board', ['date' => $d->toDateString()]);
    @endphp
    <x-page-header :title="t('meetings.slotsTitle')" :subtitle="t('meetings.slotsSubtitle')" :back="route('crm.meetings.index')" />
    <form method="GET" class="mb-4 flex flex-wrap items-center gap-2">
        <a href="{{ $nav($day->subDay()) }}" class="btn btn-outline btn-icon" aria-label="{{ t('meetings.prev') }}"><x-icon name="chevron-left" /></a>
        <input type="date" name="date" value="{{ $date }}" class="input w-auto" aria-label="{{ t('meetings.date') }}" onchange="this.form.submit()">
        <a href="{{ $nav($day->addDay()) }}" class="btn btn-outline btn-icon" aria-label="{{ t('meetings.next') }}"><x-icon name="chevron-right" /></a>
        <a href="{{ route('crm.meetings.board') }}" class="btn btn-ghost btn-sm">{{ t('meetings.today') }}</a>
        <span class="text-sm text-muted-foreground">{{ t('schedules.weekdays.'.$day->isoWeekday()) }}, {{ $day->format('d.m.Y') }}</span>
    </form>

    @if (! $grid)
        <div class="card"><x-empty icon="users" :title="t('meetings.boardEmpty')">{{ t('meetings.boardEmptyText') }}</x-empty></div>
    @else
        <x-table>
            <thead>
                <tr>
                    <th class="sticky left-0 z-10 w-16 bg-surface">{{ t('meetings.time') }}</th>
                    @foreach ($grid as $u)
                        <th class="min-w-40">{{ $u['name'] }}
                            <span class="block text-xs font-normal text-muted-foreground">{{ $u['dayOff'] ? t('meetings.dayOff') : $u['settings']['from'].'–'.$u['settings']['to'].' · '.t('crm.common.minutes', ['n' => $u['settings']['slot']]) }}</span>
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($times as $time)
                    <tr>
                        <td class="sticky left-0 z-10 bg-surface font-medium tabular-nums">{{ $time }}</td>
                        @foreach ($grid as $u)
                            @php($cell = $cells[$u['id']][$time] ?? null)
                            @if (! $cell)
                                <td class="text-muted-foreground">{{ t('meetings.outside') }}</td>
                            @elseif ($cell['state'] === 'busy')
                                <td class="bg-danger-soft text-xs text-danger">
                                    <span class="tabular-nums">{{ $cell['time'] }}–{{ $cell['end'] }}</span>
                                    @foreach ($cell['meetings'] as $bm)
                                        <span class="block">{{ t('meetings.busyBy', ['names' => implode(', ', $bm['participants'])]) }}</span>
                                        @if ($bm['visible'])
                                            <a href="{{ $bm['link'] }}" class="block truncate text-foreground underline-offset-2 hover:underline">{{ $bm['time'] }} · {{ $bm['subject'] }}</a>
                                        @endif
                                    @endforeach
                                </td>
                            @elseif ($cell['state'] === 'past')
                                <td class="bg-muted text-xs text-muted-foreground"><span class="tabular-nums">{{ $cell['time'] }}–{{ $cell['end'] }}</span> · {{ t('meetings.past') }}</td>
                            @else
                                <td class="bg-success-soft text-xs text-success"><span class="tabular-nums">{{ $cell['time'] }}–{{ $cell['end'] }}</span> · {{ t('meetings.free') }}</td>
                            @endif
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </x-table>
    @endif
@endsection
