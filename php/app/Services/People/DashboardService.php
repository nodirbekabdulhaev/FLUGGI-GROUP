<?php

namespace App\Services\People;

use App\Domain\People;
use App\Domain\Tasks;
use App\Services\Finance\FinanceService;
use App\Services\Finance\Period;
use App\Services\Projects\ProjectAccess;
use App\Services\Smm\SmmVideoPay;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Дашборды по ролям (ТЗ §5, §58–60): CEO — компания, РОП — отдел, менеджер — свои показатели,
 * исполнитель — свои задачи, проект-менеджер — проекты своих направлений; у всех, кроме CEO, — «Мой KPI».
 */
final class DashboardService
{
    public static function get(array $period): array
    {
        $access = access();
        $from = $period['from'];
        $to = $period['to'];
        $month = Period::monthOf($from);
        $out = ['period' => $period];
        $inRange = fn ($q, string $col) => $q->where($col, '>=', $from)->where($col, '<', $to);

        if ($access->can('dashboard.ceo', 'ALL') && $access->can('finance.read')) {
            $fin = FinanceService::summary($period);
            $out['ceo'] = [
                'revenueUzs' => $fin['revenueUzs'],
                'profitUzs' => $fin['grossProfitUzs'],
                'paidUzs' => $fin['collectedUzs'],
                'expectedUzs' => $fin['receivablesUzs'],
                'newLeads' => $inRange(DB::table('leads')->whereNull('deleted_at'), 'created_at')->count(),
                'newDeals' => $inRange(DB::table('deals')->whereNull('deleted_at'), 'created_at')->count(),
                'contracts' => $inRange(DB::table('contracts')->where('status', 'SIGNED'), 'signed_at')->count(),
                'projectsInProgress' => DB::table('projects')->whereNull('deleted_at')->whereIn('status', ProjectAccess::ACTIVE)->count(),
            ];
        }

        if ($access->roleCode === 'ROP' && $access->headedTeamIds && $access->can('dashboard.team')) {
            $teamIds = $access->headedTeamIds;
            $me = KpiService::rows($month, $from, $to, ['userIds' => [$access->id()]])[0] ?? null;
            $deals = DB::table('deals')->whereIn('team_id', $teamIds)->select('id');
            if ($me && $me['rop']) {
                $out['team'] = [
                    'team' => DB::table('teams')->whereIn('id', $teamIds)->first(['id', 'name']),
                    'kpi' => $me['rop'],
                    'leads' => $inRange(DB::table('leads')->whereIn('team_id', $teamIds)->whereNull('deleted_at'), 'created_at')->count(),
                    'meetings' => $inRange(DB::table('meetings')->whereIn('team_id', $teamIds)->where('status', 'DONE'), 'starts_at')->count(),
                    'proposals' => $inRange(DB::table('proposals')->whereIn('deal_id', $deals), 'sent_at')->count(),
                    'contracts' => $inRange(DB::table('contracts')->where('status', 'SIGNED')->whereIn('deal_id', $deals), 'signed_at')->count(),
                    'payments' => $inRange(DB::table('payments')->where('status', 'PAID')->where('type', '<>', 'REFUND')->whereIn('deal_id', $deals), 'paid_at')->count(),
                    'managers' => KpiService::rows($month, $from, $to, ['group' => 'MANAGER']),
                ];
            }
        }

        $open = ProjectAccess::OPEN_TASK;
        if ($access->roleCode === 'MANAGER') {
            $me = KpiService::rows($month, $from, $to, ['userIds' => [$access->id()]])[0] ?? null;
            $mine = fn () => DB::table('tasks')->where('assignee_id', $access->id())->whereNull('deleted_at')->whereIn('status', $open);
            if ($me) {
                $out['own'] = [
                    'kpi' => $me,
                    'deals' => DB::table('deals')->where('owner_id', $access->id())->where('status', 'OPEN')->whereNull('deleted_at')->count(),
                    'commissionUzs' => (string) ($inRange(DB::table('commissions')->where('user_id', $access->id())->where('status', '<>', 'CANCELLED'), 'created_at')->sum('amount_uzs') ?: '0'),
                    'tasksOpen' => $mine()->count(),
                    'tasksOverdue' => $mine()->where('deadline', '<', now())->count(),
                ];
            }
        }

        if ($access->roleCode === 'EXECUTOR') {
            $now = now();
            $tomorrow = Tasks::companyDayStart(Tasks::companyDate($now))->addDay();
            $mine = fn () => DB::table('tasks as t')->join('projects as p', 'p.id', '=', 't.project_id')->whereNull('p.deleted_at')
                ->where('t.assignee_id', $access->id())->whereNull('t.deleted_at');
            $out['executor'] = [
                'projects' => DB::table('projects')->whereNull('deleted_at')->whereIn('status', ProjectAccess::ACTIVE)
                    ->whereExists(fn ($s) => $s->selectRaw('1')->from('project_members as m')->whereColumn('m.project_id', 'projects.id')->where('m.user_id', $access->id())->where('m.status', 'ACTIVE'))->count(),
                'today' => $mine()->whereIn('t.status', $open)->where('t.deadline', '>=', $now)->where('t.deadline', '<', $tomorrow)->count(),
                'overdue' => $mine()->whereIn('t.status', $open)->where('t.deadline', '<', $now)->count(),
                'inProgress' => $mine()->where('t.status', 'IN_PROGRESS')->count(),
                'done' => $inRange($mine()->where('t.status', 'DONE'), 't.completed_at')->count(),
            ];
        }

        if ($access->roleCode === 'PROJECT_MANAGER' && $access->can('project.read')) {
            $now = now();
            $todayStart = Tasks::companyDayStart(Tasks::companyDate($now));
            $tomorrow = $todayStart->addDay();
            $active = fn () => ProjectAccess::projects()->whereIn('projects.status', ProjectAccess::ACTIVE);
            $tasks = fn () => ProjectAccess::tasks()->whereIn('tasks.status', $open);
            $out['projects'] = [
                'directions' => DB::table('directions')->whereIn('id', $access->directionIds ?: ['-'])->orderBy('sort')->pluck('name')->all(),
                'active' => $active()->count(),
                'overdueProjects' => $active()->where('projects.deadline', '<', Tasks::companyDate($now))->count(),
                'unassigned' => $active()->whereNotExists(fn ($s) => $s->selectRaw('1')->from('project_members as m')->whereColumn('m.project_id', 'projects.id')->where('m.status', '<>', 'REMOVED'))->count(),
                'tasksOpen' => $access->can('task.read') ? $tasks()->count() : 0,
                'tasksOverdue' => $access->can('task.read') ? $tasks()->where('tasks.deadline', '<', $now)->count() : 0,
                'tasksToday' => $access->can('task.read') ? $tasks()->where('tasks.deadline', '>=', $todayStart)->where('tasks.deadline', '<', $tomorrow)->count() : 0,
                'completed' => ProjectAccess::projects()->where('projects.status', 'COMPLETED')->where('projects.completed_at', '>=', $from)->where('projects.completed_at', '<', $to)->count(),
            ];
        }

        if ($access->roleCode !== 'CEO' && $access->can('kpi.read')) {
            $out['myKpi'] = self::myKpi($month, $period);
        }
        $out['attendance'] = PeopleService::tracksAttendance($access->roleCode) ? PeopleService::today() : null;

        return $out;
    }

    /** «Мой KPI»: выполнение целей месяца и сумма KPI-бонуса по текущему выполнению. */
    private static function myKpi(string $month, array $period): ?array
    {
        $access = access();
        $me = KpiService::rows($month, $period['from'], $period['to'], ['userIds' => [$access->id()]])[0] ?? null;
        if (! $me) {
            return null;
        }
        $employee = DB::table('employees')->where('user_id', $access->id())->first();
        $commission = (string) (DB::table('commissions')->where('user_id', $access->id())->where('period', $month)->where('status', '<>', 'CANCELLED')->sum('amount_uzs') ?: '0');
        // Сдельно: начисления по проектам за период
        $piece = (string) (DB::table('expenses')->where('payee_user_id', $access->id())->where('category', 'EXECUTOR')->whereNull('deleted_at')
            ->where('expense_date', '>=', $period['fromDate'])->where('expense_date', '<', $period['toDate'])->sum('amount_uzs') ?: '0');
        // За опубликованные ролики SMM (видеограф, монтажёр) — действующие начисления месяца
        $smmVideo = SmmVideoPay::byUser($month, [$access->id()])[$access->id()]['sum'] ?? '0.00';
        $bonusTarget = $employee?->kpi_bonus_target !== null ? (string) BigDecimal::of($employee->kpi_bonus_target)->toScale(2) : null;
        $bonus = People::kpiBonusFor($bonusTarget, $me['kpiPct']);
        // Оклад — только у менеджеров и РОП
        $base = PeopleService::hasFixedSalary($access->roleCode) && $employee?->base_salary !== null ? (string) BigDecimal::of($employee->base_salary)->toScale(2) : null;

        return [
            'period' => $month,
            'pct' => $me['kpiPct'],
            'targets' => $me['targets'],
            'bonusTarget' => $bonusTarget,
            'bonusUzs' => $bonus,
            'commissionUzs' => (string) BigDecimal::of($commission)->toScale(2),
            'baseSalary' => $base,
            'pieceRateUzs' => (string) BigDecimal::of($piece)->toScale(2),
            'smmVideoUzs' => $smmVideo,
            'expectedUzs' => People::finalSalary(['baseSalary' => $base ?? '0', 'pieceRate' => BigDecimal::of($piece)->plus($smmVideo), 'kpiBonus' => $bonus, 'commission' => $commission, 'otherBonus' => '0', 'penalty' => '0']),
        ];
    }
}
