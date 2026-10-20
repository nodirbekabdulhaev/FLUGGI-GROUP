<?php

namespace App\Services\Import;

use App\Models\Attendance;
use App\Models\User;
use App\Services\People\KpiService;
use App\Services\People\PeopleService;
use App\Support\Format;

/**
 * Посещаемость: запись на (сотрудник, дата) вносится тем же способом, что и ручное исправление HR
 * (PeopleService::upsertAttendance) — опоздание по графику и отработанные минуты считаются там же.
 * Посещаемость ведётся только у менеджеров и РОП; будущие даты не принимаются.
 */
final class AttendanceImporter extends Importer
{
    /** Слова статусов, которых нет среди подписей интерфейса. */
    private const WORDS = [
        'был' => 'PRESENT', 'была' => 'PRESENT', 'присутствует' => 'PRESENT', 'пришёл' => 'PRESENT', 'пришел' => 'PRESENT',
        'опоздание' => 'LATE', 'опоздала' => 'LATE', 'нет' => 'ABSENT', 'прогул' => 'ABSENT', 'отсутствует' => 'ABSENT',
        'больничный лист' => 'SICK', 'болеет' => 'SICK',
        'keldi' => 'PRESENT', 'bor' => 'PRESENT', 'kechikdi' => 'LATE', 'kechikish' => 'LATE', 'kelmadi' => 'ABSENT', "yo'q" => 'ABSENT',
        'dam olish' => 'DAY_OFF', 'dam olish kuni' => 'DAY_OFF', "ta'til" => 'VACATION', 'otpusk' => 'VACATION', 'kasal' => 'SICK', 'kasallik' => 'SICK',
        'day off' => 'DAY_OFF', 'off' => 'DAY_OFF', 'holiday' => 'VACATION', 'sick leave' => 'SICK', 'ill' => 'SICK',
    ];

    public static function entity(): string
    {
        return 'attendance';
    }

    public static function permission(): string
    {
        return 'attendance.manage';
    }

    public static function backUrl(): string
    {
        return '/attendance';
    }

    protected function columns(): array
    {
        return [
            'employee' => ['required' => true],
            'date' => ['type' => 'date', 'required' => true],
            'attendance_status' => [],
            'check_in' => ['type' => 'time'],
            'check_out' => ['type' => 'time'],
            'comment' => [],
        ];
    }

    public function rules(): array
    {
        return [t('import.rules.attendanceRoles'), t('import.rules.attendanceTimes'), t('import.rules.attendanceUpsert')];
    }

    protected function example(): array
    {
        return [
            'employee' => access()->can('employee.read') ? (string) ($this->trackedPeople()->first()?->email ?? 'manager@example.uz') : 'manager@example.uz',
            'date' => Format::today()->subDay()->toDateString(), 'attendance_status' => 'PRESENT',
            'check_in' => '09:05', 'check_out' => '18:00', 'comment' => '',
        ];
    }

    protected function reference(): array
    {
        return [
            $this->col('employee') => self::people($this->trackedPeople()),
            $this->col('attendance_status') => self::enumRows(PeopleService::ATTENDANCE_STATUSES, 'attendance.status'),
        ];
    }

    /** Сотрудники с посещаемостью в зоне права attendance.manage. */
    private function trackedPeople()
    {
        return KpiService::users('attendance.manage')->with('role')->where('users.status', 'ACTIVE')
            ->whereHas('role', fn ($q) => $q->whereIn('code', PeopleService::ATTENDANCE_ROLES))->orderBy('full_name')->get();
    }

    protected function label(array $v): string
    {
        return trim(($v['employee'] ?? '').' · '.($v['date'] ?? ''), ' ·');
    }

    protected function check(array $v, ImportContext $ctx): array
    {
        $user = $this->employee($v['employee'], $ctx);
        $date = Values::date($v['date'], $this->col('date'));
        if ($date > Values::today()) {
            throw new RowError(t('import.errors.futureDate'));
        }
        $status = Values::enum($v['attendance_status'], PeopleService::ATTENDANCE_STATUSES, 'attendance.status', $this->col('attendance_status'), self::WORDS);
        $in = Values::time($v['check_in'], $this->col('check_in'));
        $out = Values::time($v['check_out'], $this->col('check_out'));
        $status ??= $in ? 'PRESENT' : null;
        if (! $status) {
            throw new RowError(t('import.errors.statusRequired'));
        }
        if ($out && ! $in) {
            throw new RowError(t('import.errors.outWithoutIn'));
        }
        if ($in && $out && $out <= $in) {
            throw new RowError(t('attendance.errors.outBeforeIn'));
        }
        if (! in_array($status, ['PRESENT', 'LATE'], true) && ($in || $out)) {
            throw new RowError(t('import.errors.timesForAbsence'));
        }
        $data = [
            'user_id' => $user->id, 'date' => $date, 'status' => $status, 'check_in' => $in, 'check_out' => $out,
            'comment' => Values::text($v['comment'], 500, $this->col('comment')),
        ];
        $exists = Attendance::where('user_id', $user->id)->whereDate('date', $date)->exists();

        return [
            'status' => $exists ? 'update' : 'create',
            'reason' => $exists ? t('import.reasons.attendanceUpdate') : null,
            'data' => $data + ['name' => $user->full_name],
            'keys' => ['att:'.$user->id.':'.$date],
        ];
    }

    /** Сотрудник по email или точному ФИО: в зоне права и только менеджер/РОП. */
    private function employee(string $value, ImportContext $ctx): User
    {
        $q = User::with('role')->where('status', 'ACTIVE');
        if (str_contains($value, '@')) {
            $users = $q->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($value))])->get();
        } else {
            $users = $q->whereRaw('LOWER(TRIM(full_name)) = ?', [mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value)))])->get();
        }
        if ($users->count() > 1) {
            throw new RowError(t('import.errors.employeeAmbiguous', ['value' => $value]));
        }
        $user = $users->first();
        $visible = $ctx->cached('attendanceScope', fn () => KpiService::users('attendance.manage')->pluck('users.id')->flip()->all());
        if (! $user || ! isset($visible[$user->id])) {
            throw new RowError(t('import.errors.employeeNotFound', ['value' => $value]));
        }
        if (! PeopleService::tracksAttendance((string) $user->role?->code)) {
            throw new RowError(t('attendance.errors.notTrackedUser'));
        }

        return $user;
    }

    protected function create(array $data, ImportContext $ctx): string
    {
        PeopleService::upsertAttendance($data);

        return $data['name'].' · '.Format::date($data['date']);
    }
}
