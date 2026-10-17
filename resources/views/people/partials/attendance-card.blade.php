{{-- Отметка прихода/ухода (ТЗ §35) — только менеджеры и РОП --}}
@if ($today && $today['tracked'])
    @php($r = $today['record'])
    @php($sch = $today['schedule'])
    <div class="card card-body grid content-start gap-3">
        <div class="flex items-center justify-between gap-3">
            <p class="flex items-center gap-2 text-sm text-muted-foreground"><x-icon name="clock" /> {{ t('attendance.today') }}</p>
            @if ($r)<x-badge :color="$r->status === 'LATE' ? 'yellow' : 'green'">{{ t('attendance.status.'.$r->status) }}</x-badge>@endif
        </div>
        <p class="text-sm">{{ $sch ? t('attendance.schedule').': '.$sch->name.' '.$sch->start_time.'–'.$sch->end_time : t('attendance.noSchedule') }}@if (! $today['workday']) · {{ t('attendance.dayOff') }}@endif</p>
        @if ($r?->check_in)
            <p class="text-sm tabular-nums">{{ t('attendance.in') }} {{ \App\Domain\People::tashkentTime($r->check_in) }}@if ($r->late_minutes) ({{ t('attendance.late', ['minutes' => $r->late_minutes]) }})@endif
                @if ($r->check_out) · {{ t('attendance.out') }} {{ \App\Domain\People::tashkentTime($r->check_out) }}@endif</p>
        @endif
        @if (! $r?->check_in)
            <form method="POST" action="/attendance/check-in">@csrf<button class="btn btn-primary"><x-icon name="play" /> {{ t('attendance.checkIn') }}</button></form>
        @elseif (! $r->check_out)
            <form method="POST" action="/attendance/check-out">@csrf<button class="btn btn-outline"><x-icon name="pause" /> {{ t('attendance.checkOut') }}</button></form>
        @endif
    </div>
@endif
