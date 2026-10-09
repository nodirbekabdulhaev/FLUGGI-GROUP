<?php

use App\Support\Outbox;
use Illuminate\Support\Facades\Schedule;

/*
 * Фоновые задачи. На хостинге cron раз в минуту запускает `php artisan schedule:run`.
 * Задачи модулей регистрируются здесь же (каждая — с withoutOverlapping, чтобы два запуска cron
 * не выполняли одну работу дважды; повторы по слотам — таблица job_runs).
 */
Schedule::call(fn () => Outbox::drain(40))
    ->name('outbox')->everyMinute()->withoutOverlapping(5);
