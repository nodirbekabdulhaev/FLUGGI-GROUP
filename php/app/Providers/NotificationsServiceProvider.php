<?php

namespace App\Providers;

use App\Services\Automation\AutomationEvents;
use App\Services\Notifications\NotificationEvents;
use Illuminate\Support\ServiceProvider;

/**
 * Модуль «Уведомления, Telegram, автоматизация»: подписчики outbox-событий
 * (уведомления in-app/Telegram, follow-up после проекта, достижение плана).
 */
class NotificationsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        NotificationEvents::register();
        AutomationEvents::register();
    }
}
