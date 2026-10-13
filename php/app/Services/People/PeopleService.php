<?php

namespace App\Services\People;

use App\Domain\People;
use App\Domain\Tasks;
use App\Exceptions\BusinessRule;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\KpiTarget;
use App\Models\PayrollEntry;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Finance\Period;
use App\Support\Audit;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Цели KPI, графики, посещаемость и зарплата (ТЗ §31, §32, §35, §36). */
final class PeopleService
{
    /**
     * Посещаемость и фиксированный оклад — только у менеджеров и РОП. Исполнители получают
     * сдельно (начисления по проектам), CEO и проект-менеджер приход не отмечают.
     */
    public const ATTENDANCE_ROLES = ['MANAGER', 'ROP'];

    public const FIXED_SALARY_ROLES = ['MANAGER', 'ROP'];

    public const ATTENDANCE_STATUSES = ['PRESENT', 'LATE', 'ABSENT', 'DAY_OFF', 'VACATION', 'SICK'];

    public static function tracksAttendance(string $role): bool
    {
        return in_array($role, self::ATTENDANCE_ROLES, true);
    }

    public static function hasFixedSalary(string $role): bool
    {
        return in_array($role, self::FIXED_SALARY_ROLES, true);
    }

    // ─────────────────────────── Цели (§31) ───────────────────────────

    /**
     * CEO/HR — любому сотруднику; РОП — сотрудникам своего отдела, но не себе.
     *
     * @param  list<array{metric:string,value:?string,currency:string}>  $targets
     */
    public static function setTargets(string $userId, string $period, array $targets): void
    {
        $access = access();
        $scope = $access->scope('kpi.target.manage');
        if ($scope !== 'ALL' && $userId === $access->id()) {
            abort(403, t('kpi.errors.ownTargets'));
        }
        abort_unless(KpiService::users('kpi.target.manage')->whereKey($userId)->exists(), 404);
        DB::transaction(function () use ($userId, $period, $targets, $access) {
            foreach ($targets as $t) {
                $before = KpiTarget::where(['user_id' => $userId, 'period' => $period, 'metric' => $t['metric']])->first();
                $value = $t['value'];
                if ($value === null) {
                    $before?->delete();
                } else {
                    if (! preg_match('/^\d{1,16}(\.\d{1,2})?$/', $value)) {
                        throw new BusinessRule(t('kpi.errors.value'));
                    }
                    KpiTarget::updateOrCreate(
                        ['user_id' => $userId, 'period' => $period, 'metric' => $t['metric']],
                        ['target_value' => $value, 'currency' => $t['currency'], 'set_by_id' => $access->id()],
                    );
                }
                $old = $before ? (string) $before->target_value : null;
                $new = $value === null ? null : (string) BigDecimal::of($value)->toScale(2);
                if ($old !== $new) {
                    Audit::log('kpi.target', 'user', $userId, ["$period.{$t['metric']}" => ['old' => $old, 'new' => $value]]);
                }
            }
        });
    }

    // ─────────────────────────── Графики (§36) ───────────────────────────

    public static function schedules(): Collection
    {
        return WorkSchedule::with('employees.user:id,full_name')->orderBy('name')->get();
    }

    /** @param  array{name:string,role_code:?string,start_time:string,end_time:string,work_days:array,grace_minutes:int,is_active:bool,user_ids:array}  $input */
    public static function saveSchedule(?string $id, array $input): void
    {
        $days = array_values(array_unique(array_map('intval', $input['work_days'])));
        sort($days);
        $data = [
            'name' => $input['name'], 'role_code' => $input['role_code'] ?: null, 'start_time' => $input['start_time'], 'end_time' => $input['end_time'],
            'work_days' => $days, 'grace_minutes' => $input['grace_minutes'], 'is_active' => $input['is_active'],
        ];
        DB::transaction(function () use ($id, $data, $input) {
            if ($data['role_code'] && $data['is_active']) {
                $clash = WorkSchedule::where('role_code', $data['role_code'])->where('is_active', true)->when($id, fn ($q) => $q->whereKeyNot($id))->first();
                if ($clash) {
                    throw new BusinessRule(t('schedules.errors.roleClash', ['name' => $clash->name]));
                }
            }
            $before = $id ? WorkSchedule::findOrFail($id) : null;
            $oldTime = $before ? "{$before->start_time}–{$before->end_time}" : null;
            $s = $before ? tap($before)->update($data) : WorkSchedule::create($data);
            Employee::where('schedule_id', $s->id)->whereNotIn('user_id', $input['user_ids'] ?: ['-'])->update(['schedule_id' => null]);
            if ($input['user_ids']) {
                Employee::whereIn('user_id', $input['user_ids'])->update(['schedule_id' => $s->id]);
            }
            Audit::log($id ? 'schedule.update' : 'schedule.create', 'work_schedule', $s->id, [
                'time' => ['old' => $oldTime, 'new' => "{$s->start_time}–{$s->end_time}"],
                'users' => ['old' => null, 'new' => count($input['user_ids'])],
            ]);
        });
    }

    /** Индивидуальный график сотрудника, иначе — график его роли. */
    public static function scheduleOf(string $userId): ?WorkSchedule
    {
        $user = User::with(['role', 'employee.schedule'])->findOrFail($userId);
        $own = $user->employee?->schedule;
        if ($own?->is_active) {
            return $own;
        }

        return WorkSchedule::where('role_code', $user->role->code)->where('is_active', true)->first();
    }

    // ─────────────────────────── Посещаемость (§35) ───────────────────────────

    public static function today(): array
    {
        $access = access();
        $date = Tasks::companyDate(now());
        $schedule = self::scheduleOf($access->id());

        return [
            'tracked' => self::tracksAttendance($access->roleCode),
            'date' => $date,
            'schedule' => $schedule,
            'workday' => $schedule ? in_array(People::isoWeekday($date), $schedule->work_days, true) : true,
            'record' => Attendance::where('user_id', $access->id())->whereDate('date', $date)->first(),
        ];
    }

    /** Приход/уход отмечают только менеджеры и РОП: остальным раздел недоступен (403). */
    private static function assertTracked(string $role): void
    {
        if (! self::tracksAttendance($role)) {
            abort(403, t('attendance.errors.notTracked'));
        }
    }

    /** Отметка прихода: опоздание считается по графику (ТЗ §35–36). */
    public static function checkIn(?string $comment): Attendance
    {
        $access = access();
        self::assertTracked($access->roleCode);
        $now = CarbonImmutable::now();
        $date = Tasks::companyDate($now);

        return DB::transaction(function () use ($access, $now, $date, $comment) {
            $existing = Attendance::where('user_id', $access->id())->whereDate('date', $date)->lockForUpdate()->first();
            if ($existing?->check_in) {
                throw new BusinessRule(t('attendance.errors.alreadyIn'));
            }
            if ($existing && in_array($existing->status, ['VACATION', 'SICK', 'DAY_OFF'], true)) {
                throw new BusinessRule(t('attendance.errors.absenceDay'));
            }
            $schedule = self::scheduleOf($access->id());
            $late = $schedule && in_array(People::isoWeekday($date), $schedule->work_days, true)
                ? People::lateMinutes($now, $date, $schedule->start_time, $schedule->grace_minutes) : 0;
            $data = ['check_in' => $now, 'status' => $late > 0 ? 'LATE' : 'PRESENT', 'late_minutes' => $late, 'comment' => $comment ?? $existing?->comment];
            $row = $existing ? tap($existing)->update($data) : Attendance::create(['user_id' => $access->id(), 'date' => $date] + $data);
            Audit::log('attendance.check_in', 'attendance', $row->id, ['status' => ['old' => $existing?->getOriginal('status'), 'new' => $data['status']]]);

            return $row;
        });
    }

    public static function checkOut(?string $comment): Attendance
    {
        $access = access();
        self::assertTracked($access->roleCode);
        $now = CarbonImmutable::now();
        $date = Tasks::companyDate($now);

        return DB::transaction(function () use ($access, $now, $date, $comment) {
            $existing = Attendance::where('user_id', $access->id())->whereDate('date', $date)->lockForUpdate()->first();
            if (! $existing?->check_in) {
                throw new BusinessRule(t('attendance.errors.notIn'));
            }
            if ($existing->check_out) {
                throw new BusinessRule(t('attendance.errors.alreadyOut'));
            }
            $existing->update(['check_out' => $now, 'work_minutes' => People::workMinutes($existing->check_in, $now), 'comment' => $comment ?? $existing->comment]);
            Audit::log('attendance.check_out', 'attendance', $existing->id, null);

            return $existing;
        });
    }

    /** Записи посещаемости видимых сотрудников с отслеживаемой ролью. */
    private static function attendanceQuery(string $dateFrom, string $dateTo, ?string $userId)
    {
        return Attendance::query()
            ->whereIn('attendance.user_id', KpiService::users('attendance.read')->whereHas('role', fn ($q) => $q->whereIn('code', self::ATTENDANCE_ROLES))->select('users.id'))
            ->whereDate('attendance.date', '>=', $dateFrom)->whereDate('attendance.date', '<=', $dateTo)
            ->when($userId, fn ($q) => $q->where('attendance.user_id', $userId));
    }

    public static function list(string $dateFrom, string $dateTo, ?string $userId = null): Collection
    {
        return self::attendanceQuery($dateFrom, $dateTo, $userId)->with(['user:id,full_name', 'editedBy:id,full_name'])
            ->join('users', 'users.id', '=', 'attendance.user_id')->orderByDesc('attendance.date')->orderBy('users.full_name')
            ->select('attendance.*')->limit(2000)->get();
    }

    public static function summary(string $dateFrom, string $dateTo, ?string $userId = null): array
    {
        $users = KpiService::users('attendance.read')->where('users.status', 'ACTIVE')
            ->whereHas('role', fn ($q) => $q->whereIn('code', self::ATTENDANCE_ROLES))
            ->when($userId, fn ($q) => $q->whereKey($userId))->orderBy('full_name')->get(['users.id', 'users.full_name']);
        $rows = self::attendanceQuery($dateFrom, $dateTo, $userId)->get();

        return $users->map(function ($u) use ($rows) {
            $mine = $rows->where('user_id', $u->id);
            $count = fn ($s) => $mine->where('status', $s)->count();

            return [
                'user' => $u,
                'present' => $count('PRESENT') + $count('LATE'),
                'late' => $count('LATE'),
                'lateMinutes' => (int) $mine->sum('late_minutes'),
                'absent' => $count('ABSENT'),
                'dayOff' => $count('DAY_OFF'),
                'vacation' => $count('VACATION'),
                'sick' => $count('SICK'),
                'workHours' => number_format($mine->sum('work_minutes') / 60, 1, '.', ''),
            ];
        })->all();
    }

    /**
     * Внесение/исправление записи HR или CEO (отпуск, больничный, забыл отметиться).
     *
     * @param  array{user_id:string,date:string,status:string,check_in:?string,check_out:?string,comment:?string}  $input
     */
    public static function upsertAttendance(array $input): Attendance
    {
        $user = KpiService::users('attendance.manage')->with('role')->whereKey($input['user_id'])->first() ?? abort(404);
        if (! self::tracksAttendance($user->role->code)) {
            throw new BusinessRule(t('attendance.errors.notTrackedUser'), ['user_id' => t('attendance.errors.notTrackedUser')]);
        }
        $present = in_array($input['status'], ['PRESENT', 'LATE'], true);
        $checkIn = $present && ! empty($input['check_in']) ? People::atTashkent($input['date'], $input['check_in']) : null;
        $checkOut = $present && ! empty($input['check_out']) ? People::atTashkent($input['date'], $input['check_out']) : null;
        if ($checkIn && $checkOut && $checkOut <= $checkIn) {
            throw new BusinessRule(t('attendance.errors.outBeforeIn'), ['check_out' => t('attendance.errors.outBeforeIn')]);
        }
        $status = $input['status'];
        $late = 0;
        if ($present && $checkIn) {
            $schedule = self::scheduleOf($user->id);
            if ($schedule && in_array(People::isoWeekday($input['date']), $schedule->work_days, true)) {
                $late = People::lateMinutes($checkIn, $input['date'], $schedule->start_time, $schedule->grace_minutes);
            }
            $status = $late > 0 ? 'LATE' : 'PRESENT';
        }

        return DB::transaction(function () use ($user, $input, $status, $checkIn, $checkOut, $late) {
            $before = Attendance::where('user_id', $user->id)->whereDate('date', $input['date'])->lockForUpdate()->first();
            $time = fn ($v) => $v ? People::tashkentTime($v) : null;
            $old = ['status' => $before?->status, 'in' => $time($before?->check_in), 'out' => $time($before?->check_out)];
            $data = [
                'status' => $status, 'check_in' => $checkIn, 'check_out' => $checkOut, 'late_minutes' => $late,
                'work_minutes' => People::workMinutes($checkIn, $checkOut), 'comment' => $input['comment'] ?? null, 'edited_by_id' => access()->id(),
            ];
            $row = $before ? tap($before)->update($data) : Attendance::create(['user_id' => $user->id, 'date' => $input['date']] + $data);
            Audit::log('attendance.update', 'attendance', $row->id, [
                'status' => ['old' => $old['status'], 'new' => $status],
                'checkIn' => ['old' => $old['in'], 'new' => $input['check_in'] ?? null],
                'checkOut' => ['old' => $old['out'], 'new' => $input['check_out'] ?? null],
            ]);

            return $row;
        });
    }

    // ─────────────────────────── Зарплата (§32) ───────────────────────────

    /** Свою зарплату видит каждый, всех — только CEO (payroll.read на всю компанию). */
    public static function payroll(string $period): Collection
    {
        $access = access();
        $all = $access->scope('payroll.read') === 'ALL';

        return PayrollEntry::with(['user:id,full_name,role_id', 'user.role:id,code', 'user.employee:id,user_id,kpi_bonus_target', 'approvedBy:id,full_name'])
            ->where('period', $period)->when(! $all, fn ($q) => $q->where('user_id', $access->id()))
            ->join('users', 'users.id', '=', 'payroll_entries.user_id')->orderBy('users.full_name')->select('payroll_entries.*')->get();
    }

    private static function commissionOf(string $userId, string $period): string
    {
        return (string) (DB::table('commissions')->where('user_id', $userId)->where('period', $period)->whereIn('status', ['APPROVED', 'PAID'])->sum('amount_uzs') ?: '0');
    }

    private static function dec($v): string
    {
        return (string) BigDecimal::of($v === null || $v === '' ? '0' : (string) $v)->toScale(2);
    }

    /**
     * Посещаемость за месяц, % (2 знака): отработанные минуты / минуты по графику.
     * Рабочие дни — по графику сотрудника (с даты приёма до даты увольнения; в текущем месяце — по сегодня).
     * За день засчитывается не больше нормы графика: полдня = 50 %. Выходной (DAY_OFF) в норму не входит;
     * прогул, отпуск, больничный и день без отметки — 0. Нет графика — null (оклад начисляется полностью).
     */
    public static function attendancePct(User $user, string $period): ?string
    {
        $schedule = self::scheduleOf($user->id);
        if (! $schedule) {
            return null;
        }
        $daily = People::minutesOf($schedule->end_time) - People::minutesOf($schedule->start_time);
        if ($daily <= 0) {
            return null;
        }
        $range = People::monthRange($period);
        $tz = config('app.company_timezone');
        $from = $range['from']->setTimezone($tz)->startOfDay();
        $to = $range['to']->setTimezone($tz)->subDay()->startOfDay();
        $today = CarbonImmutable::now($tz)->startOfDay();
        if ($to->greaterThan($today)) {
            $to = $today;
        }
        $hired = $user->employee?->hired_at;
        $fired = $user->employee?->fired_at;
        if ($hired && $hired->format('Y-m-d') > $from->format('Y-m-d')) {
            $from = CarbonImmutable::parse($hired->format('Y-m-d'), $tz);
        }
        if ($fired && $fired->format('Y-m-d') < $to->format('Y-m-d')) {
            $to = CarbonImmutable::parse($fired->format('Y-m-d'), $tz);
        }
        $rows = Attendance::where('user_id', $user->id)
            ->whereDate('date', '>=', $from->format('Y-m-d'))->whereDate('date', '<=', $to->format('Y-m-d'))
            ->get()->keyBy(fn ($r) => $r->date->format('Y-m-d'));

        $plan = 0;
        $worked = 0;
        for ($d = $from; $d->lessThanOrEqualTo($to); $d = $d->addDay()) {
            if (! in_array($d->isoWeekday(), $schedule->work_days, true)) {
                continue;
            }
            $row = $rows[$d->format('Y-m-d')] ?? null;
            if ($row?->status === 'DAY_OFF') {
                continue;
            }
            $plan += $daily;
            if ($row && in_array($row->status, ['PRESENT', 'LATE'], true)) {
                $worked += min((int) $row->work_minutes, $daily);
            }
        }
        if ($plan === 0) {
            return null;
        }

        return (string) BigDecimal::of($worked)->multipliedBy(100)->dividedBy($plan, 2, RoundingMode::HalfUp);
    }

    /** Оклад в строке отличается от рассчитанного по посещаемости — значит, его исправили вручную. */
    private static function baseEditedManually(PayrollEntry $e): bool
    {
        $base = BigDecimal::of((string) $e->base_salary);
        if ($base->isZero()) {
            return false;
        }
        if ($e->base_full === null) {
            return true;
        }
        $auto = $e->attendance_pct === null ? BigDecimal::of((string) $e->base_full)
            : BigDecimal::of((string) $e->base_full)->multipliedBy((string) $e->attendance_pct)->dividedBy(100, 2, RoundingMode::HalfUp);

        return ! $base->isEqualTo($auto);
    }

    /** Менеджеры и РОП с окладом при 100 % посещаемости — для блока «Оклады». */
    public static function salaries(): Collection
    {
        return User::with(['employee', 'role'])->where('status', 'ACTIVE')
            ->whereHas('role', fn ($q) => $q->whereIn('code', self::FIXED_SALARY_ROLES))
            ->orderBy('full_name')->get();
    }

    /** @param  array<string,?string>  $salaries  user_id → оклад при 100 % (null — не задан) */
    public static function saveSalaries(array $salaries): void
    {
        $users = self::salaries()->keyBy('id');
        DB::transaction(function () use ($salaries, $users) {
            foreach ($salaries as $userId => $amount) {
                $user = $users[$userId] ?? abort(404);
                $old = $user->employee?->base_salary;
                $new = $amount === null || $amount === '' ? null : self::dec($amount);
                if ((string) $old === (string) $new || ($old !== null && $new !== null && BigDecimal::of((string) $old)->isEqualTo($new))) {
                    continue;
                }
                Employee::updateOrCreate(['user_id' => $userId], ['base_salary' => $new]);
                Audit::log('employee.salary_update', 'user', $userId, ['baseSalary' => ['old' => $old === null ? null : (string) $old, 'new' => $new]]);
            }
        });
    }

    /**
     * Расчёт месяца: черновики для всех активных сотрудников (кроме CEO). Комиссия — сумма утверждённых
     * и выплаченных комиссий месяца; оклад (только менеджер и РОП) — оклад при 100 % × посещаемость; сдельно — начисления
     * «Исполнитель» по проектам; KPI-бонус — «бонус при 100%» × KPI (до 120%); бонусы и штрафы вносятся
     * вручную и при пересчёте сохраняются. Утверждённые строки не пересчитываются.
     */
    public static function calculate(string $period): void
    {
        $users = User::with(['employee', 'role'])->where('status', 'ACTIVE')->whereHas('role', fn ($q) => $q->where('code', '<>', 'CEO'))->get();
        [$from, $to] = [People::monthRange($period)['from'], People::monthRange($period)['to']];
        $kpi = collect(KpiService::rows($period, $from, $to, ['code' => 'payroll.manage']))->mapWithKeys(fn ($r) => [$r['user']->id => $r['kpiPct']]);
        [$d1, $d2] = Period::monthDates($period);
        $piece = DB::table('expenses')->whereNull('deleted_at')->where('category', 'EXECUTOR')->whereNotNull('payee_user_id')
            ->where('expense_date', '>=', $d1)->where('expense_date', '<', $d2)->groupBy('payee_user_id')
            ->select('payee_user_id', DB::raw('SUM(amount_uzs) s'))->pluck('s', 'payee_user_id');

        DB::transaction(function () use ($users, $period, $kpi, $piece) {
            foreach ($users as $u) {
                $existing = PayrollEntry::where('user_id', $u->id)->where('period', $period)->lockForUpdate()->first();
                if ($existing && $existing->status !== 'DRAFT') {
                    continue;
                }
                // Оклад (менеджер, РОП) = оклад при 100 % × посещаемость месяца
                $fixed = self::hasFixedSalary($u->role->code);
                $full = $fixed && $u->employee?->base_salary !== null ? self::dec($u->employee->base_salary) : null;
                $attendancePct = $fixed ? self::attendancePct($u, $period) : null;
                $base = $full === null ? '0.00' : ($attendancePct === null ? $full
                    : (string) BigDecimal::of($full)->multipliedBy($attendancePct)->dividedBy(100, 2, RoundingMode::HalfUp));
                // Оклад, исправленный вручную в черновике, пересчёт не затирает
                if ($fixed && $existing && self::baseEditedManually($existing)) {
                    $base = self::dec($existing->base_salary);
                }
                $target = $u->employee?->kpi_bonus_target;
                $pct = $kpi[$u->id] ?? null;
                $parts = [
                    'base_salary' => $base,
                    'piece_rate' => self::dec($piece[$u->id] ?? 0),
                    'kpi_bonus' => $target !== null ? People::kpiBonusFor((string) $target, $pct) : self::dec($existing?->kpi_bonus),
                    'commission' => self::dec(self::commissionOf($u->id, $period)),
                    'other_bonus' => self::dec($existing?->other_bonus),
                    'penalty' => self::dec($existing?->penalty),
                ];
                $data = $parts + ['final_salary' => self::final($parts), 'kpi_pct' => $pct, 'base_full' => $full, 'attendance_pct' => $attendancePct];
                $existing ? $existing->update($data) : PayrollEntry::create(['user_id' => $u->id, 'period' => $period] + $data);
            }
            Audit::log('payroll.calculate', 'payroll', null, ['period' => ['old' => null, 'new' => $period]]);
        });
    }

    private static function final(array $p): string
    {
        return People::finalSalary([
            'baseSalary' => $p['base_salary'], 'pieceRate' => $p['piece_rate'], 'kpiBonus' => $p['kpi_bonus'],
            'commission' => $p['commission'], 'otherBonus' => $p['other_bonus'], 'penalty' => $p['penalty'],
        ]);
    }

    /** @param  array{base_salary?:string,kpi_bonus?:string,other_bonus?:string,penalty?:string,comment?:?string,kpi_bonus_target?:?string}  $input */
    public static function updatePayroll(string $id, array $input): PayrollEntry
    {
        $e = PayrollEntry::with('user.role')->findOrFail($id);
        if ($e->status !== 'DRAFT') {
            throw new BusinessRule(t('payroll.errors.immutable'));
        }
        if (isset($input['base_salary']) && ! BigDecimal::of($input['base_salary'])->isZero() && ! self::hasFixedSalary($e->user->role->code)) {
            throw new BusinessRule(t('payroll.errors.noFixed'), ['base_salary' => t('payroll.errors.noFixedField')]);
        }
        $hasTarget = array_key_exists('kpi_bonus_target', $input);
        $parts = [
            'base_salary' => self::dec($input['base_salary'] ?? $e->base_salary),
            'piece_rate' => self::dec($e->piece_rate),
            // Новый «бонус при 100%» сразу пересчитывает KPI-бонус месяца (если сумму не ввели вручную)
            'kpi_bonus' => isset($input['kpi_bonus']) ? self::dec($input['kpi_bonus'])
                : ($hasTarget && $input['kpi_bonus_target'] !== null ? People::kpiBonusFor($input['kpi_bonus_target'], $e->kpi_pct === null ? null : (string) $e->kpi_pct) : self::dec($e->kpi_bonus)),
            'commission' => self::dec($e->commission),
            'other_bonus' => self::dec($input['other_bonus'] ?? $e->other_bonus),
            'penalty' => self::dec($input['penalty'] ?? $e->penalty),
        ];

        return DB::transaction(function () use ($e, $input, $parts, $hasTarget, $id) {
            $old = ['base' => self::dec($e->base_salary), 'kpi' => self::dec($e->kpi_bonus), 'other' => self::dec($e->other_bonus), 'penalty' => self::dec($e->penalty)];
            if ($hasTarget) {
                Employee::updateOrCreate(['user_id' => $e->user_id], ['kpi_bonus_target' => $input['kpi_bonus_target']]);
            }
            $e->update([
                'base_salary' => $parts['base_salary'], 'kpi_bonus' => $parts['kpi_bonus'], 'other_bonus' => $parts['other_bonus'], 'penalty' => $parts['penalty'],
                'final_salary' => self::final($parts),
            ] + (array_key_exists('comment', $input) ? ['comment' => $input['comment']] : []));
            Audit::log('payroll.update', 'payroll', $id, [
                'baseSalary' => ['old' => $old['base'], 'new' => $parts['base_salary']],
                'kpiBonus' => ['old' => $old['kpi'], 'new' => $parts['kpi_bonus']],
                'otherBonus' => ['old' => $old['other'], 'new' => $parts['other_bonus']],
                'penalty' => ['old' => $old['penalty'], 'new' => $parts['penalty']],
            ]);

            return $e->fresh();
        });
    }

    /** Утверждение → строки неизменяемы; выплата — только утверждённых. */
    public static function transition(array $ids, string $to): int
    {
        $from = $to === 'APPROVED' ? 'DRAFT' : 'APPROVED';

        return DB::transaction(function () use ($ids, $to, $from) {
            $rows = PayrollEntry::whereIn('id', $ids)->lockForUpdate()->get();
            if ($rows->count() !== count(array_unique($ids))) {
                abort(404);
            }
            if ($rows->contains(fn ($r) => $r->status !== $from)) {
                throw new BusinessRule(t($to === 'APPROVED' ? 'payroll.errors.approveDraft' : 'payroll.errors.payApproved'));
            }
            $now = now();
            PayrollEntry::whereIn('id', $ids)->update($to === 'APPROVED'
                ? ['status' => $to, 'approved_by_id' => access()->id(), 'approved_at' => $now]
                : ['status' => $to, 'paid_at' => $now]);
            foreach ($rows as $r) {
                Audit::log($to === 'APPROVED' ? 'payroll.approve' : 'payroll.pay', 'payroll', $r->id, [
                    'status' => ['old' => $from, 'new' => $to], 'finalSalary' => ['old' => null, 'new' => (string) $r->final_salary],
                ]);
            }

            return $rows->count();
        });
    }
}
