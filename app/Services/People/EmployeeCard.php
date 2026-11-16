<?php

namespace App\Services\People;

use App\Domain\People;
use App\Models\Commission;
use App\Models\PayrollEntry;
use App\Models\User;
use App\Services\Finance\Period;
use App\Services\Projects\ProjectAccess;
use App\Services\Sales\CommissionsService;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Карточка сотрудника (Команда → Сотрудники → сотрудник): шапка, эффективность за месяц, зарплата и комиссии
 * по месяцам, авансы, активность месяца. Доступ к каждому блоку — на сервере:
 *  - карточка: своя — всегда, чужая — employee.read с областью (иначе 404);
 *  - эффективность и активность: своя — всегда, чужая — kpi.read с областью;
 *  - зарплата и авансы: своя — всегда, чужая — payroll.read с областью;
 *  - комиссии: свои — всегда, чужие — commission.read с областью (CommissionsService::query).
 */
final class EmployeeCard
{
    public const MONTHS = 12;

    /** Сотрудник для карточки или 404 (чужой без employee.read — 403). */
    public static function find(string $id): User
    {
        $access = access();
        $with = ['role', 'team', 'employee.schedule', 'directions.direction', 'headedTeams'];
        if ($id === $access->id()) {
            return User::with($with)->findOrFail($id);
        }
        $access->authorize('employee.read');

        return UserService::visible()->with($with)->whereKey($id)->first() ?? abort(404);
    }

    /** Видит ли текущий пользователь данные сотрудника по праву $code (своя запись — всегда). */
    public static function sees(User $user, string $code): bool
    {
        $access = access();
        if ($user->id === $access->id()) {
            return true;
        }

        return $access->can($code) && KpiService::users($code)->whereKey($user->id)->exists();
    }

    /** Месяцы от $period назад: ['2026-11', '2026-10', ...]. */
    public static function months(string $period, int $n = self::MONTHS): array
    {
        $end = CarbonImmutable::createFromFormat('!Y-m', $period);

        return array_map(fn ($i) => $end->subMonthsNoOverflow($i)->format('Y-m'), range(0, $n - 1));
    }

    /** Зарплата по месяцам (новые сверху) — только рассчитанные месяцы. */
    public static function payroll(User $user, string $period): Collection
    {
        return PayrollEntry::where('user_id', $user->id)->whereIn('period', self::months($period))->orderByDesc('period')->get();
    }

    /**
     * Комиссии по месяцам: period → [rows, totals по статусам, total без отменённых].
     *
     * @return array<string, array{rows:Collection,byStatus:array<string,string>,total:string}>
     */
    public static function commissions(User $user, string $period): array
    {
        $access = access();
        $q = $user->id === $access->id() ? Commission::query() : CommissionsService::query();
        $rows = $q->where('user_id', $user->id)->whereIn('period', self::months($period))
            ->with(['deal:id,number,title', 'deal.project:id,number,name,deal_id', 'payment:id,number,project_id', 'payment.project:id,number,name'])
            ->orderByDesc('period')->orderByDesc('created_at')->get();
        $out = [];
        foreach ($rows->groupBy('period') as $p => $items) {
            $by = [];
            $total = BigDecimal::zero();
            foreach ($items as $c) {
                $by[$c->status] = (string) BigDecimal::of($by[$c->status] ?? '0')->plus((string) $c->amount_uzs)->toScale(2);
                if ($c->status !== 'CANCELLED') {
                    $total = $total->plus((string) $c->amount_uzs);
                }
            }
            $out[$p] = ['rows' => $items, 'byStatus' => $by, 'total' => (string) $total->toScale(2)];
        }
        krsort($out);

        return $out;
    }

    /**
     * Активность за месяц: числа и (если у зрителя есть право на список) ссылки на отфильтрованные списки.
     *
     * @return list<array{key:string,value:string,url:?string}>
     */
    public static function activity(User $user, string $period): array
    {
        $access = access();
        ['from' => $from, 'to' => $to] = People::monthRange($period);
        [$d1, $d2] = Period::monthDates($period);
        $lastDay = CarbonImmutable::createFromFormat('!Y-m-d', $d2)->subDay()->format('Y-m-d');
        $range = ['date_from' => $d1, 'date_to' => $lastDay];
        $role = $user->role?->code;
        $out = [];
        $link = fn (string $perm, string $path, array $q) => $access->can($perm) ? $path.'?'.http_build_query($q) : null;
        if (in_array($role, ['MANAGER', 'ROP'], true)) {
            $leads = DB::table('leads')->where('owner_id', $user->id)->whereNull('deleted_at')->where('created_at', '>=', $from)->where('created_at', '<', $to)->count();
            $out[] = ['key' => 'leads', 'value' => (string) $leads, 'url' => $link('lead.read', '/sales/leads', ['view' => 'list', 'status' => 'any', 'owner_id' => $user->id] + $range)];
            $deals = DB::table('deals')->where('owner_id', $user->id)->whereNull('deleted_at')->where('created_at', '>=', $from)->where('created_at', '<', $to)->count();
            $out[] = ['key' => 'deals', 'value' => (string) $deals, 'url' => $link('deal.read', '/sales/deals', ['view' => 'list', 'status' => 'any', 'owner_id' => $user->id] + $range)];
            $won = DB::table('deals')->where('owner_id', $user->id)->whereNull('deleted_at')->where('won_at', '>=', $from)->where('won_at', '<', $to)->count();
            $out[] = ['key' => 'won', 'value' => (string) $won, 'url' => $link('deal.read', '/sales/deals', ['view' => 'list', 'status' => 'WON', 'owner_id' => $user->id])];
            $m = DB::table('meetings')->where('manager_id', $user->id)->where('starts_at', '>=', $from)->where('starts_at', '<', $to)
                ->groupBy('status')->selectRaw('status, COUNT(*) n')->pluck('n', 'status');
            $out[] = ['key' => 'meetings', 'value' => t('employees.score.of', ['done' => (int) ($m['DONE'] ?? 0), 'total' => (int) $m->except(['CANCELLED', 'RESCHEDULED'])->sum()]),
                'url' => $link('meeting.read', '/sales/meetings', ['view' => 'all', 'manager_id' => $user->id] + $range)];
        }
        if (in_array($role, ['EXECUTOR', 'MANAGER', 'ROP', 'PROJECT_MANAGER'], true)) {
            $done = DB::table('tasks')->where('assignee_id', $user->id)->whereNull('deleted_at')->where('status', 'DONE')
                ->where('completed_at', '>=', $from)->where('completed_at', '<', $to)->count();
            $overdue = DB::table('tasks')->where('assignee_id', $user->id)->whereNull('deleted_at')->whereIn('status', ProjectAccess::OPEN_TASK)
                ->where('deadline', '<', min($to, CarbonImmutable::now()))->count();
            if ($done || $overdue || $role === 'EXECUTOR') {
                $out[] = ['key' => 'tasksDone', 'value' => (string) $done, 'url' => $user->id === $access->id() && $access->can('task.read') ? '/tasks' : null];
                $out[] = ['key' => 'tasksOverdue', 'value' => (string) $overdue, 'url' => null];
            }
        }
        $todosDone = DB::table('todos')->where('owner_id', $user->id)->whereNull('deleted_at')->where('status', 'DONE')
            ->where('completed_at', '>=', $from)->where('completed_at', '<', $to)->count();
        $todosOverdue = DB::table('todos')->where('owner_id', $user->id)->whereNull('deleted_at')->where('status', 'OPEN')
            ->where('due_at', '<', min($to, CarbonImmutable::now()))->count();
        $out[] = ['key' => 'todosDone', 'value' => (string) $todosDone, 'url' => $user->id === $access->id() ? '/todos' : null];
        $out[] = ['key' => 'todosOverdue', 'value' => (string) $todosOverdue, 'url' => null];

        return $out;
    }
}
