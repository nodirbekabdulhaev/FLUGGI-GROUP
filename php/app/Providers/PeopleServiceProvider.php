<?php

namespace App\Providers;

use App\Services\People\AdvanceEvents;
use App\Services\People\AttendanceCheckout;
use App\Services\Telegram\TelegramService;
use Illuminate\Support\ServiceProvider;

/** Посещаемость: уведомления о незакрытых днях (подписчики outbox) и кнопка «Утвердить» в Telegram; уведомления об авансах. */
class PeopleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        AttendanceCheckout::register();
        AdvanceEvents::register();
        TelegramService::onCallback(AttendanceCheckout::TELEGRAM_PREFIX, [AttendanceCheckout::class, 'telegramCallback']);
    }
}
