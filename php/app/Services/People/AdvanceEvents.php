<?php

namespace App\Services\People;

use App\Models\EmployeeAdvance;
use App\Services\Notifications\NotificationEvents;
use App\Services\Notifications\Notifier;
use App\Support\Outbox;

/** Уведомления сотруднику об авансе (in-app и Telegram через Notifier): «Вам выдан аванс X» и отмена аванса. */
final class AdvanceEvents
{
    public static function register(): void
    {
        Outbox::on('payroll.advance_created', [self::class, 'created']);
        Outbox::on('payroll.advance_cancelled', [self::class, 'cancelled']);
    }

    private static function advance(?string $id): ?EmployeeAdvance
    {
        return $id ? EmployeeAdvance::find($id) : null;
    }

    public static function created(array $e, array $meta): void
    {
        $a = self::advance($e['advanceId'] ?? null);
        if (! $a || $a->cancelled_at) {
            return;
        }
        Notifier::notify(
            [$a->user_id],
            'payroll.advance',
            fn () => [t('payroll.advance.notify.created', ['amount' => NotificationEvents::uzs($a->amount)]), t('payroll.advance.notify.createdBody', ['period' => $a->period, 'date' => d($a->paid_at)])],
            '/team/employees/'.$a->user_id.'?period='.$a->period.'#salary',
            $meta['actorId'] ?? null,
        );
    }

    public static function cancelled(array $e, array $meta): void
    {
        $a = self::advance($e['advanceId'] ?? null);
        if (! $a) {
            return;
        }
        Notifier::notify(
            [$a->user_id],
            'payroll.advance_cancelled',
            fn () => [t('payroll.advance.notify.cancelled', ['amount' => NotificationEvents::uzs($a->amount)]), (string) $a->cancel_reason],
            '/team/employees/'.$a->user_id.'?period='.$a->period.'#salary',
            $meta['actorId'] ?? null,
        );
    }
}
