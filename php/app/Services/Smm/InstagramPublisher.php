<?php

namespace App\Services\Smm;

use App\Models\SmmPublication;
use App\Models\SmmPublicationTarget;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Публикация в Instagram по расписанию (место INSTAGRAM публикации) — cron раз в минуту (SmmPublisher::tick),
 * без долгого ожидания в одном запуске:
 *  1) наступило время SCHEDULED → PUBLISHING: проверка лимита публикаций, контейнер
 *     (Reels — загрузка исходного файла, фото — image_url на временную подписанную ссылку);
 *  2) PUBLISHING: статус контейнера (FINISHED / IN_PROGRESS / ERROR) в следующих запусках;
 *     FINISHED → media_publish → permalink → SmmTargets::published (идея «Опубликовано», файлы — когда опубликованы все места);
 *  3) ошибка Meta → FAILED с текстом Meta, автору уведомление; повтор — кнопкой в карточке.
 */
final class InstagramPublisher
{
    /** Пауза между проверками статуса контейнера, секунд. */
    public const CHECK_EVERY = 30;

    /** Контейнер, который Meta обрабатывает дольше, считается неудачным, часов. */
    public const MAX_PROCESSING_HOURS = 24;

    /** Временная ссылка на файл для Meta, часов. */
    public const LINK_HOURS = 6;

    /** @return array{started:int, checked:int} */
    public static function run(int $limit = 5): array
    {
        $started = 0;
        $checked = 0;
        $now = SmmTargets::ts(now());
        $due = SmmPublicationTarget::where('kind', 'INSTAGRAM')->where('status', 'SCHEDULED')->where('scheduled_at', '<=', $now)
            ->orderBy('scheduled_at')->limit($limit)->pluck('id');
        foreach ($due as $id) {
            if ($t = SmmTargets::claim($id)) {
                self::start($t);
                $started++;
            }
        }
        $pending = SmmPublicationTarget::where('kind', 'INSTAGRAM')->where('status', 'PUBLISHING')->whereNotNull('container_id')
            ->where('next_check_at', '<=', $now)->orderBy('next_check_at')->limit($limit * 4)->pluck('id');
        foreach ($pending as $id) {
            // Захват проверки: следующий запуск cron не возьмёт то же место
            $taken = SmmPublicationTarget::whereKey($id)->where('status', 'PUBLISHING')->where('next_check_at', '<=', $now)
                ->update(['next_check_at' => now()->addMinutes(5)]);
            if ($taken) {
                self::check(SmmPublicationTarget::with('publication')->findOrFail($id));
                $checked++;
            }
        }

        return ['started' => $started, 'checked' => $checked];
    }

    /** Временная подписанная ссылка на файл публикации (без входа, только для Meta). */
    public static function mediaUrl(SmmPublication $p, string $kind): string
    {
        return URL::temporarySignedRoute('smm.media.public', now()->addHours(self::LINK_HOURS), ['id' => $p->id, 'kind' => $kind]);
    }

    private static function start(SmmPublicationTarget $t): void
    {
        $p = $t->publication;
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
                if (config('fluggi.smm_media.ig_reels_upload', 'url') === 'resumable') {
                    $c = InstagramApi::createReelsContainer($ig, $caption, $p->share_to_feed, $p->thumb_offset_ms, $cover);
                    @set_time_limit(0);
                    InstagramApi::uploadVideo($c['uri'], $path);
                } else {
                    // Meta скачивает оригинал по временной подписанной ссылке (файл без пережатия)
                    $c = InstagramApi::createReelsContainer($ig, $caption, $p->share_to_feed, $p->thumb_offset_ms, $cover, self::mediaUrl($p, 'media'));
                }
                $containerId = $c['id'];
            } else {
                $containerId = InstagramApi::createImageContainer($ig, self::mediaUrl($p, 'media'), $caption);
            }
            $t->update(['container_id' => $containerId, 'next_check_at' => now()->addSeconds(self::CHECK_EVERY)]);
        } catch (InstagramError $e) {
            SmmTargets::failed($t, $e->getMessage());
        } catch (Throwable $e) {
            Log::warning('Instagram publish failed: '.$e->getMessage());
            SmmTargets::failed($t, 'Instagram: '.$e->getMessage());
        }
    }

    private static function check(SmmPublicationTarget $t): void
    {
        try {
            $s = InstagramApi::containerStatus((string) $t->container_id);
            if ($s['code'] === 'FINISHED') {
                $mediaId = InstagramApi::publish(InstagramApi::igUserId(), (string) $t->container_id);
                SmmTargets::published($t, $mediaId, InstagramApi::permalink($mediaId));

                return;
            }
            if ($s['code'] === 'IN_PROGRESS' || $s['code'] === '') {
                if ($t->started_at && $t->started_at->lt(now()->subHours(self::MAX_PROCESSING_HOURS))) {
                    SmmTargets::failed($t, t('smm.ig.errors.processingTimeout'));

                    return;
                }
                $t->update(['next_check_at' => now()->addSeconds(self::CHECK_EVERY * 2)]);

                return;
            }
            SmmTargets::failed($t, t('smm.ig.errors.container', ['status' => $s['code'], 'error' => $s['status'] ?? '—']));
        } catch (InstagramError $e) {
            SmmTargets::failed($t, $e->getMessage());
        } catch (Throwable $e) {
            Log::warning('Instagram status check failed: '.$e->getMessage());
            SmmTargets::failed($t, 'Instagram: '.$e->getMessage());
        }
    }
}
