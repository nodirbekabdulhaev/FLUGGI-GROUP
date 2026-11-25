<?php

namespace App\Services\Finance;

use App\Domain\Finance;
use App\Domain\Tasks;
use App\Models\PayrollEntry;
use App\Models\User;
use App\Services\People\PeopleService;
use App\Support\Audit;
use App\Support\Settings;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Накладные расходы (аренда, офис — категории с отметкой «накладные») и фонд фиксированных
 * окладов сотрудников делятся на проекты, бывшие в работе в этом месяце, или на заданный
 * в настройках делитель. Это управленческая аналитика проекта: в расходах компании и в
 * зарплате суммы остаются как есть.
 *
 * Фонд окладов месяца — сумма по включённым сотрудникам: оклад из расчёта зарплаты за месяц
 * (payroll_entries.base_salary), а если расчёта нет — оклад при 100 % из карточки сотрудника.
 * Сотрудники — список из «Настройки → Финансы»; пока список не сохранён — все активные
 * сотрудники с окладом больше нуля, кроме CEO и партнёра.
 */
final class OverheadService
{
    private const KEY = 'finance';

    /** @return array{overheadDivisor:?int} */
    public static function settings(): array
    {
        $d = Settings::get(self::KEY)['overheadDivisor'] ?? null;

        return ['overheadDivisor' => is_numeric($d) && (int) $d >= 1 && (int) $d <= 1000 ? (int) $d : null];
    }

    public static function saveSettings(?int $divisor): array
    {
        DB::transaction(function () use ($divisor) {
            $before = self::settings();
            Settings::save(self::KEY, ['overheadDivisor' => $divisor] + Settings::get(self::KEY));
            Audit::log('settings.finance', 'setting', null, ['overheadDivisor' => ['old' => $before['overheadDivisor'], 'new' => $divisor]]);
        });

        return self::settings();
    }

    /**
     * Сотрудники, которых можно включить в фонд окладов: активные, не CEO и не партнёр,
     * с окладом больше нуля (менеджер, РОП, SMM-специалист с планом роликов).
     */
    public static function salaryCandidates(): Collection
    {
        $partner = PartnerService::settings()['partnerId'];

        return User::with(['employee', 'role'])->where('status', 'ACTIVE')
            ->whereHas('role', fn ($q) => $q->where('code', '<>', 'CEO'))
            ->whereHas('employee', fn ($q) => $q->where('base_salary', '>', 0))
            ->when($partner, fn ($q) => $q->whereKeyNot($partner))
            ->orderBy('full_name')->get()
            ->filter(fn (User $u) => PeopleService::hasBaseSalary($u))->values();
    }

    /** Сохранённый список сотрудников фонда окладов или null (по умолчанию — все кандидаты). */
    public static function savedSalaryUserIds(): ?array
    {
        $ids = Settings::get(self::KEY)['salaryUserIds'] ?? null;

        return is_array($ids) ? array_values(array_filter($ids, 'is_string')) : null;
    }

    /** Сотрудники, чьи оклады делятся на проекты (партнёр не входит никогда). */
    public static function salaryUserIds(): array
    {
        $partner = PartnerService::settings()['partnerId'];
        $ids = self::savedSalaryUserIds() ?? self::salaryCandidates()->pluck('id')->all();

        return array_values(array_filter($ids, fn ($id) => $id !== $partner));
    }

    /** @param  string[]  $userIds  только из salaryCandidates() */
    public static function saveSalaryUsers(array $userIds): array
    {
        $allowed = self::salaryCandidates()->pluck('id')->all();
        $ids = array_values(array_unique($userIds));
        if (array_diff($ids, $allowed)) {
            abort(422);
        }
        sort($ids);
        DB::transaction(function () use ($ids) {
            $before = self::savedSalaryUserIds();
            Settings::save(self::KEY, ['salaryUserIds' => $ids] + Settings::get(self::KEY));
            Audit::log('settings.finance', 'setting', null, ['salaryUserIds' => ['old' => $before, 'new' => $ids]]);
        });

        return $ids;
    }

    /** Месяцы YYYY-MM от from до to включительно. */
    public static function monthsBetween(string $from, string $to): array
    {
        $out = [];
        [$y, $m] = array_map('intval', explode('-', $from));
        [$ty, $tm] = array_map('intval', explode('-', $to));
        while ($y < $ty || ($y === $ty && $m <= $tm)) {
            $out[] = sprintf('%04d-%02d', $y, $m);
            if (++$m > 12) {
                $m = 1;
                $y++;
            }
        }

        return $out;
    }

    /** Накладные по месяцам, UZS. */
    private static function overheadByMonth(array $months): array
    {
        if (! $months) {
            return [];
        }
        $first = $months[0].'-01';
        $last = CarbonImmutable::createFromFormat('!Y-m', end($months))->addMonthNoOverflow()->format('Y-m-d');
        $rows = DB::table('expenses as e')->join('finance_categories as c', 'c.code', '=', 'e.category')
            ->whereNull('e.deleted_at')->where('e.scope', 'COMPANY')->where('c.is_overhead', true)
            ->where('e.expense_date', '>=', $first)->where('e.expense_date', '<', $last)
            ->select(DB::raw("DATE_FORMAT(e.expense_date, '%Y-%m') AS m"), DB::raw('SUM(e.amount_uzs) AS s'))->groupBy('m')->get();

        return $rows->pluck('s', 'm')->map(fn ($v) => (string) $v)->all();
    }

    /**
     * Фонд фиксированных окладов по месяцам, UZS: оклад из расчёта зарплаты месяца,
     * без расчёта — оклад при 100 % (если сотрудник в этом месяце уже работал и ещё не уволен).
     */
    public static function salaryByMonth(array $months): array
    {
        $ids = self::salaryUserIds();
        if (! $months || ! $ids) {
            return [];
        }
        $entries = [];
        foreach (PayrollEntry::whereIn('period', $months)->whereIn('user_id', $ids)->get(['user_id', 'period', 'base_salary']) as $e) {
            $entries[$e->period][$e->user_id] = (string) $e->base_salary;
        }
        $users = User::with(['employee', 'role'])->whereIn('id', $ids)->get();
        $out = [];
        foreach ($months as $m) {
            [$from, $to] = Period::monthDates($m);
            $sum = BigDecimal::zero();
            foreach ($users as $u) {
                if (isset($entries[$m][$u->id])) {
                    $sum = $sum->plus($entries[$m][$u->id]);

                    continue;
                }
                $emp = $u->employee;
                if (! $emp || $emp->base_salary === null || ! PeopleService::hasBaseSalary($u)) {
                    continue;
                }
                $hired = $emp->hired_at?->format('Y-m-d');
                $fired = $emp->fired_at?->format('Y-m-d');
                if (($hired && $hired >= $to) || ($fired && $fired < $from) || (! $fired && $u->status !== 'ACTIVE')) {
                    continue;
                }
                $sum = $sum->plus((string) $emp->base_salary);
            }
            $out[$m] = (string) $sum->toScale(2);
        }

        return $out;
    }

    /** Число проектов в работе по месяцам. */
    private static function activeByMonth(array $months): array
    {
        if (! $months) {
            return [];
        }
        $range = fn (string $m) => [
            CarbonImmutable::createFromFormat('!Y-m', $m, Period::TZ)->utc(),
            CarbonImmutable::createFromFormat('!Y-m', $m, Period::TZ)->addMonthNoOverflow()->utc(),
        ];
        $firstFrom = $range($months[0])[0];
        $lastTo = $range(end($months))[1];
        $projects = DB::table('projects')->whereNull('deleted_at')->where('status', '<>', 'CANCELLED')->where('created_at', '<', $lastTo)
            ->where(fn ($q) => $q->whereNull('completed_at')->orWhere('completed_at', '>=', $firstFrom))->get(['created_at', 'completed_at']);
        $out = [];
        foreach ($months as $m) {
            [$from, $to] = $range($m);
            $out[$m] = $projects->filter(fn ($p) => CarbonImmutable::parse($p->created_at, 'UTC') < $to
                && (! $p->completed_at || CarbonImmutable::parse($p->completed_at, 'UTC') >= $from))->count();
        }

        return $out;
    }

    /**
     * Доля накладных для каждого проекта за все месяцы его работы (до сегодня).
     *
     * @param  iterable<object{id:string,created_at:mixed,completed_at:mixed,status:string}>  $projects
     * @return array<string,BigDecimal>
     */
    public static function forProjects(iterable $projects, ?CarbonImmutable $now = null): array
    {
        return self::sharesForProjects($projects, $now)['overhead'];
    }

    /**
     * Доли накладных и фонда окладов для каждого проекта за все месяцы его работы (до сегодня):
     * каждый месяц делится одинаково — на заданный делитель или на число проектов в работе.
     *
     * @param  iterable<object{id:string,created_at:mixed,completed_at:mixed,status:string}>  $projects
     * @return array{overhead: array<string,BigDecimal>, salary: array<string,BigDecimal>}
     */
    public static function sharesForProjects(iterable $projects, ?CarbonImmutable $now = null): array
    {
        $today = substr(Tasks::companyDate($now ?? CarbonImmutable::now()), 0, 7);
        $spans = [];
        foreach ($projects as $p) {
            $spans[$p->id] = $p->status === 'CANCELLED' ? [] : self::monthsBetween(
                substr(Tasks::companyDate(CarbonImmutable::parse($p->created_at, 'UTC')), 0, 7),
                $p->completed_at ? substr(Tasks::companyDate(CarbonImmutable::parse($p->completed_at, 'UTC')), 0, 7) : $today,
            );
        }
        if (! $spans) {
            return ['overhead' => [], 'salary' => []];
        }
        $all = array_values(array_unique(array_merge(...array_values($spans))));
        sort($all);
        $overhead = self::overheadByMonth($all);
        $salary = self::salaryByMonth($all);
        $active = self::activeByMonth($all);
        $divisor = self::settings()['overheadDivisor'];
        $out = ['overhead' => [], 'salary' => []];
        foreach ($spans as $id => $months) {
            $oh = BigDecimal::zero();
            $sal = BigDecimal::zero();
            foreach ($months as $m) {
                $oh = $oh->plus(Finance::overheadShare($overhead[$m] ?? '0', $active[$m] ?? 1, $divisor));
                $sal = $sal->plus(Finance::overheadShare($salary[$m] ?? '0', $active[$m] ?? 1, $divisor));
            }
            $out['overhead'][$id] = $oh;
            $out['salary'][$id] = $sal;
        }

        return $out;
    }

    /** Оценка накладных на один проект в месяц — по последнему полному месяцу (для экономики тарифа). */
    public static function monthlyShareEstimate(?CarbonImmutable $now = null): string
    {
        $m = CarbonImmutable::createFromFormat('!Y-m', substr(Tasks::companyDate($now ?? CarbonImmutable::now()), 0, 7))->subMonthNoOverflow()->format('Y-m');

        return Finance::overheadShare(self::overheadByMonth([$m])[$m] ?? '0', self::activeByMonth([$m])[$m] ?? 1, self::settings()['overheadDivisor']);
    }
}
