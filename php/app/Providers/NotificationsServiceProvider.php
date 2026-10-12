<?php

namespace App\Providers;

use App\Services\Automation\AutomationEvents;
use App\Services\Notifications\NotificationEvents;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Event;
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

        // Публичная форма заявки f/{key} встраивается на сайт через iframe (embed.js):
        // общий запрет X-Frame-Options снимаем только для неё
        Event::listen(RequestHandled::class, function (RequestHandled $e) {
            if ($e->request->is('f/*')) {
                $e->response->headers->remove('X-Frame-Options');
                $e->response->headers->set('Content-Security-Policy', 'frame-ancestors *');
            }
        });
    }
}
