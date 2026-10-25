<?php

namespace App\Services\Smm;

use App\Models\SmmIdea;
use App\Services\Notifications\Notifier;
use App\Support\Outbox;

/**
 * Подписчики событий SMM → уведомления (in-app и Telegram через Notifier, с учётом личных настроек):
 *  - новая идея (и повторная отправка) — проект-менеджерам SMM и CEO на утверждение;
 *  - утверждена / отклонена — ответственному и автору;
 *  - смена этапа или ответственного другим сотрудником — ответственному и автору;
 *  - назначен видеограф — видеографу;
 *  - опубликовано в Instagram (автопубликация) — автору, ответственному, видеографу и проект-менеджерам SMM;
 *  - автопубликация не удалась — автору и ответственному (с текстом ошибки Meta);
 *  - опубликовано в Telegram-канале / не удалось — так же, как для Instagram (с названием канала).
 */
final class SmmEvents
{
    public const TYPES = ['smm.idea_created', 'smm.idea_approved', 'smm.idea_rejected', 'smm.idea_status_changed', 'smm.videographer_assigned',
        'smm.instagram_published', 'smm.instagram_failed', 'smm.telegram_published', 'smm.telegram_failed'];

    public static function register(): void
    {
        Outbox::on('smm.idea_created', [self::class, 'created']);
        Outbox::on('smm.idea_approved', [self::class, 'approved']);
        Outbox::on('smm.idea_rejected', [self::class, 'rejected']);
        Outbox::on('smm.idea_status_changed', [self::class, 'statusChanged']);
        Outbox::on('smm.videographer_assigned', [self::class, 'videographerAssigned']);
        Outbox::on('smm.instagram_published', [self::class, 'instagramPublished']);
        Outbox::on('smm.instagram_failed', [self::class, 'instagramFailed']);
        Outbox::on('smm.telegram_published', [self::class, 'telegramPublished']);
        Outbox::on('smm.telegram_failed', [self::class, 'telegramFailed']);
    }

    public static function telegramPublished(array $e, array $meta): void
    {
        $idea = self::idea($e['ideaId'] ?? null);
        if (! $idea) {
            return;
        }
        Notifier::notify(
            [$idea->author_id, $idea->assignee_id, $idea->videographer_id, ...SmmAccess::projectManagerIds()],
            'smm.telegram_published',
            fn () => [t('smm.notify.tgPublished', ['channel' => $e['channelTitle'] ?? '—']), self::subject($idea)],
            self::link($idea),
            $meta['actorId'],
        );
    }

    public static function telegramFailed(array $e, array $meta): void
    {
        $idea = self::idea($e['ideaId'] ?? null);
        if (! $idea) {
            return;
        }
        Notifier::notify(
            [$idea->author_id, $idea->assignee_id],
            'smm.telegram_failed',
            fn () => [t('smm.notify.tgFailed', ['channel' => $e['channelTitle'] ?? '—']), self::subject($idea).' — '.($e['error'] ?? '')],
            self::link($idea),
            $meta['actorId'],
        );
    }

    public static function instagramPublished(array $e, array $meta): void
    {
        $idea = self::idea($e['ideaId'] ?? null);
        if (! $idea) {
            return;
        }
        Notifier::notify(
            [$idea->author_id, $idea->assignee_id, $idea->videographer_id, ...SmmAccess::projectManagerIds()],
            'smm.instagram_published',
            fn () => [t('smm.notify.igPublished'), self::subject($idea)],
            self::link($idea),
            $meta['actorId'],
        );
    }

    public static function instagramFailed(array $e, array $meta): void
    {
        $idea = self::idea($e['ideaId'] ?? null);
        if (! $idea) {
            return;
        }
        Notifier::notify(
            [$idea->author_id, $idea->assignee_id],
            'smm.instagram_failed',
            fn () => [t('smm.notify.igFailed'), self::subject($idea).' — '.($e['error'] ?? '')],
            self::link($idea),
            $meta['actorId'],
        );
    }

    public static function videographerAssigned(array $e, array $meta): void
    {
        $idea = self::idea($e['ideaId'] ?? null);
        if (! $idea || $idea->trashed() || $idea->videographer_id !== ($e['videographerId'] ?? null)) {
            return;
        }
        Notifier::notify([$idea->videographer_id], 'smm.videographer_assigned', fn () => [t('smm.notify.videographerAssigned'), self::subject($idea)], self::link($idea), $meta['actorId']);
    }

    private static function idea(?string $id): ?SmmIdea
    {
        return $id ? SmmIdea::withTrashed()->find($id) : null;
    }

    private static function link(SmmIdea $idea): string
    {
        return '/smm/'.$idea->id;
    }

    private static function subject(SmmIdea $idea): string
    {
        return SmmIdeaService::code($idea).' · '.$idea->title;
    }

    /** Утверждающие: проект-менеджеры SMM (направление «Медиа», иначе все) и CEO. */
    public static function approverIds(): array
    {
        return array_values(array_unique([...SmmAccess::projectManagerIds(), ...Notifier::ceoIds()]));
    }

    public static function created(array $e, array $meta): void
    {
        $idea = self::idea($e['ideaId'] ?? null);
        if (! $idea || $idea->trashed() || $idea->status !== 'IDEA') {
            return;
        }
        Notifier::notify(self::approverIds(), 'smm.idea_created', fn () => [t('smm.notify.created'), self::subject($idea)], self::link($idea), $meta['actorId']);
    }

    public static function approved(array $e, array $meta): void
    {
        $idea = self::idea($e['ideaId'] ?? null);
        if (! $idea || $idea->trashed()) {
            return;
        }
        Notifier::notify([$idea->assignee_id, $idea->author_id], 'smm.idea_approved', fn () => [t('smm.notify.approved'), self::subject($idea)], self::link($idea), $meta['actorId']);
    }

    public static function rejected(array $e, array $meta): void
    {
        $idea = self::idea($e['ideaId'] ?? null);
        if (! $idea || $idea->trashed()) {
            return;
        }
        Notifier::notify(
            [$idea->assignee_id, $idea->author_id],
            'smm.idea_rejected',
            fn () => [t('smm.notify.rejected'), self::subject($idea).' — '.($e['reason'] ?? '')],
            self::link($idea),
            $meta['actorId'],
        );
    }

    public static function statusChanged(array $e, array $meta): void
    {
        $idea = self::idea($e['ideaId'] ?? null);
        if (! $idea || $idea->trashed()) {
            return;
        }
        // Отклонённую идею отправили заново — снова на утверждение
        if (($e['to'] ?? null) === 'IDEA' && ($e['from'] ?? null) === 'REJECTED') {
            Notifier::notify(self::approverIds(), 'smm.idea_created', fn () => [t('smm.notify.resubmitted'), self::subject($idea)], self::link($idea), $meta['actorId']);

            return;
        }
        $to = (string) ($e['to'] ?? '');
        Notifier::notify(
            [$idea->assignee_id, $idea->author_id],
            'smm.idea_status_changed',
            fn () => ! empty($e['assigned'])
                ? [t('smm.notify.assigned'), self::subject($idea)]
                : [t('smm.notify.moved', ['status' => t('smm.status.'.$to)]), self::subject($idea)],
            self::link($idea),
            $meta['actorId'],
        );
    }
}
