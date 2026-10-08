<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\AttendanceService;

class SendLessonReminders extends Command
{
    use RunsPerOrganization;

    protected $signature = 'reminders:lessons';
    protected $description = 'Напоминания о занятиях на сегодня (cron 08:00)';

    public function handle(): int
    {
        return $this->eachOrganization(fn () => $this->info('queued: '.app(AttendanceService::class)->remindToday()));
    }
}
