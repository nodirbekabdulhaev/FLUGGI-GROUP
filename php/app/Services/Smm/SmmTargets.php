<?php

namespace App\Services\Smm;

use App\Models\SmmIdea;
use App\Models\SmmIdeaHistory;
use App\Models\SmmPublication;
use App\Models\SmmPublicationTarget;
use App\Support\Audit;
use App\Support\Outbox;
use App\Support\Secrets;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Места автопубликации (Instagram, Telegram-каналы) — общее для всех мест:
 *  - захват запланированного места cron-ом, общий статус публикации по местам (settle);
 *  - опубликовано: место, идея «Опубликовано» при ПЕРВОМ успешном месте (дата публикации — один раз, для плана роликов),
 *    история, аудит, событие; файлы удаляются, когда опубликованы ВСЕ выбранные места;
 *  - ошибка: место FAILED с текстом, история, аудит, событие;
 *  - уборка: брошенные загрузки; файлы, когда все места опубликованы; у неудачных — через failed_keep_days дней.
 */
final class SmmTargets
{
    public const STATUSES = ['DRAFT', 'SCHEDULED', 'PUBLISHING', 'PUBLISHED', 'FAILED'];

    /** Места, пока они есть, файлы не удаляются. */
    public const ACTIVE = ['DRAFT', 'SCHEDULED', 'PUBLISHING'];

    /** Публикация, прерванная сбоем процесса (нет ответа от площадки), минут. */
    public const STALE_MINUTES = 40;

    public static function ts(\DateTimeInterface $at): string
    {
        return $at->format('Y-m-d H:i:s.v');
    }

    /** Общий статус по местам: идёт → запланировано → ошибка → черновик → опубликовано (все). */
    public static function aggregate(Collection $targets): string
    {
        $s = $targets->pluck('status');
        foreach (['PUBLISHING', 'SCHEDULED', 'FAILED', 'DRAFT'] as $status) {
            if ($s->contains($status)) {
                return $status;
            }
        }

        return $s->isEmpty() ? 'DRAFT' : 'PUBLISHED';
    }

    /** Пересчитать общий статус публикации. */
    public static function settle(SmmPublication $p): SmmPublication
    {
        $status = self::aggregate(SmmPublicationTarget::where('publication_id', $p->id)->get(['status']));
        if ($p->status !== $status) {
            $p->update(['status' => $status]);
        }

        return $p;
    }

    /** Наступило время: SCHEDULED → PUBLISHING (один запуск cron — одно место). */
    public static function claim(string $id): ?SmmPublicationTarget
    {
        $ok = SmmPublicationTarget::whereKey($id)->where('status', 'SCHEDULED')->where('scheduled_at', '<=', self::ts(now()))
            ->update(['status' => 'PUBLISHING', 'started_at' => now(), 'container_id' => null, 'next_check_at' => null, 'error' => null,
                'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]) === 1;
        if (! $ok) {
            return null;
        }
        $t = SmmPublicationTarget::with(['publication', 'channel'])->findOrFail($id);
        self::settle($t->publication);

        return $t;
    }

    /** Площадка попросила подождать (Telegram 429 retry_after): снова в расписание через $seconds секунд. */
    public static function postpone(SmmPublicationTarget $t, int $seconds, string $reason): void
    {
        $t->update(['status' => 'SCHEDULED', 'scheduled_at' => now()->addSeconds(max(1, $seconds)), 'started_at' => null, 'error' => mb_substr(Secrets::redact($reason), 0, 2000)]);
        self::settle($t->publication);
    }

    private static function prefix(SmmPublicationTarget $t): string
    {
        return $t->kind === 'INSTAGRAM' ? 'ig' : 'tg';
    }

    /** Опубликовано на месте $t. */
    public static function published(SmmPublicationTarget $t, ?string $externalId, ?string $permalink): void
    {
        $p = $t->publication;
        DB::transaction(function () use ($t, $p, $externalId, $permalink) {
            $now = now();
            $t->update(['status' => 'PUBLISHED', 'external_id' => $externalId, 'permalink' => $permalink, 'published_at' => $now,
                'error' => null, 'next_check_at' => null]);
            self::settle($p);
            $idea = SmmIdea::withTrashed()->lockForUpdate()->findOrFail($p->idea_id);
            $from = $idea->status;
            $data = [];
            // Первое успешное место: идея «Опубликовано», дата публикации — один раз (план роликов в зарплате)
            if ($from !== 'PUBLISHED') {
                $data['status'] = 'PUBLISHED';
                $data['published_at'] = $now;
            } elseif (! $idea->published_at) {
                $data['published_at'] = $now;
            }
            // Ссылка идеи — Instagram (основная площадка), иначе первая опубликованная
            if ($permalink && ($t->kind === 'INSTAGRAM' || ! $idea->publish_url)) {
                $data['publish_url'] = $permalink;
            }
            $oldUrl = $idea->publish_url;
            if ($data) {
                $idea->update($data);
            }
            // Первая успешная автопубликация — начисления за ролик видеографу и монтажёру
            if ($from !== 'PUBLISHED') {
                SmmVideoPay::sync($idea);
            }
            $changes = array_filter([
                'status' => $from !== 'PUBLISHED' ? ['old' => $from, 'new' => 'PUBLISHED'] : null,
                'publish_url' => isset($data['publish_url']) && $data['publish_url'] !== $oldUrl ? ['old' => $oldUrl, 'new' => $data['publish_url']] : null,
                'channel' => $t->kind === 'TELEGRAM' ? ['old' => null, 'new' => $t->channel?->title] : null,
            ]);
            $prefix = self::prefix($t);
            SmmIdeaHistory::create(['idea_id' => $idea->id, 'user_id' => null, 'action' => $prefix.'_published', 'changes' => $changes ?: null, 'created_at' => $now]);
            Audit::log('smm.'.$prefix.'_published', 'smm_publication', $p->id, $changes + ['external_id' => ['old' => null, 'new' => $externalId], 'target' => ['old' => null, 'new' => $t->label()]], null);
            if ($t->kind === 'INSTAGRAM') {
                Outbox::publish('smm.instagram_published', ['ideaId' => $idea->id, 'publicationId' => $p->id, 'targetId' => $t->id, 'mediaId' => $externalId, 'permalink' => $permalink, 'from' => $from], null);
            } else {
                Outbox::publish('smm.telegram_published', ['ideaId' => $idea->id, 'publicationId' => $p->id, 'targetId' => $t->id, 'channelId' => $t->channel_id,
                    'channelTitle' => $t->channel?->title, 'messageId' => $externalId, 'permalink' => $permalink, 'from' => $from], null);
            }
        });
        self::dropIfDone($p->fresh());
    }

    /** Ошибка на месте $t (текст площадки). */
    public static function failed(SmmPublicationTarget $t, string $error): void
    {
        $error = mb_substr(Secrets::redact($error), 0, 2000);
        DB::transaction(function () use ($t, $error) {
            $from = $t->status;
            $t->update(['status' => 'FAILED', 'error' => $error, 'failed_at' => now(), 'next_check_at' => null]);
            $p = self::settle($t->publication);
            $prefix = self::prefix($t);
            $changes = ['igStatus' => ['old' => $from, 'new' => 'FAILED']] + ($t->kind === 'TELEGRAM' ? ['channel' => ['old' => null, 'new' => $t->channel?->title]] : []);
            SmmIdeaHistory::create(['idea_id' => $p->idea_id, 'user_id' => null, 'action' => $prefix.'_failed', 'changes' => $changes, 'comment' => $error, 'created_at' => now()]);
            Audit::log('smm.'.$prefix.'_failed', 'smm_publication', $p->id, ['status' => ['old' => $from, 'new' => 'FAILED'],
                'target' => ['old' => null, 'new' => $t->label()], 'error' => ['old' => null, 'new' => mb_substr($error, 0, 500)]], null);
            $payload = ['ideaId' => $p->idea_id, 'publicationId' => $p->id, 'targetId' => $t->id, 'error' => mb_substr($error, 0, 500)];
            $t->kind === 'INSTAGRAM'
                ? Outbox::publish('smm.instagram_failed', $payload, null)
                : Outbox::publish('smm.telegram_failed', $payload + ['channelId' => $t->channel_id, 'channelTitle' => $t->channel?->title], null);
        });
    }

    /** Все выбранные места опубликованы → файлы больше не нужны. */
    public static function allPublished(SmmPublication $p): bool
    {
        $s = SmmPublicationTarget::where('publication_id', $p->id)->pluck('status');

        return $s->isNotEmpty() && $s->every(fn ($v) => $v === 'PUBLISHED');
    }

    public static function dropIfDone(SmmPublication $p): void
    {
        if (self::hasFiles($p) && self::allPublished($p)) {
            self::dropFiles($p);
        }
    }

    public static function hasFiles(SmmPublication $p): bool
    {
        return (bool) ($p->media_path || $p->cover_path || $p->tg_media_path);
    }

    /** Удалить файлы публикации, в базе — только метаданные и отметка «файл удалён». */
    public static function dropFiles(SmmPublication $p): void
    {
        foreach ([$p->media_path, $p->cover_path, $p->tg_media_path] as $path) {
            SmmMedia::delete($path);
        }
        $p->update(['media_path' => null, 'cover_path' => null, 'tg_media_path' => null, 'file_deleted_at' => now()]);
    }

    /** Место, прерванное сбоем процесса: нет контейнера Instagram / ответа Telegram дольше STALE_MINUTES. */
    public static function failStale(): int
    {
        $n = 0;
        $old = self::ts(now()->subMinutes(self::STALE_MINUTES));
        $stale = SmmPublicationTarget::with(['publication', 'channel'])->where('status', 'PUBLISHING')->where('started_at', '<', $old)
            ->where(fn ($q) => $q->where('kind', 'TELEGRAM')->orWhereNull('container_id'))->limit(50)->get();
        foreach ($stale as $t) {
            self::failed($t, t('smm.ig.errors.interrupted'));
            $n++;
        }

        return $n;
    }

    /**
     * Брошенные загрузки; файлы публикаций, где ни одно место уже не ждёт публикации:
     * все опубликованы — сразу, есть неудачные — через failed_keep_days дней после последней ошибки.
     */
    public static function cleanup(): int
    {
        $n = SmmMedia::cleanupChunks();
        $cutoff = self::ts(now()->subDays((int) config('fluggi.smm_media.failed_keep_days', 7)));
        $old = SmmPublication::where(fn ($q) => $q->whereNotNull('media_path')->orWhereNotNull('cover_path')->orWhereNotNull('tg_media_path'))
            ->whereHas('targets')
            ->whereDoesntHave('targets', fn ($q) => $q->whereIn('status', self::ACTIVE))
            ->whereDoesntHave('targets', fn ($q) => $q->where('status', 'FAILED')->where('failed_at', '>=', $cutoff))
            ->limit(100)->get();
        foreach ($old as $p) {
            $failed = SmmPublicationTarget::where('publication_id', $p->id)->where('status', 'FAILED')->exists();
            self::dropFiles($p);
            if ($failed) {
                SmmIdeaHistory::create(['idea_id' => $p->idea_id, 'user_id' => null, 'action' => 'ig_file_deleted', 'created_at' => now()]);
                Audit::log('smm.ig_file_deleted', 'smm_publication', $p->id, ['file' => ['old' => $p->media_name, 'new' => null]], null);
            }
            $n++;
        }

        return $n;
    }
}
