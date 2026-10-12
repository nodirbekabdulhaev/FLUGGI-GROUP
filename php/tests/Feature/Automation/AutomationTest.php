<?php

namespace Tests\Feature\Automation;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Deal;
use App\Models\FollowUp;
use App\Models\JobRun;
use App\Models\KpiTarget;
use App\Models\Meeting;
use App\Models\Notification;
use App\Models\OutboxEvent;
use App\Models\Payment;
use App\Models\Task;
use App\Models\WorkSchedule;
use App\Services\Automation\OverdueScanner;
use App\Services\Automation\ReminderService;
use App\Services\Automation\ReportService;
use App\Services\Automation\Scheduler;
use App\Support\Outbox;
use App\Support\Settings;
use Tests\Feature\Notifications\CommsFixtures;
use Tests\TestCase;

class AutomationTest extends TestCase
{
    use CommsFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtures();
    }

    public function test_reminders_once_and_job_slot_once(): void
    {
        $lead = $this->lead($this->manager, ['title' => 'Old']);
        $lead->forceFill(['created_at' => now()->subDays(5), 'last_contact_at' => null])->save();
        Meeting::create(['lead_id' => $lead->id, 'manager_id' => $this->manager->id, 'rop_id' => $this->rop->id, 'team_id' => $this->team1->id,
            'starts_at' => now()->addMinutes(20), 'type' => 'OFFLINE', 'created_by_id' => $this->manager->id]);
        $first = ReminderService::run();
        $this->assertSame(1, $first['lead.no_contact']);
        $this->assertSame(1, $first['meeting.soon']);
        $titles = Notification::where('user_id', $this->manager->id)->pluck('title')->all();
        $this->assertContains('Клиенту не звонили 3 дня', $titles);
        $this->assertTrue(collect($titles)->contains(fn ($t) => str_starts_with($t, 'Встреча через')));
        $this->assertSame([], ReminderService::run());

        $this->assertTrue(Scheduler::execute('smart-reminders', 'test-slot'));
        $this->assertFalse(Scheduler::execute('smart-reminders', 'test-slot'));
        $this->assertSame(1, JobRun::where('job', 'smart-reminders')->count());
    }

    public function test_manual_run_reports_and_settings_rights(): void
    {
        $this->actingAsUser($this->ceo)->get('/settings/automation')->assertOk()->assertSee(t('automation.jobs.overdue-tasks.label'))->assertSee('Новые лиды: 0');
        $this->assertSame(['overdue-tasks', 'smart-reminders', 'follow-ups', 'daily-report', 'absences', 'weekly-report', 'recurring-todos', 'client-health', 'payroll'], Scheduler::names());
        $this->actingAsUser($this->rop)->get('/settings/automation')->assertForbidden();
        $this->actingAsUser($this->manager)->postJson('/automation/jobs/daily-report/run')->assertForbidden();
        $this->actingAsUser($this->ceo)->postJson('/automation/jobs/unknown/run')->assertNotFound();

        $run = $this->postJson('/automation/jobs/daily-report/run')->assertOk()->json();
        $this->assertSame(['daily-report', null, ['ceo' => 1, 'rop' => 2]], [$run['job'], $run['error'], $run['result']]);
        $report = Notification::where('user_id', $this->ceo->id)->where('type', 'report.daily')->firstOrFail();
        $this->assertStringContainsString('Новые лиды: 0', $report->body);
        $this->assertStringContainsString('План месяца: цель не задана', $report->body);
        $this->assertSame(1, Notification::where('user_id', $this->rop->id)->where('type', 'report.daily')->count());

        $this->assertStringContainsString('НЕДЕЛЬНЫЙ ОТЧЁТ', $this->getJson('/reports/preview?kind=weekly')->json('text'));
        $this->assertStringContainsString('ОТЧЁТ ЗА', $this->actingAsUser($this->rop)->getJson('/reports/preview')->json('text'));
        $this->actingAsUser($this->manager)->getJson('/reports/preview')->assertForbidden();

        // Отчёты можно выключить; изменение — в журнале аудита
        $cur = Settings::automation();
        $this->actingAsUser($this->ceo)->putJson('/settings/automation', ['largeAmountUzs' => 1_000_000, 'followUps' => $cur['followUps'], 'dailyReport' => false, 'weeklyReport' => true])->assertOk();
        $this->actingAsUser($this->rop)->putJson('/settings/automation', $cur)->assertForbidden();
        $this->assertSame(['skipped' => true], $this->actingAsUser($this->ceo)->postJson('/automation/jobs/daily-report/run')->json('result'));
        $this->assertSame(1, AuditLog::where('action', 'settings.automation')->count());
        $this->putJson('/settings/automation', ['largeAmountUzs' => -1, 'followUps' => [['days' => 0, 'kind' => 'X']]])->assertStatus(422);
    }

    public function test_absences_by_schedule(): void
    {
        $schedule = WorkSchedule::create(['name' => 'Все дни', 'start_time' => '09:00', 'end_time' => '18:00', 'work_days' => [1, 2, 3, 4, 5, 6, 7]]);
        foreach ([$this->manager, $this->rop, $this->ceo] as $u) {
            $u->employee->update(['schedule_id' => $schedule->id]);
        }
        Attendance::create(['user_id' => $this->rop->id, 'date' => \App\Support\Format::today()->toDateString(), 'status' => 'PRESENT', 'check_in' => now()]);
        $r = $this->actingAsUser($this->ceo)->postJson('/automation/jobs/absences/run')->json('result');
        $this->assertGreaterThanOrEqual(1, $r['absent']);
        $this->assertSame('ABSENT', Attendance::where('user_id', $this->manager->id)->value('status'));
        $this->assertSame('PRESENT', Attendance::where('user_id', $this->rop->id)->value('status'));
        // CEO не отмечается; повторный запуск не создаёт дублей
        $this->assertFalse(Attendance::where('user_id', $this->ceo->id)->exists());
        $this->assertSame(['absent' => 0], $this->postJson('/automation/jobs/absences/run')->json('result'));
    }

    private function completedProject(): array
    {
        $s = $this->sale($this->manager, 'Repeat LLC', '3000000');
        $s['project']->update(['status' => 'COMPLETED', 'completed_at' => now()]);
        Outbox::publish('project.status_changed', ['projectId' => $s['project']->id, 'from' => 'IN_PROGRESS', 'to' => 'COMPLETED'], null);
        $this->drain();

        return $s;
    }

    public function test_follow_ups_after_project_and_completion_with_repeat_deal(): void
    {
        $s = $this->completedProject();
        $items = FollowUp::orderBy('due_date')->get();
        $this->assertSame(['CONTACT', 'NEW_PROJECT', 'REPEAT_SALE'], $items->pluck('kind')->all());
        // Повторное событие не создаёт дубликатов
        Outbox::publish('project.status_changed', ['projectId' => $s['project']->id, 'from' => 'IN_PROGRESS', 'to' => 'COMPLETED'], null);
        $this->drain();
        $this->assertSame(3, FollowUp::count());

        $this->actingAsUser($this->manager)->get('/sales/follow-ups?view=pending')->assertOk()->assertSee('Repeat LLC');
        // Чужой менеджер не видит follow-up клиента
        $this->actingAsUser($this->otherManager)->get('/sales/follow-ups?view=pending')->assertOk()->assertDontSee('Repeat LLC');
        $this->postJson('/sales/follow-ups/'.$items[0]->id.'/complete', ['status' => 'DONE'])->assertNotFound();
        $this->actingAsUser($this->executor)->get('/sales/follow-ups')->assertForbidden();

        // Пришёл срок — уведомление менеджеру
        $items[0]->update(['due_date' => '2020-01-01']);
        $this->assertSame(['notified' => 1], $this->actingAsUser($this->ceo)->postJson('/automation/jobs/follow-ups/run')->json('result'));
        $this->assertSame(1, Notification::where('user_id', $this->manager->id)->where('type', 'followup.due')->count());
        $this->actingAsUser($this->manager)->get('/sales/follow-ups')->assertOk()->assertSee('Repeat LLC');

        $done = $this->postJson('/sales/follow-ups/'.$items[2]->id.'/complete', ['status' => 'DONE', 'result' => 'Хотят продлить на 3 месяца', 'createDeal' => true])->assertOk()->json();
        $this->assertSame(['DONE', 'Хотят продлить на 3 месяца'], [$done['status'], $done['result']]);
        $deal = Deal::findOrFail($done['resultDeal']['id']);
        $this->assertSame([$s['client']->id, true, $this->manager->id], [$deal->client_id, (bool) $deal->is_repeat, $deal->owner_id]);
        $this->assertTrue(OutboxEvent::where('type', 'deal.created')->exists());
        $this->assertTrue(AuditLog::where('action', 'followup.complete')->exists());
        $this->postJson('/sales/follow-ups/'.$items[2]->id.'/complete', ['status' => 'SKIPPED'])->assertStatus(422);
        $this->postJson('/sales/follow-ups/'.$items[1]->id.'/complete', ['status' => 'SKIPPED', 'createDeal' => true])->assertStatus(422);
        foreach (['ru', 'uz'] as $loc) {
            $this->manager->update(['locale' => $loc]);
            $this->actingAsUser($this->manager)->get('/sales/follow-ups?view=closed')->assertOk()->assertSee(\App\Support\Lang::get('followUps.title', [], $loc));
        }
    }

    public function test_intervals_from_settings_and_plan_achieved_once(): void
    {
        Settings::save('automation', ['followUps' => [['days' => 14, 'kind' => 'CONTACT']]] + Settings::automation());
        $s = $this->completedProject();
        $this->assertSame(1, FollowUp::count());

        $period = ReportService::planProgress(null)['period'];
        KpiTarget::create(['user_id' => $this->manager->id, 'period' => $period, 'metric' => 'REVENUE', 'target_value' => 1000, 'currency' => 'UZS', 'set_by_id' => $this->ceo->id]);
        $payload = ['paymentId' => Payment::value('id'), 'dealId' => $s['deal']->id, 'amountUzs' => '3000000', 'managerId' => $this->manager->id,
            'teamId' => $this->team1->id, 'projectId' => $s['project']->id, 'projectCreated' => false];
        Outbox::publish('payment.paid', $payload, null);
        Outbox::publish('payment.paid', $payload, null);
        $this->drain();
        $this->assertSame(1, Notification::where('user_id', $this->ceo->id)->where('type', 'plan.achieved')->count());
        $this->assertSame(1, Notification::where('user_id', $this->rop->id)->where('type', 'plan.achieved')->count());
        // Оплата: менеджеру (кроме автора), РОП и CEO
        $this->assertSame(2, Notification::where('user_id', $this->ceo->id)->where('type', 'payment.paid')->count());
    }

    public function test_overdue_tasks_thresholds_1_3_7(): void
    {
        $s = $this->sale($this->manager);
        $task = Task::create(['project_id' => $s['project']->id, 'title' => 'Монтаж', 'assignee_id' => $this->executor->id, 'creator_id' => $this->manager->id, 'deadline' => now()->subHours(2)]);
        $this->assertSame(1, OverdueScanner::scan());
        $this->assertSame(0, OverdueScanner::scan());
        $this->assertSame(1, OverdueScanner::scan(now()->addDays(3)));
        $this->assertSame(0, OverdueScanner::scan(now()->addDays(4)));
        $this->assertSame(1, OverdueScanner::scan(now()->addDays(7)));
        $this->assertSame(3, OutboxEvent::where('type', 'task.overdue')->count());
        $task->update(['status' => 'DONE']);
        $this->assertSame(0, OverdueScanner::scan(now()->addDays(30)));
    }

    public function test_http_cron_requires_secret_and_delivers_events(): void
    {
        config(['fluggi.cron_secret' => 'cron-secret-for-tests-0123456789']);
        $this->postJson('/hooks/cron')->assertNotFound();
        $this->withHeader('x-cron-secret', 'wrong-secret-wrong-secret-000000')->postJson('/hooks/cron')->assertNotFound();
        $lead = $this->lead($this->manager);
        Outbox::publish('lead.created', ['leadId' => $lead->id, 'ownerId' => $this->manager->id, 'teamId' => $this->team1->id, 'createdById' => $this->manager->id, 'budgetUzs' => null], $this->manager->id);
        $r = $this->withHeader('x-cron-secret', 'cron-secret-for-tests-0123456789')->postJson('/hooks/cron')->assertOk()->json();
        $this->assertGreaterThan(0, $r['events']);
        $this->assertSame(0, OutboxEvent::whereNull('processed_at')->count());
        $this->assertGreaterThan(0, Notification::where('user_id', $this->ceo->id)->count());
        // Задачи планировщика отмечены в job_runs (слот выполнен один раз)
        $this->assertTrue(JobRun::where('job', 'smart-reminders')->exists());
        config(['fluggi.cron_secret' => null]);
        $this->withHeader('x-cron-secret', '')->postJson('/hooks/cron')->assertNotFound();
    }

    public function test_company_requisites_validation_and_audit(): void
    {
        $this->actingAsUser($this->ceo)->putJson('/settings/automation/company', ['name' => 'Fluggi', 'inn' => '12345', 'taxRegime' => 'IT_PARK'])->assertStatus(422);
        $this->putJson('/settings/automation/company', ['name' => 'ООО «Fluggi»', 'inn' => '123456789', 'director' => 'CEO', 'accountant' => 'Бухгалтер', 'taxRegime' => 'IT_PARK'])
            ->assertOk()->assertJsonPath('taxRegime', 'IT_PARK');
        $this->assertSame('ООО «Fluggi»', Settings::company()['name']);
        $this->assertTrue(AuditLog::where('action', 'settings.company')->exists());
        $this->actingAsUser($this->rop)->putJson('/settings/automation/company', ['taxRegime' => 'OTHER'])->assertForbidden();
        $this->ceo->update(['locale' => 'uz']);
        $this->actingAsUser($this->ceo)->get('/settings/automation')->assertOk()->assertSee(\App\Support\Lang::get('company.title', [], 'uz'));
    }
}
