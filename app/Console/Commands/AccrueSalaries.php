<?php

namespace App\Console\Commands;

use App\Services\SalaryService;
use Illuminate\Console\Command;

class AccrueSalaries extends Command
{
    use RunsPerOrganization;

    protected $signature = 'salary:accrue {period? : ГГГГ-ММ (по умолчанию прошлый месяц)}';
    protected $description = 'Начисление зарплат за период (cron 1-го числа)';

    public function handle(): int
    {
        $period = $this->argument('period') ?: now()->subMonthNoOverflow()->format('Y-m');

        return $this->eachOrganization(fn () => $this->info(app(SalaryService::class)->accrue($period)->count()." accruals for $period"));
    }
}
