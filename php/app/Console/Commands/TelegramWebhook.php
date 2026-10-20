<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Подключение Telegram-бота в режиме webhook (на хостинге нет постоянных процессов):
 * php artisan fluggi:telegram-webhook — установить адрес {APP_URL}/hooks/telegram с секретом;
 * --info — состояние; --delete — отключить.
 */
class TelegramWebhook extends Command
{
    protected $signature = 'fluggi:telegram-webhook {--info : Показать состояние webhook} {--delete : Удалить webhook}';

    protected $description = 'Установить (или проверить) webhook Telegram-бота: {APP_URL}/hooks/telegram';

    public function handle(): int
    {
        if (! TelegramClient::configured()) {
            $this->error('TELEGRAM_BOT_TOKEN не задан в .env');

            return self::FAILURE;
        }
        try {
            if ($this->option('delete')) {
                TelegramClient::deleteWebhook();
                $this->info('Webhook удалён');

                return self::SUCCESS;
            }
            if (! $this->option('info')) {
                $secret = (string) config('fluggi.telegram.webhook_secret');
                if (strlen($secret) < 16 || ! preg_match('/^[A-Za-z0-9_-]+$/', $secret)) {
                    $this->error('TELEGRAM_WEBHOOK_SECRET: не короче 16 символов, только A-Z a-z 0-9 _ -');

                    return self::FAILURE;
                }
                $url = TelegramService::webhookUrl();
                if (! str_starts_with($url, 'https://')) {
                    $this->error('Telegram принимает только HTTPS: проверьте APP_URL ('.$url.')');

                    return self::FAILURE;
                }
                TelegramClient::setWebhook($url, $secret);
                $this->info('Webhook установлен: '.$url);
            }
            $info = TelegramClient::getWebhookInfo();
            $this->line('URL: '.($info['url'] ?? '—'));
            $this->line('Ожидают доставки: '.($info['pending_update_count'] ?? 0));
            if (! empty($info['last_error_message'])) {
                $this->warn('Последняя ошибка: '.$info['last_error_message']);
            }
            $ok = TelegramService::check();
            $this->line($ok ? 'Бот работает: @'.TelegramService::username() : 'Проблема: '.(TelegramService::health()['problem'] ?? ''));

            return $ok ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            $this->error(TelegramService::explain($e));

            return self::FAILURE;
        }
    }
}
