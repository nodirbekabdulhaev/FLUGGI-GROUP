<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Debt;
use App\Models\Expense;
use App\Models\Group;
use App\Models\Lead;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\SalaryAccrual;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** All management analytics. Scoped automatically by organization and selected branch. */
class ReportService
{
    protected static function pct(float|int $a, float|int $b): ?float
    {
        return $b > 0 ? round($a * 100 / $b, 1) : null;
    }

    public function revenue(Carbon $from, Carbon $to): float
    {
        return (float) Payment::whereBetween('paid_at', [$from, $to])->sum(DB::raw(Payment::SIGNED_SQL));
    }

    public function expenses(Carbon $from, Carbon $to): float
    {
        return (float) Expense::whereBetween('spent_at', [$from->toDateString(), $to->toDateString()])->sum('amount');
    }

    public function totalDebt(): float
    {
        return (float) Debt::where('balance', '>', 0)->sum('balance');
    }

    /** Overall attendance percent in a period = (present + late) / marked. */
    public function attendancePercent(Carbon $from, Carbon $to): ?float
    {
        $r = Attendance::join('lessons', 'lessons.id', '=', 'attendance.lesson_id')
            ->whereBetween('lessons.lesson_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw("COUNT(*) total, SUM(CASE WHEN attendance.status IN ('present','late') THEN 1 ELSE 0 END) ok")->first();

        return static::pct((int) $r->ok, (int) $r->total);
    }

    /** Lead funnel for leads created in the period: new → contacted → booked → attended → sold. */
    public function funnel(Carbon $from, Carbon $to, ?User $user = null): array
    {
        $q = Lead::whereBetween('created_at', [$from, $to]);
        if ($user) {
            $q->visibleTo($user);
        }
        $r = $q->selectRaw('COUNT(*) total,
            SUM(CASE WHEN max_stage >= 2 THEN 1 ELSE 0 END) contacted,
            SUM(CASE WHEN max_stage >= 3 THEN 1 ELSE 0 END) booked,
            SUM(CASE WHEN max_stage >= 4 THEN 1 ELSE 0 END) attended,
            SUM(CASE WHEN max_stage >= 5 THEN 1 ELSE 0 END) sold')->first();

        $total = (int) $r->total;
        $c = (int) $r->contacted;
        $b = (int) $r->booked;
        $a = (int) $r->attended;
        $s = (int) $r->sold;

        return [
            'total' => $total, 'contacted' => $c, 'booked' => $b, 'attended' => $a, 'sold' => $s,
            'contact_rate' => static::pct($c, $total),
            'booking_rate' => static::pct($b, $c),
            'show_rate' => static::pct($a, $b),
            'conversion_rate' => static::pct($s, $total),
        ];
    }

    public function dashboard(Carbon $from, Carbon $to, ?User $user = null): array
    {
        $revenue = $this->revenue($from, $to);
        $expenses = $this->expenses($from, $to);
        $days = max(1, $from->diffInDays($to) + 1);

        $revByDay = Payment::whereBetween('paid_at', [$from, $to])
            ->selectRaw('DATE(paid_at) d, SUM('.Payment::SIGNED_SQL.') s')->groupBy('d')->pluck('s', 'd');
        $expByDay = Expense::whereBetween('spent_at', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('DATE(spent_at) d, SUM(amount) s')->groupBy('d')->pluck('s', 'd');
        $leadsByDay = Lead::whereBetween('created_at', [$from, $to])->selectRaw('DATE(created_at) d, COUNT(*) c')->groupBy('d')->pluck('c', 'd');

        $series = ['labels' => [], 'revenue' => [], 'expenses' => [], 'leads' => []];
        // very long periods are bucketed by month to keep the chart readable
        if ($days > 62) {
            $cursor = $from->copy()->startOfMonth();
            while ($cursor->lte($to)) {
                $key = $cursor->format('Y-m');
                $series['labels'][] = $cursor->translatedFormat('M Y');
                $series['revenue'][] = (float) $revByDay->filter(fn ($v, $d) => str_starts_with($d, $key))->sum();
                $series['expenses'][] = (float) $expByDay->filter(fn ($v, $d) => str_starts_with($d, $key))->sum();
                $series['leads'][] = (int) $leadsByDay->filter(fn ($v, $d) => str_starts_with($d, $key))->sum();
                $cursor->addMonth();
            }
        } else {
            for ($d = $from->copy()->startOfDay(); $d->lte($to); $d->addDay()) {
                $k = $d->toDateString();
                $series['labels'][] = $d->format('d.m');
                $series['revenue'][] = (float) ($revByDay[$k] ?? 0);
                $series['expenses'][] = (float) ($expByDay[$k] ?? 0);
                $series['leads'][] = (int) ($leadsByDay[$k] ?? 0);
            }
        }

        return [
            'active_students' => Student::where('status', 'active')->count(),
            'new_leads' => ($user ? Lead::visibleTo($user) : Lead::query())->whereBetween('created_at', [$from, $to])->count(),
            'new_students' => Student::whereBetween('created_at', [$from, $to])->count(),
            'revenue' => $revenue,
            'debts' => $this->totalDebt(),
            'expenses' => $expenses,
            'profit' => $revenue - $expenses,
            'attendance' => $this->attendancePercent($from, $to),
            'funnel' => $this->funnel($from, $to, $user),
            'series' => $series,
            'sources' => $this->sales($from, $to, $user)['sources']->take(6)->values(),
            'overdue' => Debt::where('balance', '>', 0)->whereNotNull('next_payment_date')->where('next_payment_date', '<', today()->toDateString())->count(),
        ];
    }

    /**
     * Sales analytics: sources, managers, dynamics. Cohort = leads created in the period.
     * Pure SQL aggregates (no models loaded) so it stays fast with 100k+ leads.
     */
    public function sales(Carbon $from, Carbon $to, ?User $user = null): array
    {
        $org = \App\Support\Tenant::id();
        $cohort = fn () => ($user ? Lead::visibleTo($user) : Lead::query())->whereBetween('leads.created_at', [$from, $to]);
        $cols = 'COUNT(*) leads, SUM(CASE WHEN leads.max_stage >= 2 THEN 1 ELSE 0 END) processed, SUM(CASE WHEN leads.max_stage >= 5 THEN 1 ELSE 0 END) sales';

        // revenue of the students converted from the cohort's leads, grouped by a leads column
        $revenueBy = fn (string $col) => (clone $cohort())->join('students', fn ($j) => $j->on('students.lead_id', '=', 'leads.id')->whereNull('students.deleted_at'))
            ->join('payments', fn ($j) => $j->on('payments.student_id', '=', 'students.id')->whereNull('payments.deleted_at'))
            ->selectRaw("leads.$col k, SUM(".Payment::SIGNED_SQL.') revenue')->groupBy("leads.$col")->pluck('revenue', 'k');

        $shape = function ($r, $revenue) {
            $sales = (int) $r->sales;
            $rev = (float) $revenue;

            return [
                'leads' => (int) $r->leads, 'processed' => (int) $r->processed, 'sales' => $sales,
                'conversion' => static::pct($sales, (int) $r->leads), 'revenue' => $rev, 'avg_check' => $sales ? round($rev / $sales) : 0,
            ];
        };

        $srcRevenue = $revenueBy('source_id');
        $sources = (clone $cohort())->leftJoin('lead_sources', 'lead_sources.id', '=', 'leads.source_id')
            ->selectRaw("leads.source_id sid, COALESCE(lead_sources.name, 'Без источника') name, $cols")->groupBy('leads.source_id', 'name')->get()
            ->map(fn ($r) => ['name' => $r->name] + $shape($r, $srcRevenue[$r->sid] ?? 0))->sortByDesc('leads')->values();

        $mgrRevenue = $revenueBy('manager_id');
        $managers = (clone $cohort())->leftJoin('users', 'users.id', '=', 'leads.manager_id')
            ->selectRaw("leads.manager_id mid, COALESCE(users.name, 'Не назначен') name, $cols")->groupBy('leads.manager_id', 'name')->get()
            ->map(fn ($r) => ['name' => $r->name] + $shape($r, $mgrRevenue[$r->mid] ?? 0))->sortByDesc('sales')->values();

        $total = (clone $cohort())->selectRaw($cols)->first();
        $totalRevenue = (clone $cohort())->join('students', fn ($j) => $j->on('students.lead_id', '=', 'leads.id')->whereNull('students.deleted_at'))
            ->join('payments', fn ($j) => $j->on('payments.student_id', '=', 'students.id')->whereNull('payments.deleted_at'))
            ->selectRaw('COALESCE(SUM('.Payment::SIGNED_SQL.'),0) r')->value('r');

        $daily = (clone $cohort())->selectRaw('DATE(leads.created_at) d, COUNT(*) leads, SUM(CASE WHEN leads.max_stage >= 5 THEN 1 ELSE 0 END) sales')
            ->groupBy('d')->orderBy('d')->get()
            ->map(fn ($r) => ['date' => $r->d, 'leads' => (int) $r->leads, 'sales' => (int) $r->sales])->values();

        return ['total' => $shape($total, $totalRevenue), 'funnel' => $this->funnel($from, $to, $user), 'sources' => $sources, 'managers' => $managers, 'daily' => $daily];
    }

    public function finance(Carbon $from, Carbon $to): array
    {
        $payments = Payment::whereBetween('paid_at', [$from, $to]);
        $revenue = $this->revenue($from, $to);
        $count = (clone $payments)->where('type', 'payment')->count();
        $expenses = $this->expenses($from, $to);

        $byMethod = Payment::whereBetween('paid_at', [$from, $to])->leftJoin('payment_methods', 'payment_methods.id', '=', 'payments.method_id')
            ->selectRaw('COALESCE(payment_methods.name, \'—\') name, SUM('.Payment::SIGNED_SQL.') total, COUNT(*) cnt')->groupBy('name')->orderByDesc('total')->get();
        $byCategory = Expense::whereBetween('spent_at', [$from->toDateString(), $to->toDateString()])
            ->leftJoin('expense_categories', 'expense_categories.id', '=', 'expenses.category_id')
            ->selectRaw('COALESCE(expense_categories.name, \'—\') name, SUM(expenses.amount) total')->groupBy('name')->orderByDesc('total')->get();
        $byBranch = Payment::whereBetween('paid_at', [$from, $to])->leftJoin('branches', 'branches.id', '=', 'payments.branch_id')
            ->selectRaw('COALESCE(branches.name, \'—\') name, SUM('.Payment::SIGNED_SQL.') total')->groupBy('name')->orderByDesc('total')->get();

        return [
            'revenue' => $revenue,
            'expenses' => $expenses,
            'profit' => $revenue - $expenses,
            'debts' => $this->totalDebt(),
            'payments_count' => $count,
            'avg_check' => $count ? round($revenue / $count) : 0,
            'by_method' => $byMethod,
            'by_category' => $byCategory,
            'by_branch' => $byBranch,
        ];
    }

    public function students(Carbon $from, Carbon $to): array
    {
        $byStatus = Student::selectRaw('status, COUNT(*) c')->groupBy('status')->pluck('c', 'status');
        $expelled = Student::where('status', 'expelled')->whereBetween('status_changed_at', [$from->toDateString(), $to->toDateString()]);
        $expelledCount = (clone $expelled)->count();
        $activeNow = (int) ($byStatus['active'] ?? 0);
        $newCount = Student::whereBetween('created_at', [$from, $to])->count();
        // students that were active at the start of the period ≈ active now − new + expelled
        $startBase = max(0, $activeNow - $newCount + $expelledCount);

        $reasons = (clone $expelled)->leftJoin('expulsion_reasons', 'expulsion_reasons.id', '=', 'students.expulsion_reason_id')
            ->selectRaw('COALESCE(expulsion_reasons.name, \'Неизвестно\') name, COUNT(*) c')->groupBy('name')->orderByDesc('c')->get();

        return [
            'new' => $newCount,
            'active' => $activeNow,
            'frozen' => (int) ($byStatus['frozen'] ?? 0),
            'expelled' => $expelledCount,
            'completed' => (int) ($byStatus['completed'] ?? 0),
            'churn' => static::pct($expelledCount, $startBase),
            'retention' => $startBase ? round(100 - $expelledCount * 100 / $startBase, 1) : null,
            'reasons' => $reasons,
            'by_branch' => Student::leftJoin('branches', 'branches.id', '=', 'students.branch_id')->where('students.status', 'active')
                ->selectRaw('COALESCE(branches.name, \'—\') name, COUNT(*) c')->groupBy('name')->get(),
        ];
    }

    /** Attendance by group / teacher / student for lessons in the period. */
    public function attendance(Carbon $from, Carbon $to, bool $withStudents = true): array
    {
        $base = fn () => Attendance::join('lessons', 'lessons.id', '=', 'attendance.lesson_id')
            ->whereBetween('lessons.lesson_date', [$from->toDateString(), $to->toDateString()]);
        $cols = "COUNT(*) total,
            SUM(CASE WHEN attendance.status = 'present' THEN 1 ELSE 0 END) present,
            SUM(CASE WHEN attendance.status = 'late' THEN 1 ELSE 0 END) late,
            SUM(CASE WHEN attendance.status = 'absent' THEN 1 ELSE 0 END) absent,
            SUM(CASE WHEN attendance.status = 'excused' THEN 1 ELSE 0 END) excused";
        $decorate = fn ($rows) => $rows->map(function ($r) {
            $r->percent = static::pct((int) $r->present + (int) $r->late, (int) $r->total);

            return $r;
        });

        $groups = $decorate($base()->join('groups', 'groups.id', '=', 'lessons.group_id')
            ->selectRaw("groups.id, groups.name, COUNT(DISTINCT lessons.id) lessons, $cols")->groupBy('groups.id', 'groups.name')->orderBy('groups.name')->get());
        $teachers = $decorate($base()->join('teachers', 'teachers.id', '=', 'lessons.teacher_id')
            ->selectRaw("teachers.id, teachers.full_name name, COUNT(DISTINCT lessons.id) lessons, $cols")->groupBy('teachers.id', 'teachers.full_name')->orderBy('name')->get());
        // per-student rows are only needed by the attendance report page, not by teacher KPIs
        $students = $withStudents
            ? $decorate($base()->join('students', 'students.id', '=', 'attendance.student_id')
                ->selectRaw("students.id, students.first_name, students.last_name, $cols")->groupBy('students.id', 'students.first_name', 'students.last_name')
                ->orderBy('students.first_name')->limit(300)->get())
            : collect();

        // overall figure derived from the group rows: no extra scan of the attendance table
        $total = (int) $groups->sum('total');
        $ok = (int) ($groups->sum('present') + $groups->sum('late'));

        return ['percent' => static::pct($ok, $total), 'groups' => $groups, 'teachers' => $teachers, 'students' => $students];
    }

    /** Teacher KPI (spec §43). */
    public function teachers(Carbon $from, Carbon $to): Collection
    {
        $range = [$from->toDateString(), $to->toDateString()];
        $att = $this->attendance($from, $to, false);

        return Teacher::where('status', '!=', 'inactive')->orderBy('full_name')->get()->map(function (Teacher $t) use ($range, $from, $to, $att) {
            $groupIds = $t->groups()->pluck('id');
            $lessons = Lesson::where('teacher_id', $t->id)->whereBetween('lesson_date', $range);
            $groupsAtt = $att['groups']->whereIn('id', $groupIds)->filter(fn ($g) => $g->percent !== null);
            $teacherAtt = $att['teachers']->firstWhere('id', $t->id);

            return [
                'id' => $t->id,
                'name' => $t->full_name,
                'groups' => $groupIds->count(),
                'students' => \App\Models\GroupStudent::whereIn('group_id', $groupIds)->whereIn('status', ['active', 'frozen'])->distinct()->count('student_id'),
                'lessons' => (clone $lessons)->where('status', 'held')->count(),
                'attendance' => $teacherAtt?->percent,
                'avg_group_attendance' => $groupsAtt->isNotEmpty() ? round($groupsAtt->avg('percent'), 1) : null,
                'cancelled' => (clone $lessons)->where('status', 'cancelled')->count(),
                'salary' => (float) SalaryAccrual::where('payable_type', $t->getMorphClass())->where('payable_id', $t->id)
                    ->whereBetween('period', [$from->format('Y-m'), $to->format('Y-m')])->sum('amount'),
            ];
        });
    }

    /** Evening digest for the director (cron 21:00). */
    public function dailyDigest(Carbon $day): array
    {
        $from = $day->copy()->startOfDay();
        $to = $day->copy()->endOfDay();
        $lessons = Lesson::whereDate('lesson_date', $day->toDateString());
        $newDebt = Debt::where('balance', '>', 0)->whereDate('created_at', $day->toDateString())->sum('balance');

        return [
            'date' => $day->format('d.m.Y'),
            'leads' => Lead::whereBetween('created_at', [$from, $to])->count(),
            'students' => Student::whereBetween('created_at', [$from, $to])->count(),
            'paid' => number_format($this->revenue($from, $to), 0, '.', ' '),
            'debts' => number_format((float) $newDebt, 0, '.', ' '),
            'lessons' => (clone $lessons)->where('status', 'held')->count(),
            'attendance' => $this->attendancePercent($from, $to) ?? 0,
            'cancelled' => (clone $lessons)->where('status', 'cancelled')->count(),
        ];
    }
}
