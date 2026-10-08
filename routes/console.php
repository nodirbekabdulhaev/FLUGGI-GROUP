<?php

use Illuminate\Support\Facades\Schedule;

/*
| Beget cron (one line, every minute):
|   * * * * * /usr/local/bin/php8.2 /home/USER/erp/artisan schedule:run >> /dev/null 2>&1
*/
Schedule::command('notifications:process')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::command('reminders:lessons')->dailyAt('08:00');
Schedule::command('debts:check')->dailyAt('20:00');
Schedule::command('report:daily')->dailyAt('21:00');
Schedule::command('lessons:extend')->dailyAt('02:00');
Schedule::command('backup:run')->dailyAt('03:00');
Schedule::command('salary:accrue')->monthlyOn(1, '06:00');
Schedule::command('housekeeping:run')->weeklyOn(0, '04:00');
