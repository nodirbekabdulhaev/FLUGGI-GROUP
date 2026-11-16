<?php

namespace App\Services\People;

use App\Auth\Access;
use App\Domain\People;
use App\Domain\Tasks;
use App\Exceptions\BusinessRule;
use App\Models\Attendance;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Automation\ReminderService;
use App\Services\Notifications\Notifier;
use App\Support\Audit;
use App\Support\Format;
use App\Support\Outbox;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Забытая отметка ухода (ТЗ §35):
 *  1) рабочий день по графику закончился + «напоминание через» минут, а ухода нет — одно уведомление
 *     «Вы не отметили уход» (reminder_log: один раз на запись);
 *  2) прошло «авто-уход через» минут после конца графика (выходной или нет графика — в 23:59 по Ташкенту) —
 *     уход ставится на конец графика (не на время закрытия), запись помечается auto_checkout = 1 и уходит
 *     на утверждение (approval_status = PENDING); уведомления сотруднику и всем CEO (в Telegram — кнопка «Утвердить»);
 *  3) CEO (attendance.manage на всю компанию) утверждает (день засчитан до конца графика, время ухода можно исправить)
 *     или отклоняет (день — прогул). До решения день в оклад не идёт (PeopleService::attendancePct).
 */
final class AttendanceCheckout
{
    public const JOB = 'attendance-checkout';

    /** Сколько прошлых дней проверять (если cron долго не работал). */
    private const LOOKBACK_DAYS = 7;

    /** Префикс callback_data inline-кнопки Telegram. */
    public const TELEGRAM_PREFIX = 'att';

    public static function settings(): array
    {
        $s = Settings::attendance();

        return [
            'checkoutControl' => (bool) $s['checkoutControl'],
            'reminderGraceMinutes' => (int) $s['reminderGraceMinutes'],
            'autoCheckoutDelayMinutes' => (int) $s['autoCheckoutDelayMinutes'],
        ];
    }

    /** @param  array{checkoutControl:bool,reminderGraceMinutes:int,autoCheckoutDelayMinutes:int}  $input */
    public static function saveSettings(array $input): array
    {
        $old = self::settings();
        $new = [
            'checkoutControl' => (bool) $input['checkoutControl'],
            'reminderGraceMinutes' => (int) $input['reminderGraceMinutes'],
            'autoCheckoutDelayMinutes' => (int) $input['autoCheckoutDelayMinutes'],
        ];

        return DB::transaction(function () use ($old, $new) {
            $saved = Settings::save('attendance', $new);
            $changes = [];
            foreach ($new as $k => $v) {
                if ($old[$k] !== $v) {
                    $changes[$k] = ['old' => $old[$k], 'new' => $v];
                }
            }
            if ($changes) {
                Audit::log('settings.attendance', 'settings', null, $changes);
            }

            return $saved;
        });
    }

    /** Может ли текущий пользователь утверждать дни без отметки ухода. */
    public static function canApprove(?Access $access = null): bool
    {
        $access ??= access(false);

        return (bool) $access?->can('attendance.manage', 'ALL');
    }

    /** График сотрудника: свой активный, иначе — активный график его роли. */
    private static function scheduleFor(User $u, Collection $roleSchedules): ?WorkSchedule
    {
        $own = $u->employee?->schedule;
        if ($own?->is_active) {
            return $own;
        }

        return $roleSchedules->firstWhere('role_code', $u->role?->code);
    }

    /**
     * Конец рабочего дня по графику и момент авто-ухода (UTC).
     *
     * @return array{end:?CarbonImmutable, remindAt:?CarbonImmutable, cutoff:CarbonImmutable}
     */
    public static function deadlines(string $date, ?WorkSchedule $schedule, array $settings): array
    {
        $end = $schedule ? People::atTashkent($date, $schedule->end_time) : null;
        $workday = $schedule && in_array(People::isoWeekday($date), array_map('intval', $schedule->work_days ?? []), true);

        return [
            'end' => $end,
            'remindAt' => $end?->addMinutes($settings['reminderGraceMinutes']),
            // Выходной по графику или графика нет — закрываем в конце дня
            'cutoff' => $end && $workday ? $end->addMinutes($settings['autoCheckoutDelayMinutes']) : People::atTashkent($date, '23:59'),
        ];
    }

    /** Задача планировщика: напоминания и авто-уход. Повторный запуск ничего не дублирует. */
    public static function run(?DateTimeInterface $now = null): array
    {
        $settings = self::settings();
        if (! $settings['checkoutControl']) {
            return ['skipped' => true];
        }
        $now = CarbonImmutable::instance($now ?? now())->utc();
        $today = Tasks::companyDate($now);
        $rows = Attendance::with(['user.role', 'user.employee.schedule'])
            ->whereNotNull('check_in')->whereNull('check_out')->where('auto_checkout', false)
            ->whereDate('date', '>=', Tasks::addDays($today, -self::LOOKBACK_DAYS))->whereDate('date', '<=', $today)
            ->orderBy('date')->limit(1000)->get();
        $roleSchedules = WorkSchedule::where('is_active', true)->whereNotNull('role_code')->get();
        $out = ['reminded' => 0, 'closed' => 0];
        foreach ($rows as $row) {
            if (! $row->user || ! PeopleService::tracksAttendance($row->user->role?->code ?? '')) {
                continue;
            }
            $date = $row->date->format('Y-m-d');
            $dl = self::deadlines($date, self::scheduleFor($row->user, $roleSchedules), $settings);
            if ($now->greaterThanOrEqualTo($dl['cutoff'])) {
                $out['closed'] += self::autoClose($row->id, $dl['end']) ? 1 : 0;
            } elseif ($dl['remindAt'] && $now->greaterThanOrEqualTo($dl['remindAt'])) {
                $end = People::tashkentTime($dl['end']);
                $out['reminded'] += ReminderService::sendOnce('attendance.no_checkout', $row->id, [$row->user_id], 'attendance.no_checkout',
                    fn () => [t('attendance.checkout.remindTitle'), t('attendance.checkout.remindBody', ['end' => $end])], '/attendance');
            }
        }

        return $out;
    }

    /** Закрыть день по концу графика (уход не раньше прихода) и отправить на утверждение. */
    private static function autoClose(string $id, ?CarbonImmutable $end): bool
    {
        return DB::transaction(function () use ($id, $end) {
            $row = Attendance::whereKey($id)->lockForUpdate()->first();
            if (! $row || ! $row->check_in || $row->check_out || $row->auto_checkout) {
                return false;
            }
            $checkIn = CarbonImmutable::instance($row->check_in);
            $out = $end && $end->greaterThan($checkIn) ? $end : $checkIn;
            $row->update([
                'check_out' => $out, 'work_minutes' => People::workMinutes($checkIn, $out),
                'auto_checkout' => true, 'approval_status' => 'PENDING', 'approved_by_id' => null, 'approved_at' => null, 'reject_reason' => null,
            ]);
            Audit::log('attendance.auto_checkout', 'attendance', $row->id, [
                'checkOut' => ['old' => null, 'new' => People::tashkentTime($out)],
                'approval' => ['old' => null, 'new' => 'PENDING'],
            ]);
            Outbox::publish('attendance.auto_checkout', [
                'attendanceId' => $row->id, 'userId' => $row->user_id, 'date' => $row->date->format('Y-m-d'), 'checkOut' => People::tashkentTime($out),
            ]);

            return true;
        });
    }

    // ─────────────── Утверждение ───────────────

    /** Дни без отметки ухода, ждущие решения (все сотрудники — утверждает CEO). */
    public static function pending(): Collection
    {
        return Attendance::with('user:id,full_name')->where('auto_checkout', true)->where('approval_status', 'PENDING')
            ->join('users', 'users.id', '=', 'attendance.user_id')
            ->orderBy('attendance.date')->orderBy('users.full_name')->select('attendance.*')->limit(500)->get();
    }

    public static function pendingCount(): int
    {
        return Attendance::where('auto_checkout', true)->where('approval_status', 'PENDING')->count();
    }

    /**
     * Неутверждённые дни за месяц: user_id → количество (предупреждение в зарплате).
     *
     * @return array<string,int>
     */
    public static function pendingByUser(string $period): array
    {
        $from = $period.'-01';
        $to = CarbonImmutable::parse($from)->addMonthNoOverflow()->format('Y-m-d');

        return Attendance::where('auto_checkout', true)->where('approval_status', 'PENDING')
            ->where('date', '>=', $from)->where('date', '<', $to)
            ->groupBy('user_id')->selectRaw('user_id, COUNT(*) AS n')->pluck('n', 'user_id')->map(fn ($n) => (int) $n)->all();
    }

    private static function lockPending(string $id): Attendance
    {
        access()->authorize('attendance.manage', 'ALL');
        $row = Attendance::whereKey($id)->lockForUpdate()->first() ?? abort(404);
        if (! $row->auto_checkout) {
            abort(404);
        }
        if ($row->approval_status !== 'PENDING') {
            throw new BusinessRule(t('attendance.checkout.errors.decided'));
        }

        return $row;
    }

    /**
     * Утвердить: сотрудник действительно работал — день засчитан до конца графика
     * (или до исправленного времени ухода $checkOut «ЧЧ:ММ» по Ташкенту).
     */
    public static function approve(string $id, ?string $checkOut = null): Attendance
    {
        return DB::transaction(function () use ($id, $checkOut) {
            $row = self::lockPending($id);
            $date = $row->date->format('Y-m-d');
            $old = People::tashkentTime($row->check_out);
            $out = $checkOut ? People::atTashkent($date, $checkOut) : CarbonImmutable::instance($row->check_out);
            if ($checkOut && $out->lessThanOrEqualTo(CarbonImmutable::instance($row->check_in))) {
                throw new BusinessRule(t('attendance.errors.outBeforeIn'), ['check_out' => t('attendance.errors.outBeforeIn')]);
            }
            $row->update([
                'check_out' => $out, 'work_minutes' => People::workMinutes($row->check_in, $out),
                'approval_status' => 'APPROVED', 'approved_by_id' => access()->id(), 'approved_at' => now(),
            ]);
            Audit::log('attendance.checkout_approve', 'attendance', $row->id, array_filter([
                'approval' => ['old' => 'PENDING', 'new' => 'APPROVED'],
                'checkOut' => $old !== People::tashkentTime($out) ? ['old' => $old, 'new' => People::tashkentTime($out)] : null,
            ]));
            Outbox::publish('attendance.checkout_decided', [
                'attendanceId' => $row->id, 'userId' => $row->user_id, 'status' => 'APPROVED', 'reason' => null,
            ], access()->id());

            return $row;
        });
    }

    /** Отклонить: сотрудник не работал — день считается прогулом. */
    public static function reject(string $id, ?string $reason = null): Attendance
    {
        return DB::transaction(function () use ($id, $reason) {
            $row = self::lockPending($id);
            $oldStatus = $row->status;
            $row->update([
                'status' => 'ABSENT', 'work_minutes' => 0, 'late_minutes' => 0,
                'approval_status' => 'REJECTED', 'approved_by_id' => access()->id(), 'approved_at' => now(), 'reject_reason' => $reason ?: null,
            ]);
            Audit::log('attendance.checkout_reject', 'attendance', $row->id, array_filter([
                'approval' => ['old' => 'PENDING', 'new' => 'REJECTED'],
                'status' => ['old' => $oldStatus, 'new' => 'ABSENT'],
                'reason' => $reason ? ['old' => null, 'new' => $reason] : null,
            ]));
            Outbox::publish('attendance.checkout_decided', [
                'attendanceId' => $row->id, 'userId' => $row->user_id, 'status' => 'REJECTED', 'reason' => $reason ?: null,
            ], access()->id());

            return $row;
        });
    }

    // ─────────────── Уведомления (подписчики outbox) ───────────────

    public static function register(): void
    {
        Outbox::on('attendance.auto_checkout', [self::class, 'onAutoCheckout']);
        Outbox::on('attendance.checkout_decided', [self::class, 'onDecided']);
    }

    /** Сотруднику — что день закрыт автоматически; каждому CEO — на утверждение (в Telegram — кнопка «Утвердить»). */
    public static function onAutoCheckout(array $e, array $meta): void
    {
        $row = Attendance::with('user:id,full_name')->find($e['attendanceId'] ?? null);
        if (! $row || $row->approval_status !== 'PENDING') {
            return;
        }
        $params = fn () => ['name' => (string) $row->user?->full_name, 'date' => Format::date($row->date), 'end' => People::tashkentTime($row->check_out)];
        Notifier::notify([$row->user_id], 'attendance.auto_checkout',
            fn () => [t('attendance.checkout.autoOwnTitle', $params()), t('attendance.checkout.autoOwnBody')], '/attendance?tab=records');
        Notifier::notify(Notifier::ceoIds(), 'attendance.auto_checkout',
            fn () => [t('attendance.checkout.autoTitle', $params()), t('attendance.checkout.autoBody')], '/attendance?tab=approval',
            buttons: [[['text' => 'attendance.checkout.approve', 'data' => self::TELEGRAM_PREFIX.':a:'.$row->id]]]);
    }

    public static function onDecided(array $e, array $meta): void
    {
        $row = Attendance::find($e['attendanceId'] ?? null);
        if (! $row) {
            return;
        }
        $approved = ($e['status'] ?? null) === 'APPROVED';
        $params = ['date' => Format::date($row->date), 'end' => $row->check_out ? People::tashkentTime($row->check_out) : '—'];
        Notifier::notify([$row->user_id], 'attendance.checkout_decided', fn () => [
            t($approved ? 'attendance.checkout.approvedTitle' : 'attendance.checkout.rejectedTitle', $params),
            $approved ? null : ($e['reason'] ?? null),
        ], '/attendance?tab=records', $meta['actorId'] ?? null);
    }

    /**
     * Кнопка «Утвердить» в Telegram (callback_data «att:a:<id>»). Права проверяются как в CRM:
     * чат привязан к активному сотруднику с attendance.manage на всю компанию.
     */
    public static function telegramCallback(string $arg): string
    {
        [$action, $id] = array_pad(explode(':', $arg, 2), 2, '');
        if ($action !== 'a' || ! preg_match('/^[0-9a-f-]{36}$/i', $id)) {
            return t('telegram.botReplies.callbackUnknown');
        }
        $row = self::approve($id)->load('user:id,full_name');

        return t('attendance.checkout.tgApproved', ['name' => (string) $row->user?->full_name, 'date' => Format::date($row->date)]);
    }
}
