<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ScheduleService;

class ExtendLessons extends Command
{
    use RunsPerOrganization;

    protected $signature = 'lessons:extend';
    protected $description = 'Генерация занятий вперёд по активным расписаниям (cron ночью)';

    public function handle(): int
    {
        return $this->eachOrganization(fn () => $this->info('lessons created: '.app(ScheduleService::class)->extendAll()));
    }
}
