<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SetTelegramWebhook extends Command
{
    protected $signature = 'telegram:webhook {--delete : удалить webhook}';
    protected $description = 'Зарегистрировать webhook бота (нужны TELEGRAM_BOT_TOKEN и TELEGRAM_WEBHOOK_SECRET)';

    public function handle(): int
    {
        $token = config('services.telegram.bot_token');
        $secret = config('services.telegram.webhook_secret');
        if (! $token || ! $secret) {
            $this->error('Задайте TELEGRAM_BOT_TOKEN и TELEGRAM_WEBHOOK_SECRET в .env');

            return self::FAILURE;
        }
        $res = $this->option('delete')
            ? Http::asForm()->post("https://api.telegram.org/bot$token/deleteWebhook")
            : Http::asForm()->post("https://api.telegram.org/bot$token/setWebhook", ['url' => route('telegram.webhook', $secret), 'allowed_updates' => json_encode(['message'])]);
        $this->line($res->body());

        return $res->successful() ? self::SUCCESS : self::FAILURE;
    }
}
