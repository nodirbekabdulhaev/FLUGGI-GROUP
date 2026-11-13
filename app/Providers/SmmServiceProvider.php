<?php

namespace App\Providers;

use App\Services\Smm\SmmEvents;
use Illuminate\Support\ServiceProvider;

/** Модуль «SMM» (собственный видеоконтент): уведомления об идеях роликов — подписчики outbox. */
class SmmServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        SmmEvents::register();
    }
}
