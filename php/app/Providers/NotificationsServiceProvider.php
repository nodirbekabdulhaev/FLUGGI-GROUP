<?php

namespace App\Providers;

use App\Services\Automation\AutomationEvents;
use App\Services\Notifications\NotificationEvents;
use App\Support\Db;
use Illuminate\Database\Connection;
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
        // Outbox::processBatch() вызывает DB::lockRows() (фасад DB перекрывает App\Support\Db):
        // макрос соединения направляет вызов в App\Support\Db, иначе очередь событий не разбирается
        if (! Connection::hasMacro('lockRows')) {
            Connection::macro('lockRows', fn () => Db::lockRows());
        }
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
