<?php

namespace App\Services\People;

use App\Domain\People;
use App\Domain\Tasks;
use App\Models\User;
use App\Services\Finance\Period;
use App\Services\Smm\SmmAccess;
use App\Services\Smm\SmmIdeaService;
use App\Services\Smm\SmmPayroll;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Оценка эффективности сотрудника за месяц: 0–100 и буква A/B/C/D. Считается только по настоящим данным CRM.
 *
 * Оценка = Σ (вес × балл составляющей) / Σ весов — только по составляющим, для которых в месяце есть данные
 * (нет данных — составляющая исключается, веса остальных пересчитываются пропорционально). Нет ни одной —
 * «недостаточно данных» (score = null). Балл составляющей — 0–100:
 *
 *  - kpi         — выполнение целей KPI месяца (KpiService, среднее по целям), не больше 100;
 *  - conversion  — конверсия лидов месяца в сделки / цель 20 % (у РОП — по отделу), не больше 100;
 *  - leads       — доля лидов месяца, выведенных из этапа «Новый» (обработано), %;
 *  - meetings    — проведено встреч / запланировано на прошедшие дни месяца (проведённые + «не пришёл» + не закрытые), %;
 *  - attendance  — посещаемость по графику (PeopleService::attendancePct), %;
 *  - todos       — дела со сроком в месяце, выполненные в срок, %;
 *  - tasks       — задачи проектов со сроком в месяце, сданные в срок, % (у проект-менеджера — задачи его направлений);
 *  - rework      — 100 − доля возвратов с проверки к сданным задачам месяца (не ниже 0);
 *  - videos      — опубликовано роликов / план роликов месяца (SMM), не больше 100;
 *  - ideas       — ролики месяца, опубликованные не позже плановой даты, %;
 *  - projects    — проекты направлений со сроком в месяце, завершённые в срок, %.
 *
 * Веса по ролям — WEIGHTS. Буква: A ≥ 85, B ≥ 70, C ≥ 50, иначе D. Скорость первого ответа лиду в CRM
 * не хранится, поэтому вместо неё — обработка лидов (leads).
 */
final class EmployeeScore
{
    public const WEIGHTS = [
        'MANAGER' => ['kpi' => 30, 'conversion' => 15, 'leads' => 10, 'meetings' => 15, 'attendance' => 20, 'todos' => 10],
        'ROP' => ['kpi' => 40, 'conversion' => 20, 'meetings' => 10, 'attendance' => 20, 'todos' => 10],
        'EXECUTOR' => ['tasks' => 40, 'rework' => 20, 'kpi' => 20, 'videos' => 30, 'ideas' => 15, 'todos' => 10],
        'PROJECT_MANAGER' => ['projects' => 50, 'tasks' => 30, 'todos' => 20],
        'CEO' => ['todos' => 100],
        'HR_ADMIN' => ['todos' => 100],
        'ACCOUNTANT' => ['todos' => 100],
    ];

    /** Цель конверсии лидов в сделки, %. */
    public const CONVERSION_TARGET = 20;

    public const GRADES = ['A' => 85, 'B' => 70, 'C' => 50, 'D' => 0];

    // ─────────────────────────── Формула (без базы) ───────────────────────────

    /** Балл 0–100 за выполнение: min(факт / цель, 1) × 100; цели нет — null. */
    public static function ratio(float|int $fact, float|int $target): ?float
    {
        if ($target <= 0) {
            return null;
        }

        return round(max(0, min($fact / $target, 1)) * 100, 2);
    }

    /** Доля «хороших» из всех, %; всего 0 — null (нет данных). */
    public static function share(int $good, int $total): ?float
    {
        return $total > 0 ? round(max(0, min($good, $total)) / $total * 100, 2) : null;
    }

    /** Возвраты на доработку: 100 − возвраты / сданные × 100 (не ниже 0); нет ни сданных, ни возвратов — null. */
    public static function reworkScore(int $reworks, int $done): ?float
    {
        if ($done <= 0) {
            return $reworks > 0 ? 0.0 : null;
        }

        return round(max(0, 100 - $reworks / $done * 100), 2);
    }

    public static function grade(?int $score): ?string
    {
        if ($score === null) {
            return null;
        }
        foreach (self::GRADES as $letter => $min) {
            if ($score >= $min) {
                return $letter;
            }
        }

        return 'D';
    }

    /**
     * Итог по составляющим: веса — только у составляющих с баллом, доля веса пересчитана до 100 %.
     *
     * @param  list<array{key:string,weight:int|float,score:?float}>  $components
     * @return array{score:?int,grade:?string,components:list<array>}
     */
    public static function combine(array $components): array
    {
        $total = 0.0;
        foreach ($components as $c) {
            if ($c['score'] !== null && $c['weight'] > 0) {
                $total += $c['weight'];
            }
        }
        $sum = 0.0;
        foreach ($components as &$c) {
            $active = $c['score'] !== null && $c['weight'] > 0 && $total > 0;
            $c['share'] = $active ? round($c['weight'] / $total * 100, 1) : null;
            if ($active) {
                $sum += $c['weight'] * $c['score'];
            }
        }
        unset($c);
        $score = $total > 0 ? (int) round($sum / $total) : null;

        return ['score' => $score, 'grade' => self::grade($score), 'components' => $components];
    }

    // ─────────────────────────── Данные месяца ───────────────────────────

    /**
     * Оценка за месяц с разбором составляющих.
     *
     * @return array{score:?int,grade:?string,period:string,components:list<array{key:string,weight:int,score:?float,share:?float,value:string,target:string}>}
     */
    public static function forUser(User $user, string $period): array
    {
        $user->loadMissing(['role', 'team', 'employee', 'headedTeams', 'directions']);
        $role = $user->role?->code ?? '';
        $weights = self::WEIGHTS[$role] ?? ['todos' => 100];
        ['from' => $from, 'to' => $to] = People::monthRange($period);
        $now = CarbonImmutable::now();
        $until = $to < $now ? $to : $now;
        $components = [];
        $add = function (string $key, ?float $score, string $value, string $target) use (&$components, $weights) {
            if (isset($weights[$key])) {
                $components[] = ['key' => $key, 'weight' => $weights[$key], 'score' => $score, 'value' => $value, 'target' => $target];
            }
        };
        $of = fn (int $a, int $b) => t('employees.score.of', ['done' => $a, 'total' => $b]);
        $pctText = fn (?float $v) => $v === null ? '—' : rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.').'%';
        $future = $from > $now;

        // KPI: цели месяца (KpiService)
        if (in_array($role, KpiService::GROUPS, true) && isset($weights['kpi'])) {
            $rows = $future ? [] : KpiService::build(collect([$user]), $period, $from, $to);
            $kpi = $rows[0]['kpiPct'] ?? null;
            $add('kpi', $kpi === null ? null : (float) min((float) $kpi, 100), $pctText($kpi === null ? null : (float) $kpi), '100%');
            $mgr = $rows[0]['manager'] ?? null;
            $rop = $rows[0]['rop'] ?? null;
            if ($role === 'MANAGER') {
                $n = (int) ($mgr['leads'] ?? 0);
                $conv = $mgr['conversionPct'] ?? null;
                $add('conversion', $conv === null ? null : self::ratio((float) $conv, self::CONVERSION_TARGET), $pctText($conv === null ? null : (float) $conv).($n ? ' · '.t('employees.score.leadsCount', ['count' => $n]) : ''), self::CONVERSION_TARGET.'%');
                $add('leads', self::share((int) ($mgr['processedLeads'] ?? 0), $n), $of((int) ($mgr['processedLeads'] ?? 0), $n), '100%');
            } elseif ($role === 'ROP') {
                $conv = $rop['conversionPct'] ?? null;
                $add('conversion', $conv === null ? null : self::ratio((float) $conv, self::CONVERSION_TARGET), $pctText($conv === null ? null : (float) $conv), self::CONVERSION_TARGET.'%');
            }
        }

        if (isset($weights['meetings'])) {
            $m = DB::table('meetings')->where('manager_id', $user->id)->where('starts_at', '>=', $from)->where('starts_at', '<', $until)
                ->whereIn('status', ['DONE', 'NO_SHOW', 'SCHEDULED', 'CONFIRMED'])->groupBy('status')->selectRaw('status, COUNT(*) n')->pluck('n', 'status');
            $held = (int) ($m['DONE'] ?? 0);
            $planned = (int) $m->sum();
            $add('meetings', self::share($held, $planned), $of($held, $planned), '100%');
        }

        if (isset($weights['attendance']) && PeopleService::tracksAttendance($role) && ! $future) {
            $pct = PeopleService::attendancePct($user, $period);
            $add('attendance', $pct === null ? null : (float) min((float) $pct, 100), $pctText($pct === null ? null : (float) $pct), '100%');
        }

        if (isset($weights['tasks'])) {
            $q = DB::table('tasks as t')->whereNull('t.deleted_at')->where('t.status', '<>', 'CANCELLED')
                ->where('t.deadline', '>=', $from)->where('t.deadline', '<', $until);
            if ($role === 'PROJECT_MANAGER') {
                $dirs = $user->directions->pluck('direction_id')->all();
                $q->join('projects as p', 'p.id', '=', 't.project_id')->whereNull('p.deleted_at')->whereIn('p.direction_id', $dirs ?: ['-']);
            } else {
                $q->where('t.assignee_id', $user->id);
            }
            $rows = $q->get(['t.status', 't.deadline', 't.completed_at']);
            $onTime = $rows->filter(fn ($t) => $t->status === 'DONE' && $t->completed_at && $t->completed_at <= $t->deadline)->count();
            $add('tasks', self::share($onTime, $rows->count()), $of($onTime, $rows->count()), '100%');
        }

        if (isset($weights['rework']) && $role === 'EXECUTOR') {
            $done = DB::table('tasks')->where('assignee_id', $user->id)->whereNull('deleted_at')->where('status', 'DONE')
                ->where('completed_at', '>=', $from)->where('completed_at', '<', $to)->count();
            $reworks = DB::table('task_status_history as h')->join('tasks as t', 't.id', '=', 'h.task_id')->where('h.from_status', 'REVIEW')
                ->whereIn('h.to_status', ['IN_PROGRESS', 'TODO'])->where('h.created_at', '>=', $from)->where('h.created_at', '<', $to)
                ->where('t.assignee_id', $user->id)->count();
            $add('rework', self::reworkScore($reworks, $done), t('employees.score.reworks', ['reworks' => $reworks, 'done' => $done]), '0');
        }

        if ($role === 'EXECUTOR' && SmmAccess::isSmmExecutor($user)) {
            if (SmmPayroll::hasVideoPlan($user)) {
                $published = SmmIdeaService::publishedCount($user->id, $period);
                $target = (int) $user->employee->video_target;
                $add('videos', self::ratio($published, $target), $of($published, $target), (string) $target);
            }
            $ideas = DB::table('smm_ideas')->whereNull('deleted_at')->where('assignee_id', $user->id)->where('status', 'PUBLISHED')
                ->where('published_at', '>=', $from)->where('published_at', '<', $to)->whereNotNull('planned_publish_date')
                ->get(['published_at', 'planned_publish_date']);
            $tz = config('app.company_timezone');
            $onTime = $ideas->filter(fn ($i) => CarbonImmutable::parse($i->published_at, 'UTC')->setTimezone($tz)->format('Y-m-d') <= substr((string) $i->planned_publish_date, 0, 10))->count();
            $add('ideas', self::share($onTime, $ideas->count()), $of($onTime, $ideas->count()), '100%');
        }

        if (isset($weights['projects'])) {
            [$d1, $d2] = Period::monthDates($period);
            $today = Tasks::companyDate($now);
            $dirs = $user->directions->pluck('direction_id')->all();
            $projects = DB::table('projects')->whereNull('deleted_at')->whereIn('direction_id', $dirs ?: ['-'])->where('status', '<>', 'CANCELLED')
                ->where('deadline', '>=', $d1)->where('deadline', '<', min($d2, $today))->get(['status', 'deadline', 'completed_at']);
            $tz = config('app.company_timezone');
            $onTime = $projects->filter(fn ($p) => $p->status === 'COMPLETED' && $p->completed_at
                && CarbonImmutable::parse($p->completed_at, 'UTC')->setTimezone($tz)->format('Y-m-d') <= substr((string) $p->deadline, 0, 10))->count();
            $add('projects', self::share($onTime, $projects->count()), $of($onTime, $projects->count()), '100%');
        }

        if (isset($weights['todos'])) {
            $todos = DB::table('todos')->where('owner_id', $user->id)->whereNull('deleted_at')->where('status', '<>', 'CANCELLED')
                ->where('due_at', '>=', $from)->where('due_at', '<', $until)->get(['status', 'due_at', 'completed_at']);
            $onTime = $todos->filter(fn ($t) => $t->status === 'DONE' && $t->completed_at && $t->completed_at <= $t->due_at)->count();
            $add('todos', self::share($onTime, $todos->count()), $of($onTime, $todos->count()), '100%');
        }

        return ['period' => $period] + self::combine($components);
    }

    /**
     * Оценки за последние $months месяцев по $period включительно: [[period, score], ...] (старые — первыми).
     *
     * @return list<array{period:string,score:?int}>
     */
    public static function trend(User $user, string $period, int $months = 6): array
    {
        $end = CarbonImmutable::createFromFormat('!Y-m', $period);
        $out = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $p = $end->subMonthsNoOverflow($i)->format('Y-m');
            $out[] = ['period' => $p, 'score' => self::forUser($user, $p)['score']];
        }

        return $out;
    }

    /**
     * Оценки для списка сотрудников (кэш 10 минут): user_id → [score, grade].
     *
     * @return array<string, array{score:?int,grade:?string}>
     */
    public static function forList(Collection $users, string $period): array
    {
        $out = [];
        foreach ($users as $u) {
            $out[$u->id] = Cache::remember("employee-score:{$u->id}:{$period}", 600, function () use ($u, $period) {
                $r = self::forUser($u, $period);

                return ['score' => $r['score'], 'grade' => $r['grade']];
            });
        }

        return $out;
    }

    /** Стиль бейджа оценки. */
    public static function color(?string $grade): string
    {
        return ['A' => 'green', 'B' => 'blue', 'C' => 'yellow', 'D' => 'red'][$grade] ?? 'gray';
    }
}
