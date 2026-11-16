<?php

namespace App\Services\Finance;

use App\Models\PartnerPayout;
use App\Services\Notifications\NotificationEvents;
use App\Services\Notifications\Notifier;
use App\Support\Format;
use App\Support\Outbox;

/**
 * Подписчики событий выплат партнёру → уведомления (in-app и Telegram через Notifier):
 *  - новая заявка — CEO, которые могут её утвердить (все CEO, кроме партнёра);
 *  - решение (утверждено / отклонено) — партнёру и автору заявки.
 */
final class PartnerEvents
{
    public const TYPES = ['partner.payout_requested', 'partner.payout_decided'];

    private const LINK = '/finance/partner';

    public static function register(): void
    {
        Outbox::on('partner.payout_requested', [self::class, 'requested']);
        Outbox::on('partner.payout_decided', [self::class, 'decided']);
    }

    private static function payout(?string $id): ?PartnerPayout
    {
        return $id ? PartnerPayout::with(['project', 'partner:id,full_name'])->find($id) : null;
    }

    private static function subject(PartnerPayout $p): string
    {
        return ($p->project ? Format::code('P', $p->project->number).' '.$p->project->name : '').' — '.NotificationEvents::uzs($p->amount_uzs);
    }

    public static function requested(array $e, array $meta): void
    {
        $p = self::payout($e['payoutId'] ?? null);
        if (! $p || $p->status !== 'REQUESTED') {
            return;
        }
        $deciders = array_values(array_filter(Notifier::ceoIds(), fn ($id) => $id !== $p->partner_id));
        Notifier::notify(
            $deciders,
            'partner.payout_requested',
            fn () => [t('partner.notify.requested', ['name' => $p->partner?->full_name ?? '']), self::subject($p)],
            self::LINK,
            $meta['actorId'],
        );
    }

    public static function decided(array $e, array $meta): void
    {
        $p = self::payout($e['payoutId'] ?? null);
        if (! $p) {
            return;
        }
        $approved = ($e['status'] ?? $p->status) === 'APPROVED';
        Notifier::notify(
            [$p->partner_id, $p->requested_by],
            'partner.payout_decided',
            fn () => [
                t($approved ? 'partner.notify.approved' : 'partner.notify.rejected'),
                self::subject($p).($approved ? '' : ' · '.($p->reject_reason ?? '')),
            ],
            self::LINK,
            $meta['actorId'],
        );
    }
}
