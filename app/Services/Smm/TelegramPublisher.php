<?php

namespace App\Services\Smm;

use App\Models\SmmPublication;
use App\Models\SmmPublicationTarget;
use App\Models\SmmTelegramChannel;
use App\Services\Telegram\TelegramApiError;
use App\Services\Telegram\TelegramClient;
use App\Support\Secrets;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Публикация в Telegram-канал по расписанию (место TELEGRAM) — в том же запуске cron, что и Instagram:
 *  - видео → sendVideo (файл целиком multipart, supports_streaming, размер кадра и длительность из заголовка MP4/MOV,
 *    миниатюра и обложка — из обложки Reels), фото → sendPhoto;
 *  - файл — «версия для Telegram», если загружена, иначе исходный (лимит Bot API: видео 50 МБ, фото 10 МБ);
 *  - подпись без разметки: к медиа — до 1024 символов, остальное — следующими сообщениями (до 4096);
 *    id сообщения и число отправленных продолжений запоминаются сразу — повтор после сбоя не дублирует уже отправленное;
 *  - 429 (retry_after) — место снова в расписании через указанное время; другая ошибка — FAILED с текстом Telegram.
 */
final class TelegramPublisher
{
    /** Подряд «подождите» (429) — после стольких попыток место считается неудачным. */
    public const MAX_ATTEMPTS = 20;

    /** Миниатюра видео для Telegram: JPEG до 320 px и до 200 КБ. */
    public const THUMB_PX = 320;

    /** @return array{started:int} */
    public static function run(int $limit = 5): array
    {
        $started = 0;
        $due = SmmPublicationTarget::where('kind', 'TELEGRAM')->where('status', 'SCHEDULED')->where('scheduled_at', '<=', SmmTargets::ts(now()))
            ->orderBy('scheduled_at')->limit($limit)->pluck('id');
        foreach ($due as $id) {
            if ($t = SmmTargets::claim($id)) {
                self::send($t);
                $started++;
            }
        }

        return ['started' => $started];
    }

    /** Лимит Bot API для файла, байт. */
    public static function limitBytes(string $kind): int
    {
        return (int) config($kind === 'REELS' ? 'fluggi.telegram.upload_video_mb' : 'fluggi.telegram.upload_photo_mb', $kind === 'REELS' ? 50 : 10) * 1024 * 1024;
    }

    /** Файл, который уйдёт в Telegram: [относительный путь, имя, размер] — версия для Telegram или исходный. */
    public static function file(SmmPublication $p): array
    {
        return $p->tg_media_path
            ? [$p->tg_media_path, (string) $p->tg_media_name, (int) $p->tg_media_size]
            : [$p->media_path, (string) $p->media_name, (int) $p->media_size];
    }

    /** Ссылка на сообщение: публичный канал — t.me/<username>/<id>, закрытый — t.me/c/<внутренний id>/<id>. */
    public static function permalink(string $chatId, ?string $username, int|string $messageId): ?string
    {
        if ($username) {
            return 'https://t.me/'.$username.'/'.$messageId;
        }

        return str_starts_with($chatId, '-100') ? 'https://t.me/c/'.substr($chatId, 4).'/'.$messageId : null;
    }

    private static function send(SmmPublicationTarget $t): void
    {
        $p = $t->publication;
        /** @var SmmTelegramChannel|null $ch */
        $ch = $t->channel;
        $thumb = null;
        try {
            if (! $ch || $ch->trashed()) {
                SmmTargets::failed($t, t('smm.tg.errors.channelRemoved'));

                return;
            }
            [$caption, $more] = TelegramCaption::split($p->caption);
            if (! $t->external_id) {
                [$relative, $name, $size] = self::file($p);
                $path = SmmMedia::path($relative);
                if (! $path || ! is_file($path)) {
                    SmmTargets::failed($t, t('smm.ig.errors.fileMissing'));

                    return;
                }
                if ($size > self::limitBytes($p->kind)) {
                    SmmTargets::failed($t, self::tooLargeMessage($p->kind));

                    return;
                }
                @set_time_limit(0);
                $fields = ['chat_id' => $ch->chat_id] + ($caption !== '' ? ['caption' => $caption] : []);
                if ($p->kind === 'REELS') {
                    $info = SmmMedia::probeVideo($path);
                    $fields += ['supports_streaming' => true]
                        + (isset($info['width'], $info['height']) ? ['width' => $info['width'], 'height' => $info['height']] : [])
                        + (isset($info['seconds']) ? ['duration' => (int) round($info['seconds'])] : []);
                    $files = ['video' => [$path, $name]];
                    $cover = $p->cover_path ? SmmMedia::path($p->cover_path) : null;
                    if ($cover && is_file($cover)) {
                        $files['cover'] = [$cover, 'cover.jpg'];
                        $fields['cover'] = 'attach://cover';
                        if ($thumb = SmmMedia::thumbnail($cover, self::THUMB_PX)) {
                            $files['thumbnail'] = [$thumb, 'thumb.jpg'];
                            $fields['thumbnail'] = 'attach://thumbnail';
                        }
                    }
                    $msg = (array) TelegramClient::upload('sendVideo', $fields, $files);
                } else {
                    $msg = (array) TelegramClient::upload('sendPhoto', $fields, ['photo' => [$path, $name]]);
                }
                $messageId = (string) ($msg['message_id'] ?? '');
                $username = $msg['chat']['username'] ?? $ch->username;
                $t->update(['external_id' => $messageId, 'permalink' => $messageId !== '' ? self::permalink($ch->chat_id, $username, $messageId) : null, 'sent_parts' => 0]);
            }
            // Продолжение длинной подписи — отдельными сообщениями, каждое отмечается сразу
            for ($i = $t->sent_parts; $i < count($more); $i++) {
                TelegramClient::call('sendMessage', ['chat_id' => $ch->chat_id, 'text' => $more[$i]], 30);
                $t->update(['sent_parts' => $i + 1]);
            }
            SmmTargets::published($t, $t->external_id, $t->permalink);
        } catch (TelegramApiError $e) {
            if ($e->status === 429 && $t->attempts < self::MAX_ATTEMPTS) {
                SmmTargets::postpone($t, (int) ($e->retryAfter ?? 30) + 1, 'Telegram: '.$e->description);

                return;
            }
            // Нет связи с Telegram (тайм-аут, обрыв): повтор через 2, 4, 8 … до 30 минут
            if ($e->network && $t->attempts < self::MAX_ATTEMPTS) {
                SmmTargets::postpone($t, min(1800, 60 * 2 ** max(1, min(5, (int) $t->attempts))), $e->description);

                return;
            }
            SmmTargets::failed($t, 'Telegram: '.($e->description !== '' ? $e->description : 'HTTP '.$e->status));
        } catch (Throwable $e) {
            Log::warning('Telegram channel publish failed: '.Secrets::redact($e->getMessage()));
            SmmTargets::failed($t, 'Telegram: '.Secrets::redact($e->getMessage()));
        } finally {
            if ($thumb) {
                @unlink($thumb);
            }
        }
    }

    public static function tooLargeMessage(string $kind): string
    {
        return $kind === 'REELS'
            ? t('smm.tg.errors.videoTooLarge', ['mb' => (int) (self::limitBytes('REELS') / 1024 / 1024)])
            : t('smm.tg.errors.photoTooLarge', ['mb' => (int) (self::limitBytes('IMAGE') / 1024 / 1024)]);
    }
}
