<?php

namespace Tests\Feature\Import;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Comment;
use App\Models\Deal;
use App\Models\OutboxEvent;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Service;
use App\Models\Task;
use App\Services\Crm\Crm;
use App\Support\Format;
use Carbon\CarbonImmutable;

class ProjectAttendanceImportTest extends ImportTestCase
{
    private const PROJECT_HEADER = ['Название *', 'Клиент *', 'Сделка', 'РОП (email) *', 'Менеджер (email)', 'Направление', 'Услуга', 'Стоимость', 'Валюта', 'Дата старта', 'Дедлайн', 'Статус проекта', 'Описание'];

    public function test_projects_create_client_and_won_deal_without_payments(): void
    {
        $paymentsBefore = Payment::count();
        $file = fn () => $this->xlsx([
            self::PROJECT_HEADER,
            ['Brand site', 'Brand LLC', null, $this->rop->email, $this->manager->email, 'IT', 'SMM', '2 000', 'USD', '01.09.2026', '30.11.2026', 'В работе', 'Legacy'],
            ['No price', 'Brand LLC', null, $this->rop->email, null, null, null, null, null, null, null, null, null],
            ['Wrong ROP', 'Brand LLC', null, $this->manager->email, null, null, null, '100', null, null, null, null, null],
            ['Bad dates', 'Brand LLC', null, $this->rop->email, null, null, null, '100', null, '10.10.2026', '01.10.2026', null, null],
        ]);
        $token = $this->previewToken($this->ceo, 'projects', $file(), ['create_clients', 'create_tasks']);
        $p = $this->preview($this->ceo, 'projects', $token);
        $this->assertSame('create', $p[2]['status']);
        $this->assertStringContainsString('Brand LLC', $p[2]['reason']);
        $this->assertSame(t('import.errors.priceRequired'), $p[3]['reason']);
        $this->assertSame(t('projects.errors.ropRole'), $p[4]['reason']);
        $this->assertSame(t('projects.errors.deadlineBeforeStart'), $p[5]['reason']);

        $rows = $this->confirm($this->ceo, 'projects', $token);
        $project = Project::where('name', 'Brand site')->firstOrFail();
        $this->assertSame($rows[2]['code'], Format::code('P', $project->number));
        $client = Client::where('name', 'Brand LLC')->firstOrFail();
        $deal = Deal::findOrFail($project->deal_id);
        $this->assertSame($client->id, $project->client_id);
        $this->assertSame($client->id, $deal->client_id);
        $this->assertSame('WON', $deal->status);
        $this->assertSame('PAID', $deal->stage->code);
        $this->assertSame('2000.00', (string) $deal->amount);
        $this->assertSame('25300000.00', (string) $project->price_uzs);
        $this->assertSame('IN_PROGRESS', $project->status);
        $this->assertSame($this->rop->id, $project->rop_id);
        $this->assertSame($this->manager->id, $project->manager_id);
        $this->assertSame($this->team1->id, $project->team_id);
        $this->assertSame('IT', $project->direction->code);
        $this->assertSame('2026-09-01', $project->start_date->format('Y-m-d'));
        $this->assertSame($paymentsBefore, Payment::count(), 'оплаты не создаются');
        $this->assertTrue(Comment::where('deal_id', $deal->id)->where('body', t('import.projects.dealComment'))->exists());
        $this->assertTrue(AuditLog::where('action', 'project.create')->where('entity_id', $project->id)->exists());
        $this->assertTrue(AuditLog::where('action', 'deal.import_won')->where('entity_id', $deal->id)->exists());
        $this->assertTrue(OutboxEvent::where('type', 'project.created')->where('payload->projectId', $project->id)->exists());
        $this->assertGreaterThan(0, Task::where('project_id', $project->id)->count(), 'задачи по шаблону услуги SMM');

        // Повторный импорт того же файла — проект уже есть
        $p = $this->preview($this->ceo, 'projects', $this->previewToken($this->ceo, 'projects', $file(), ['create_clients']));
        $this->assertSame('duplicate', $p[2]['status']);
        $this->assertStringContainsString(Format::code('P', $project->number), $p[2]['reason']);
    }

    public function test_project_for_existing_deal_one_per_deal_and_no_tasks_without_checkbox(): void
    {
        $client = Client::create(['name' => 'Deal Co', 'owner_id' => $this->manager->id, 'team_id' => $this->team1->id])->refresh();
        $stage = Crm::stage('PAID');
        $won = Deal::create(['title' => 'Won', 'client_id' => $client->id, 'owner_id' => $this->manager->id, 'team_id' => $this->team1->id, 'service_id' => $this->ref(Service::class, 'SMM'),
            'amount' => '5000000', 'currency' => 'UZS', 'exchange_rate' => '1', 'amount_uzs' => '5000000', 'stage_id' => $stage->id, 'status' => 'WON', 'created_by_id' => $this->ceo->id])->refresh();
        $open = Deal::create(['title' => 'Open', 'client_id' => $client->id, 'owner_id' => $this->manager->id, 'team_id' => $this->team1->id,
            'amount' => '1', 'currency' => 'UZS', 'exchange_rate' => '1', 'amount_uzs' => '1', 'stage_id' => Crm::stage('NEED_DEFINED')->id, 'created_by_id' => $this->ceo->id])->refresh();
        $file = $this->xlsx([
            self::PROJECT_HEADER,
            ['P1', 'Deal Co', Format::code('D', $won->number), $this->rop->email, null, null, null, null, null, null, null, null, null],
            ['P2', 'Deal Co', Format::code('D', $won->number), $this->rop->email, null, null, null, null, null, null, null, null, null],
            ['P3', 'Deal Co', Format::code('D', $open->number), $this->rop->email, null, null, null, null, null, null, null, null, null],
            ['P4', 'Other', Format::code('D', $won->number), $this->rop->email, null, null, null, null, null, null, null, null, null],
        ]);
        // РОП может назначить только себя
        $token = $this->previewToken($this->rop, 'projects', $file);
        $p = $this->preview($this->rop, 'projects', $token);
        $this->assertSame('create', $p[2]['status']);
        $this->assertSame('duplicate', $p[3]['status']);
        $this->assertStringContainsString(t('import.errors.dealNotWon', ['deal' => Format::code('D', $open->number)]), $p[4]['reason']);
        $this->assertSame('error', $p[5]['status']);
        $this->confirm($this->rop, 'projects', $token);
        $project = Project::where('deal_id', $won->id)->firstOrFail();
        $this->assertSame('5000000.00', (string) $project->price_uzs);
        $this->assertSame($this->manager->id, $project->manager_id);
        $this->assertSame(0, Task::where('project_id', $project->id)->count());
        $this->assertSame(1, Project::where('deal_id', $won->id)->count());

        $other = $this->xlsx([self::PROJECT_HEADER, ['X', 'Deal Co', null, $this->ceo->email, null, null, null, '100', null, null, null, null, null]]);
        $p = $this->preview($this->rop, 'projects', $this->previewToken($this->rop, 'projects', $other));
        $this->assertStringContainsString($this->ceo->email, $p[2]['reason'], 'РОП не назначает другого РОП');
    }

    private function lastWorkday(): string
    {
        $d = CarbonImmutable::now(Format::tz())->startOfDay()->subDay();
        while ($d->isoWeekday() > 5) {
            $d = $d->subDay();
        }

        return $d->toDateString();
    }

    public function test_attendance_only_managers_and_rop_with_late_and_work_minutes(): void
    {
        $date = $this->lastWorkday();
        Attendance::create(['user_id' => $this->rop->id, 'date' => $date, 'status' => 'ABSENT']);
        $future = Format::today()->addDays(2)->toDateString();
        $file = $this->xlsx([
            ['Сотрудник *', 'Дата *', 'Статус', 'Приход', 'Уход', 'Комментарий'],
            [$this->manager->email, ['date' => $date], null, '09:30', '18:00', 'import'],
            ['manager one', $date, 'Присутствовал', '09:00', '18:00', null],
            [$this->rop->full_name, $date, 'Taʼtil', null, null, null],
            [$this->executor->email, $date, 'PRESENT', '09:00', '18:00', null],
            [$this->otherManager->email, $future, 'PRESENT', '09:00', '18:00', null],
            [$this->otherManager->email, $date, 'PRESENT', '18:00', '09:00', null],
            [$this->otherManager->email, $date, 'Sick leave', null, null, null],
        ]);
        $token = $this->previewToken($this->hr, 'attendance', $file);
        $p = $this->preview($this->hr, 'attendance', $token);
        $this->assertSame('create', $p[2]['status']);
        $this->assertSame('duplicate', $p[3]['status'], 'тот же сотрудник и день');
        $this->assertSame('update', $p[4]['status']);
        $this->assertSame(t('attendance.errors.notTrackedUser'), $p[5]['reason']);
        $this->assertSame(t('import.errors.futureDate'), $p[6]['reason']);
        $this->assertSame(t('attendance.errors.outBeforeIn'), $p[7]['reason']);
        $this->assertSame('create', $p[8]['status']);

        $rows = $this->confirm($this->hr, 'attendance', $token);
        $this->assertSame(['created', 'updated', 'created'], [$rows[2]['status'], $rows[4]['status'], $rows[8]['status']]);
        $m = Attendance::where('user_id', $this->manager->id)->whereDate('date', $date)->firstOrFail();
        $this->assertSame('LATE', $m->status, 'опоздание по графику 09:00 + 10 мин');
        $this->assertSame(30, (int) $m->late_minutes);
        $this->assertSame(510, (int) $m->work_minutes);
        $this->assertSame('04:30', $m->check_in->format('H:i'), 'в базе UTC');
        $this->assertSame('VACATION', Attendance::where('user_id', $this->rop->id)->whereDate('date', $date)->value('status'));
        $this->assertSame('SICK', Attendance::where('user_id', $this->otherManager->id)->whereDate('date', $date)->value('status'));
        $this->assertFalse(Attendance::where('user_id', $this->executor->id)->exists());
        $this->assertSame(3, AuditLog::where('action', 'attendance.update')->count());
    }
}
