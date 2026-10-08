<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\NotificationService;
use App\Services\ReportService;

class SendDailyReport extends Command
{
    use RunsPerOrganization;

    protected $signature = 'report:daily';
    protected $description = 'Дневной отчёт руководителю (cron 21:00)';

    public function handle(): int
    {
        return $this->eachOrganization(function () {
            $svc = app(NotificationService::class);
            $d = app(ReportService::class)->dailyDigest(now());
            $svc->toStaff('reports.finance', 'system', 'Дневной отчёт', $svc->render('daily_report', $d), '/dashboard', null, 'daily:'.now()->toDateString());
        });
    }
}
