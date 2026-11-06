<?php

namespace App\Services\Automation;

use App\Domain\People;
use App\Domain\Tasks;
use App\Models\Attendance;
use App\Models\JobRun;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Analytics\ClientInsights;
use App\Services\Notifications\Notifier;
use App\Services\People\AttendanceCheckout;
use App\Services\Todos\RecurringTodoService;
use App\Support\Lang;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Планировщик (ТЗ §55). Cron раз в минуту вызывает tick(); запуск каждой задачи фиксируется
 * в job_runs с уникальным слотом (job, slot), поэтому даже при двух параллельных cron задача
 * выполняется один раз. Время — по Ташкенту; пропущенный запуск догоняется в тот же день.
 */
final class Scheduler
{
    /**
     * Предварительный расчёт зарплаты делает модуль «Зарплата»: он регистрирует
     * app()->instance('fluggi.payroll.calculate', fn (string $period): int => …) — число начислений.
     */
    public const PAYROLL_BINDING = 'fluggi.payroll.calculate';

    /** @return list<array{name:string,due:callable}> */
    public static function jobs(): array
    {
        return [
            ['name' => 'overdue-tasks', 'due' => self::every(60)],
            ['name' => 'smart-reminders', 'due' => self::every(15)],
            ['name' => 'follow-ups', 'due' => self::dailyAt('09:00')],
            ['name' => 'daily-report', 'due' => self::dailyAt('18:00')],
            ['name' => 'absences', 'due' => self::dailyAt('20:00')],
            ['name' => AttendanceCheckout::JOB, 'due' => self::every(5)],
            ['name' => 'weekly-report', 'due' => self::weeklyAt(1, '09:00')],
            ['name' => 'recurring-todos', 'due' => self::dailyAt('07:00')],
            ['name' => 'client-health', 'due' => self::dailyAt('06:00')],
            ['name' => 'payroll', 'due' => self::monthlyAt('09:00')],
        ];
    }

    public static function names(): array
    {
        return array_column(self::jobs(), 'name');
    }

    /** Каждые N минут: слот — дата + номер интервала. */
    private static function every(int $minutes): callable
    {
        return fn (DateTimeInterface $now) => Tasks::companyDate($now).'#'.intdiv(People::minutesOf(People::tashkentTime($now)), $minutes);
    }

    /** Ежедневно после HH:MM (Ташкент). */
    private static function dailyAt(string $hhmm): callable
    {
        return fn (DateTimeInterface $now) => strcmp(People::tashkentTime($now), $hhmm) >= 0 ? Tasks::companyDate($now) : null;
    }

    private static function weeklyAt(int $weekday, string $hhmm): callable
    {
        return fn (DateTimeInterface $now) => People::isoWeekday(Tasks::companyDate($now)) === $weekday && strcmp(People::tashkentTime($now), $hhmm) >= 0
            ? Tasks::companyDate($now) : null;
    }

    private static function monthlyAt(string $hhmm): callable
    {
        return fn (DateTimeInterface $now) => str_ends_with(Tasks::companyDate($now), '-01') && strcmp(People::tashkentTime($now), $hhmm) >= 0
            ? substr(Tasks::companyDate($now), 0, 7) : null;
    }

    /** Все задачи, чей слот наступил. */
    public static function tick(?DateTimeInterface $now = null): void
    {
        $now ??= now();
        foreach (self::jobs() as $job) {
            $slot = ($job['due'])($now);
            if ($slot !== null) {
                self::execute($job['name'], $slot, $now);
            }
        }
    }

    /** Выполнить задачу в слоте один раз. false — слот уже был выполнен. */
    public static function execute(string $name, string $slot, ?DateTimeInterface $now = null): bool
    {
        $now ??= now();
        $id = (string) Str::uuid();
        $inserted = DB::table('job_runs')->insertOrIgnore([
            'id' => $id, 'job' => $name, 'slot' => $slot, 'started_at' => now()->format('Y-m-d H:i:s.v'),
        ]);
        if ($inserted !== 1) {
            return false;
        }
        try {
            $result = self::run($name, $now);
            JobRun::whereKey($id)->update(['finished_at' => now(), 'result' => $result === null ? null : json_encode($result)]);
        } catch (Throwable $e) {
            Log::error('Job '.$name.' failed: '.$e->getMessage());
            JobRun::whereKey($id)->update(['finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 1000)]);
        }

        return true;
    }

    /** Ручной запуск из настроек (отдельный слот, чтобы не мешать расписанию). */
    public static function runNow(string $name, ?DateTimeInterface $now = null): ?JobRun
    {
        if (! in_array($name, self::names(), true)) {
            return null;
        }
        $now ??= now();
        self::execute($name, 'manual:'.CarbonImmutable::instance($now)->toIso8601ZuluString('microsecond').':'.Str::random(4), $now);

        return JobRun::where('job', $name)->orderByDesc('started_at')->first();
    }

    private static function run(string $name, DateTimeInterface $now): mixed
    {
        return match ($name) {
            'overdue-tasks' => OverdueScanner::scan($now),
            'smart-reminders' => ReminderService::run($now),
            'follow-ups' => FollowUpService::notifyDue($now),
            'daily-report' => self::dailyReport($now),
            'absences' => self::absences($now),
            AttendanceCheckout::JOB => AttendanceCheckout::run($now),
            'weekly-report' => self::weeklyReport($now),
            'recurring-todos' => RecurringTodoService::generate($now),
            'client-health' => self::clientHealth($now),
            'payroll' => self::payroll($now),
        };
    }

    private static function users(string $role)
    {
        return User::with('headedTeams:id,head_id')->where('status', 'ACTIVE')->whereHas('role', fn ($q) => $q->where('code', $role))->get();
    }

    /** Отчёт на языке каждого получателя. */
    private static function sendReport(iterable $users, string $type, string $titleKey, callable $text, string $link): void
    {
        foreach ($users as $u) {
            $locale = in_array($u->locale, Lang::LOCALES, true) ? $u->locale : Lang::FALLBACK;
            $body = strip_tags($text($u, $locale));
            Notifier::notify([$u->id], $type, fn () => [t($titleKey), $body], $link);
        }
    }

    private static function dailyReport(DateTimeInterface $now): array
    {
        if (! Settings::automation()['dailyReport']) {
            return ['skipped' => true];
        }
        $ceos = self::users('CEO');
        self::sendReport($ceos, 'report.daily', 'automation.report.dailyNotice', fn ($u, $l) => ReportService::daily(null, $now, $l), '/dashboard');
        $rops = self::users('ROP');
        self::sendReport($rops->filter(fn ($r) => $r->headedTeams->isNotEmpty()), 'report.daily', 'automation.report.dailyTeamNotice',
            fn ($u, $l) => ReportService::daily($u->headedTeams->pluck('id')->all(), $now, $l), '/dashboard');

        return ['ceo' => $ceos->count(), 'rop' => $rops->count()];
    }

    private static function weeklyReport(DateTimeInterface $now): array
    {
        if (! Settings::automation()['weeklyReport']) {
            return ['skipped' => true];
        }
        $ceos = self::users('CEO');
        self::sendReport($ceos, 'report.weekly', 'automation.report.weeklyNotice', fn ($u, $l) => ReportService::weekly(null, $now, $l), '/finance/revenue');

        return ['ceo' => $ceos->count()];
    }

    /** Сотрудники с рабочим днём по графику и без отметки — «Отсутствовал» (ТЗ §35). Отмечаются только менеджеры и РОП. */
    private static function absences(DateTimeInterface $now): array
    {
        $date = Tasks::companyDate($now);
        $weekday = People::isoWeekday($date);
        $users = User::with(['role', 'employee.schedule'])->where('status', 'ACTIVE')
            ->whereHas('role', fn ($q) => $q->whereIn('code', ['MANAGER', 'ROP']))->get();
        $schedules = WorkSchedule::where('is_active', true)->whereNotNull('role_code')->get();
        $marked = Attendance::whereDate('date', $date)->pluck('user_id')->flip();
        $created = 0;
        foreach ($users as $u) {
            if ($marked->has($u->id)) {
                continue;
            }
            $own = $u->employee?->schedule?->is_active ? $u->employee->schedule : null;
            $schedule = $own ?? $schedules->firstWhere('role_code', $u->role->code);
            if (! $schedule || ! in_array($weekday, array_map('intval', $schedule->work_days ?? []), true)) {
                continue;
            }
            Attendance::create(['user_id' => $u->id, 'date' => $date, 'status' => 'ABSENT', 'comment' => Lang::get('automation.absentComment', [], 'ru')]);
            $created++;
        }

        return ['absent' => $created];
    }

    /** Пересчёт «здоровья» клиентов (ТЗ §43); перешедшие в «Риск» — уведомление менеджеру. */
    private static function clientHealth(DateTimeInterface $now): array
    {
        $r = ClientInsights::refreshAll($now);
        foreach ($r['toRisk'] as $c) {
            Notifier::notify([$c['ownerId']], 'client.risk', fn () => [t('analytics.notify.risk'), $c['name'].': '.implode(', ', ClientInsights::reasonTexts($c['reasons']))], '/clients/'.$c['id']);
        }

        return ['changed' => $r['changed'], 'risk' => count($r['toRisk'])];
    }

    /** 1-го числа: черновик зарплаты за прошлый месяц и уведомление CEO (ТЗ §55). */
    private static function payroll(DateTimeInterface $now): array
    {
        $prev = substr(Tasks::addDays(substr(Tasks::companyDate($now), 0, 7).'-01', -1), 0, 7);
        $ceo = self::users('CEO')->first();
        if (! $ceo) {
            return ['skipped' => 'no CEO'];
        }
        if (! app()->bound(self::PAYROLL_BINDING)) {
            throw new RuntimeException('Payroll calculator is not registered ('.self::PAYROLL_BINDING.')');
        }
        $entries = (int) (app(self::PAYROLL_BINDING))($prev);
        Notifier::notify([$ceo->id], 'payroll.calculated', fn () => [t('automation.payroll.title', ['period' => $prev]), t('automation.payroll.body', ['count' => $entries])], '/finance/payroll');

        return ['period' => $prev, 'entries' => $entries];
    }
}
