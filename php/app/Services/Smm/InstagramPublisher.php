<?php

namespace App\Services\Smm;

use App\Models\SmmIdea;
use App\Models\SmmIdeaHistory;
use App\Models\SmmPublication;
use App\Support\Audit;
use App\Support\Outbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Публикация в Instagram по расписанию — cron раз в минуту, без долгого ожидания в одном запуске:
 *  1) наступило время SCHEDULED → PUBLISHING: проверка лимита публикаций, контейнер
 *     (Reels — загрузка исходного файла, фото — image_url на временную подписанную ссылку);
 *  2) PUBLISHING: статус контейнера (FINISHED / IN_PROGRESS / ERROR) в следующих запусках;
 *     FINISHED → media_publish → permalink → идея «Опубликовано» (дата публикации — для плана роликов в зарплате);
 *  3) ошибка Meta → FAILED с текстом Meta, автору уведомление; повтор — кнопкой в карточке.
 * Файл удаляется сразу после публикации (в базе остаются имя, размер, id поста и ссылка).
 * Уборка: брошенные части загрузок и файлы неудавшихся публикаций старше failed_keep_days дней.
 */
final class InstagramPublisher
{
    /** Пауза между проверками статуса контейнера, секунд. */
    public const CHECK_EVERY = 30;

    /** Контейнер, который Meta обрабатывает дольше, считается неудачным, часов. */
    public const MAX_PROCESSING_HOURS = 24;

    /** Публикация, прерванная до создания контейнера (сбой процесса), минут. */
    public const STALE_MINUTES = 40;

    /** Временная ссылка на файл для Meta, часов. */
    public const LINK_HOURS = 6;

    public static function tick(int $limit = 5): array
    {
        $started = 0;
        $checked = 0;
        $due = SmmPublication::where('status', 'SCHEDULED')->where('scheduled_at', '<=', self::ts(now()))->orderBy('scheduled_at')->limit($limit)->pluck('id');
        foreach ($due as $id) {
            if (self::claim($id)) {
                self::start(SmmPublication::findOrFail($id));
                $started++;
            }
        }
        $pending = SmmPublication::where('status', 'PUBLISHING')->whereNotNull('container_id')->where('next_check_at', '<=', self::ts(now()))
            ->orderBy('next_check_at')->limit($limit * 4)->pluck('id');
        foreach ($pending as $id) {
            // Захват проверки: следующий запуск cron не возьмёт ту же публикацию
            $taken = SmmPublication::whereKey($id)->where('status', 'PUBLISHING')->where('next_check_at', '<=', self::ts(now()))
                ->update(['next_check_at' => now()->addMinutes(5)]);
            if ($taken) {
                self::check(SmmPublication::findOrFail($id));
                $checked++;
            }
        }
        foreach (SmmPublication::where('status', 'PUBLISHING')->whereNull('container_id')
            ->where('publishing_started_at', '<', self::ts(now()->subMinutes(self::STALE_MINUTES)))->get() as $p) {
            self::fail($p, t('smm.ig.errors.interrupted'));
        }

        return ['started' => $started, 'checked' => $checked, 'cleaned' => self::cleanup()];
    }

    /** Время для сравнения в запросе — с миллисекундами, как в базе (DATETIME(3)). */
    private static function ts(\DateTimeInterface $at): string
    {
        return $at->format('Y-m-d H:i:s.v');
    }

    private static function claim(string $id): bool
    {
        return SmmPublication::whereKey($id)->where('status', 'SCHEDULED')->where('scheduled_at', '<=', self::ts(now()))
            ->update(['status' => 'PUBLISHING', 'publishing_started_at' => now(), 'container_id' => null, 'error' => null,
                'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]) === 1;
    }

    /** Временная подписанная ссылка на файл публикации (без входа, только для Meta). */
    public static function mediaUrl(SmmPublication $p, string $kind): string
    {
        return URL::temporarySignedRoute('smm.media.public', now()->addHours(self::LINK_HOURS), ['id' => $p->id, 'kind' => $kind]);
    }

    private static function start(SmmPublication $p): void
    {
        try {
            $path = SmmMedia::path($p->media_path);
            if (! $path || ! is_file($path)) {
                throw new InstagramError(t('smm.ig.errors.fileMissing'));
            }
            $ig = InstagramApi::igUserId();
            InstagramApi::assertQuota($ig);
            $caption = $p->caption;
            if ($p->kind === 'REELS') {
                $cover = $p->cover_path && SmmMedia::exists($p->cover_path) ? self::mediaUrl($p, 'cover') : null;
                $c = InstagramApi::createReelsContainer($ig, $caption, $p->share_to_feed, $p->thumb_offset_ms, $cover);
                @set_time_limit(0);
                InstagramApi::uploadVideo($c['uri'], $path);
                $containerId = $c['id'];
            } else {
                $containerId = InstagramApi::createImageContainer($ig, self::mediaUrl($p, 'media'), $caption);
            }
            $p->update(['container_id' => $containerId, 'next_check_at' => now()->addSeconds(self::CHECK_EVERY)]);
        } catch (InstagramError $e) {
            self::fail($p, $e->getMessage());
        } catch (Throwable $e) {
            Log::warning('Instagram publish failed: '.$e->getMessage());
            self::fail($p, 'Instagram: '.$e->getMessage());
        }
    }

    private static function check(SmmPublication $p): void
    {
        try {
            $s = InstagramApi::containerStatus((string) $p->container_id);
            if ($s['code'] === 'FINISHED') {
                $mediaId = InstagramApi::publish(InstagramApi::igUserId(), (string) $p->container_id);
                self::complete($p, $mediaId, InstagramApi::permalink($mediaId));

                return;
            }
            if ($s['code'] === 'IN_PROGRESS' || $s['code'] === '') {
                if ($p->publishing_started_at && $p->publishing_started_at->lt(now()->subHours(self::MAX_PROCESSING_HOURS))) {
                    self::fail($p, t('smm.ig.errors.processingTimeout'));

                    return;
                }
                $p->update(['next_check_at' => now()->addSeconds(self::CHECK_EVERY * 2)]);

                return;
            }
            self::fail($p, t('smm.ig.errors.container', ['status' => $s['code'], 'error' => $s['status'] ?? '—']));
        } catch (InstagramError $e) {
            self::fail($p, $e->getMessage());
        } catch (Throwable $e) {
            Log::warning('Instagram status check failed: '.$e->getMessage());
            self::fail($p, 'Instagram: '.$e->getMessage());
        }
    }

    /** Опубликовано: публикация и идея, история, аудит, событие; файл удаляется сразу. */
    private static function complete(SmmPublication $p, string $mediaId, ?string $permalink): void
    {
        $paths = [$p->media_path, $p->cover_path];
        DB::transaction(function () use ($p, $mediaId, $permalink) {
            $now = now();
            $p->update(['status' => 'PUBLISHED', 'ig_media_id' => $mediaId, 'permalink' => $permalink, 'published_at' => $now,
                'error' => null, 'next_check_at' => null]);
            $idea = SmmIdea::withTrashed()->lockForUpdate()->findOrFail($p->idea_id);
            $from = $idea->status;
            $data = ['published_at' => $now];
            if ($from !== 'PUBLISHED') {
                $data['status'] = 'PUBLISHED';
            }
            if ($permalink) {
                $data['publish_url'] = $permalink;
            }
            $oldUrl = $idea->publish_url;
            $idea->update($data);
            $changes = array_filter([
                'status' => $from !== 'PUBLISHED' ? ['old' => $from, 'new' => 'PUBLISHED'] : null,
                'publish_url' => $permalink && $permalink !== $oldUrl ? ['old' => $oldUrl, 'new' => $permalink] : null,
            ]);
            SmmIdeaHistory::create(['idea_id' => $idea->id, 'user_id' => null, 'action' => 'ig_published', 'changes' => $changes ?: null, 'created_at' => $now]);
            Audit::log('smm.ig_published', 'smm_publication', $p->id, $changes + ['ig_media_id' => ['old' => null, 'new' => $mediaId]], null);
            Outbox::publish('smm.instagram_published', ['ideaId' => $idea->id, 'publicationId' => $p->id, 'mediaId' => $mediaId, 'permalink' => $permalink, 'from' => $from], null);
        });
        self::dropFiles($p->fresh(), $paths);
    }

    private static function fail(SmmPublication $p, string $error): void
    {
        DB::transaction(function () use ($p, $error) {
            $p->update(['status' => 'FAILED', 'error' => mb_substr($error, 0, 2000), 'failed_at' => now(), 'next_check_at' => null]);
            SmmIdeaHistory::create(['idea_id' => $p->idea_id, 'user_id' => null, 'action' => 'ig_failed',
                'changes' => ['igStatus' => ['old' => 'PUBLISHING', 'new' => 'FAILED']], 'comment' => mb_substr($error, 0, 2000), 'created_at' => now()]);
            Audit::log('smm.ig_failed', 'smm_publication', $p->id, ['status' => ['old' => 'PUBLISHING', 'new' => 'FAILED'], 'error' => ['old' => null, 'new' => mb_substr($error, 0, 500)]], null);
            Outbox::publish('smm.instagram_failed', ['ideaId' => $p->idea_id, 'publicationId' => $p->id, 'error' => mb_substr($error, 0, 500)], null);
        });
    }

    /** Удалить файлы публикации, в базе — только метаданные и отметка «файл удалён». */
    public static function dropFiles(SmmPublication $p, ?array $paths = null): void
    {
        foreach ($paths ?? [$p->media_path, $p->cover_path] as $path) {
            SmmMedia::delete($path);
        }
        $p->update(['media_path' => null, 'cover_path' => null, 'file_deleted_at' => now()]);
    }

    /** Брошенные загрузки, файлы неудавшихся публикаций старше failed_keep_days дней и оставшиеся файлы опубликованных. */
    public static function cleanup(): int
    {
        $n = SmmMedia::cleanupChunks();
        $days = (int) config('fluggi.smm_media.failed_keep_days', 7);
        $old = SmmPublication::where(fn ($q) => $q->whereNotNull('media_path')->orWhereNotNull('cover_path'))
            ->where(fn ($q) => $q->where('status', 'PUBLISHED')
                ->orWhere(fn ($w) => $w->where('status', 'FAILED')->where('failed_at', '<', self::ts(now()->subDays($days)))))
            ->limit(100)->get();
        foreach ($old as $p) {
            self::dropFiles($p);
            if ($p->status === 'FAILED') {
                SmmIdeaHistory::create(['idea_id' => $p->idea_id, 'user_id' => null, 'action' => 'ig_file_deleted', 'created_at' => now()]);
                Audit::log('smm.ig_file_deleted', 'smm_publication', $p->id, ['file' => ['old' => $p->media_name, 'new' => null]], null);
            }
            $n++;
        }

        return $n;
    }
}
