<?php

namespace App\Providers;

use App\Services\Accounting\CrmImportService;
use App\Support\Outbox;
use Illuminate\Support\ServiceProvider;

/**
 * Бухгалтерия: подтверждённые оплаты клиентов и возвраты из CRM попадают в операции счёта,
 * у которого отмечен способ оплаты (подписчик outbox, доставка — cron).
 */
class AccountingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach (['payment.paid', 'payment.refunded'] as $type) {
            Outbox::on($type, function (array $payload, array $meta) {
                if (! empty($payload['paymentId'])) {
                    CrmImportService::import($payload['paymentId'], $meta['actorId'] ?? null);
                }
            });
        }
    }
}
