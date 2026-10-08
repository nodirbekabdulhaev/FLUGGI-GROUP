<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\TelegramService;

class ProcessNotifications extends Command
{
    use RunsPerOrganization;

    protected $signature = 'notifications:process';
    protected $description = 'Отправка очереди Telegram-уведомлений (cron каждые 5 минут)';

    public function handle(): int
    {
        return $this->eachOrganization(function () {
            [$sent, $failed] = app(TelegramService::class)->processQueue();
            $this->info("sent=$sent failed=$failed");
        });
    }
}
