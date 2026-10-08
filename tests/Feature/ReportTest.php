<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\ExpulsionReason;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Lesson;
use App\Models\PaymentMethod;
use App\Models\Student;
use App\Services\AttendanceService;
use App\Services\EnrollmentService;
use App\Services\LeadService;
use App\Services\PaymentService;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    private function range(): array
    {
        return [now()->startOfMonth(), now()->endOfMonth()];
    }

    public function test_source_analytics_leads_sales_conversion_revenue(): void
    {
        $this->makeOrg();
        $this->actingAs($this->makeUser('admin'));
        $mgr1 = $this->makeUser('sales_manager', ['name' => 'Mgr One']);
        $mgr2 = $this->makeUser('sales_manager', ['name' => 'Mgr Two']);
        $insta = LeadSource::where('name', 'Instagram')->value('id');
        $tg = LeadSource::where('name', 'Telegram')->value('id');
        $leads = app(LeadService::class);
        $group = $this->makeGroup(['price' => 600000, 'max_students' => 20]);
        $method = PaymentMethod::first()->id;

        // Instagram: 4 leads, 2 sold (600k each). Telegram: 2 leads, 1 sold (600k)
        $mk = fn ($i, $src, $mgr) => $leads->create(['first_name' => "L$i", 'phone' => "+99890100000$i", 'branch_id' => $this->branch()->id, 'source_id' => $src, 'manager_id' => $mgr->id], null, false);
        $l = [$mk(1, $insta, $mgr1), $mk(2, $insta, $mgr1), $mk(3, $insta, $mgr2), $mk(4, $insta, $mgr2), $mk(5, $tg, $mgr1), $mk(6, $tg, $mgr2)];
        foreach ([0, 1, 4] as $i) {
            $st = $leads->convert($l[$i], ['group_id' => $group->id]);
            app(PaymentService::class)->record(['student_id' => $st->id, 'amount' => 600000, 'method_id' => $method]);
        }
        $leads->changeStatus($l[2], LeadStatus::where('slug', 'contacted')->first());

        [$from, $to] = $this->range();
        $d = app(ReportService::class)->sales($from, $to);
        $src = $d['sources']->keyBy('name');

        $this->assertEquals(['leads' => 4, 'sales' => 2, 'conversion' => 50.0, 'revenue' => 1200000.0, 'avg_check' => 600000], collect($src['Instagram'])->only(['leads', 'sales', 'conversion', 'revenue', 'avg_check'])->all());
        $this->assertEquals(['leads' => 2, 'sales' => 1, 'conversion' => 50.0, 'revenue' => 600000.0], collect($src['Telegram'])->only(['leads', 'sales', 'conversion', 'revenue'])->all());
        $this->assertEquals([6, 3, 50.0, 1800000.0, 600000], [$d['total']['leads'], $d['total']['sales'], $d['total']['conversion'], $d['total']['revenue'], $d['total']['avg_check']]);

        $m = $d['managers']->keyBy('name');
        // Mgr One owns l1, l2, l5 — all three were converted and paid
        $this->assertEquals([3, 3, 3, 1800000.0, 100.0], [$m['Mgr One']['leads'], $m['Mgr One']['processed'], $m['Mgr One']['sales'], $m['Mgr One']['revenue'], $m['Mgr One']['conversion']]);
        // Mgr Two owns l3 (contacted), l4, l6 (untouched)
        $this->assertEquals([3, 1, 0, 0.0], [$m['Mgr Two']['leads'], $m['Mgr Two']['processed'], $m['Mgr Two']['sales'], $m['Mgr Two']['revenue']]);

        // a sales manager only sees own leads in the same report
        $own = app(ReportService::class)->sales($from, $to, $mgr2);
        $this->assertSame(3, $own['total']['leads']);       // own only; Mgr One's leads are not visible
    }

    public function test_student_retention_churn_and_expulsion_reasons(): void
    {
        $this->makeOrg();
        $reason = ExpulsionReason::where('name', 'Дорого')->value('id');
        $active = collect(range(1, 8))->map(fn () => $this->makeStudent());
        $gone = collect(range(1, 2))->map(fn () => $this->makeStudent(['status' => 'expelled', 'expulsion_reason_id' => $reason, 'status_changed_at' => today()]));
        $this->makeStudent(['status' => 'frozen']);

        [$from, $to] = $this->range();
        $d = app(ReportService::class)->students($from, $to);
        $this->assertSame([11, 8, 1, 2], [$d['new'], $d['active'], $d['frozen'], $d['expelled']]);
        // start base = active(8) − new(11) + expelled(2) → clamped; churn must stay within 0..100
        $this->assertGreaterThanOrEqual(0, $d['churn']);
        $this->assertSame('Дорого', $d['reasons'][0]->name);
        $this->assertSame(2, (int) $d['reasons'][0]->c);
    }

    public function test_attendance_report_and_teacher_kpi(): void
    {
        $this->makeOrg();
        $this->actingAs($this->makeUser('admin'));
        $t = $this->makeTeacher(['full_name' => 'Kpi Teacher']);
        $group = $this->makeGroup(['teacher_id' => $t->id, 'max_students' => 10]);
        $students = collect(range(1, 4))->map(function () use ($group) {
            $s = $this->makeStudent();
            app(EnrollmentService::class)->enroll($s, $group);

            return $s;
        });
        $mk = fn ($time, $status = 'planned') => Lesson::create(['group_id' => $group->id, 'teacher_id' => $t->id, 'branch_id' => $group->branch_id, 'lesson_date' => today(), 'start_time' => $time, 'end_time' => $time, 'status' => $status]);
        $l1 = $mk('09:00:00');
        $l2 = $mk('11:00:00');
        $mk('13:00:00', 'cancelled');
        // lesson 1: all 4 present; lesson 2: 2 present, 1 late, 1 absent  → 7 of 8 visited? (2+1)=3 → 4+3 = 7/8
        $svc = app(AttendanceService::class);
        $svc->mark($l1, $students->mapWithKeys(fn ($s) => [$s->id => ['status' => 'present']])->all());
        $svc->mark($l2, [$students[0]->id => ['status' => 'present'], $students[1]->id => ['status' => 'present'], $students[2]->id => ['status' => 'late'], $students[3]->id => ['status' => 'absent']]);

        [$from, $to] = $this->range();
        $r = app(ReportService::class);
        $this->assertSame(87.5, $r->attendancePercent($from, $to));
        $g = $r->attendance($from, $to)['groups']->first();
        $this->assertSame([2, 8, 6, 1, 1], [(int) $g->lessons, (int) $g->total, (int) $g->present, (int) $g->late, (int) $g->absent]);
        $this->assertSame(87.5, $g->percent);

        $kpi = $r->teachers($from, $to)->firstWhere('name', 'Kpi Teacher');
        $this->assertSame([1, 4, 2, 87.5, 1], [$kpi['groups'], $kpi['students'], $kpi['lessons'], $kpi['attendance'], $kpi['cancelled']]);
    }

    public function test_daily_digest_matches_the_telegram_report_fields(): void
    {
        $this->makeOrg();
        $this->actingAs($this->makeUser('admin'));
        $group = $this->makeGroup(['price' => 600000]);
        $s = $this->makeStudent();
        app(EnrollmentService::class)->enroll($s, $group);
        app(PaymentService::class)->record(['student_id' => $s->id, 'amount' => 400000, 'method_id' => PaymentMethod::first()->id]);
        app(LeadService::class)->create(['first_name' => 'X', 'phone' => '+998901112200', 'branch_id' => $this->branch()->id], null, false);

        $d = app(ReportService::class)->dailyDigest(now());
        $this->assertSame(1, $d['leads']);
        $this->assertSame('400 000', $d['paid']);
        $this->assertSame('200 000', $d['debts']);
    }

    public function test_dashboard_numbers(): void
    {
        $this->makeOrg();
        $this->actingAs($this->makeUser('admin'));
        $group = $this->makeGroup(['price' => 1000000]);
        $s = $this->makeStudent();
        app(EnrollmentService::class)->enroll($s, $group);
        app(PaymentService::class)->record(['student_id' => $s->id, 'amount' => 700000, 'method_id' => PaymentMethod::first()->id]);
        \App\Models\Expense::create(['spent_at' => today(), 'amount' => 100000]);

        [$from, $to] = $this->range();
        $d = app(ReportService::class)->dashboard($from, $to);
        $this->assertEquals([1, 700000, 300000, 100000, 600000], [$d['active_students'], $d['revenue'], $d['debts'], $d['expenses'], $d['profit']]);
        $this->assertSame(count($d['series']['labels']), count($d['series']['revenue']));
        $this->assertEquals(700000, array_sum($d['series']['revenue']));
    }
}
