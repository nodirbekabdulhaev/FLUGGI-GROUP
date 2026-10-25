<?php

use App\Services\Automation\Cron;
use App\Services\Smm\SmmPublisher;
use App\Support\Outbox;
use Illuminate\Support\Facades\Schedule;

/*
 * Фоновые задачи. На хостинге cron раз в минуту запускает `php artisan schedule:run`.
 * Задачи модулей регистрируются здесь же (каждая — с withoutOverlapping, чтобы два запуска cron
 * не выполняли одну работу дважды; повторы по слотам — таблица job_runs).
 */
Schedule::call(fn () => Outbox::drain(40))
    ->name('outbox')->everyMinute()->withoutOverlapping(5);

// Очередь Telegram (повторы с паузой), проверка бота и планировщик модулей (ТЗ §55): просроченные
// задачи, напоминания, follow-up, отчёты, отсутствующие, регулярные дела, здоровье клиентов, зарплата.
// Каждая задача планировщика выполняется один раз за свой слот (job_runs).
Schedule::call(fn () => Cron::comms(15))
    ->name('comms')->everyMinute()->withoutOverlapping(10);

// Автопубликация SMM в Instagram и Telegram-каналы: запланированные на эту минуту, проверка статуса контейнеров Meta,
// уборка файлов (части брошенных загрузок; файлы — когда опубликованы все места, у неудачных — через 7 дней).
// Загрузка большого видео в Meta/Telegram может занять минуты — отдельная задача, чтобы не задерживать уведомления.
Schedule::call(fn () => SmmPublisher::tick())
    ->name('smm-instagram')->everyMinute()->withoutOverlapping(60);
