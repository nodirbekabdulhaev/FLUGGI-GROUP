<?php

namespace App\Services\Smm;

use App\Exceptions\BusinessRule;
use App\Models\SmmIdea;
use App\Models\SmmIdeaHistory;
use App\Models\SmmPublication;
use App\Models\SmmPublicationTarget;
use App\Models\SmmTelegramChannel;
use App\Services\Telegram\TelegramClient;
use App\Support\Audit;
use App\Support\Format;
use App\Support\Outbox;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Автопубликация идеи (Instagram и Telegram-каналы) — действия сотрудника (карточка идеи):
 *  - файл (Reels MP4/MOV или фото JPEG/PNG), обложка Reels и необязательная «версия для Telegram» (видео до 50 МБ) —
 *    загрузкой частями (SmmMedia);
 *  - подпись (до 2200 символов, до 30 хэштегов), «Поделиться в ленте», кадр обложки, дата и время по Ташкенту;
 *  - места публикации (галочки): Instagram и каждый настроенный Telegram-канал; у каждого свой статус
 *    DRAFT → SCHEDULED → PUBLISHING → PUBLISHED / FAILED (повтор — снова SCHEDULED), общий статус — SmmTargets::aggregate.
 * Менять может тот, кто может редактировать идею (автор, ответственный, проект-менеджер, CEO);
 * запланировать — только утверждённую идею (этапы «Утверждено» … «Запланировано»; опубликованную — в оставшиеся места).
 * Файлы удаляются, когда опубликованы все выбранные места (или оставшиеся сняты), при отмене и при замене;
 * при неудаче — через failed_keep_days дней.
 */
final class SmmPublicationService
{
    public const CAPTION_MAX = 2200;

    public const HASHTAGS_MAX = 30;

    /** Этапы идеи, на которых можно планировать публикацию (идея утверждена, ещё не опубликована). */
    public const SCHEDULABLE = ['APPROVED', 'SHOOTING', 'EDITING', 'SCHEDULED'];

    /** Плюс уже опубликованная идея — публикация в оставшиеся места и повтор неудавшихся. */
    public const PUBLISHABLE = [...self::SCHEDULABLE, 'PUBLISHED'];

    /** Статусы, в которых публикацию можно менять. */
    public const EDITABLE = ['DRAFT', 'SCHEDULED', 'FAILED'];

    public const COLORS = ['DRAFT' => 'gray', 'SCHEDULED' => 'blue', 'PUBLISHING' => 'yellow', 'PUBLISHED' => 'green', 'FAILED' => 'red'];

    public const INSTAGRAM = 'instagram';

    /** Идея в зоне видимости и право её редактировать (иначе 404 / 403). */
    public static function editableIdea(string $ideaId): SmmIdea
    {
        $idea = SmmAccess::idea($ideaId);
        abort_unless(self::canEdit($idea), 403);

        return $idea;
    }

    public static function canEdit(SmmIdea $idea): bool
    {
        $uid = access()->id();

        return $idea->assignee_id === $uid || $idea->author_id === $uid || SmmAccess::canApprove();
    }

    public static function for(SmmIdea $idea): ?SmmPublication
    {
        return SmmPublication::with('targets.channel')->where('idea_id', $idea->id)->first();
    }

    /** Места на выбор: Instagram и действующие Telegram-каналы (ключ → название). */
    public static function options(): array
    {
        $out = [self::INSTAGRAM => 'Instagram'];
        foreach (SmmTelegramChannel::orderBy('title')->get() as $ch) {
            $out['tg:'.$ch->id] = 'Telegram · '.$ch->title;
        }

        return $out;
    }

    private static function history(SmmIdea $idea, string $action, ?array $changes = null, ?string $comment = null, ?string $actor = null): void
    {
        $actor ??= access(false)?->id();
        SmmIdeaHistory::create(['idea_id' => $idea->id, 'user_id' => $actor, 'action' => $action,
            'changes' => $changes ?: null, 'comment' => $comment, 'created_at' => now()]);
    }

    private static function assertEditable(SmmPublication $p): void
    {
        if (! in_array($p->status, self::EDITABLE, true)) {
            throw new BusinessRule(t('smm.ig.errors.locked'));
        }
    }

    private static function hasPublished(SmmPublication $p): bool
    {
        return SmmPublicationTarget::where('publication_id', $p->id)->where('status', 'PUBLISHED')->exists();
    }

    /** Можно ли начать загрузку файла этого назначения (до передачи данных). */
    public static function assertCanUpload(SmmIdea $idea, string $purpose): void
    {
        $p = SmmPublication::where('idea_id', $idea->id)->first();
        if ($p) {
            self::assertEditable($p);
            // Исходный файл уже опубликован хотя бы в одно место — не заменяется (версия для Telegram — можно)
            if ($purpose === 'media' && self::hasPublished($p)) {
                throw new BusinessRule(t('smm.pub.errors.mediaPublished'));
            }
        }
        if ($purpose === 'telegram' && (! $p || $p->kind !== 'REELS' || ! $p->media_path)) {
            throw new BusinessRule(t('smm.tg.errors.versionNeedsVideo'));
        }
    }

    /** Загруженный файл (результат SmmMedia::finish) → публикация; прежний файл удаляется. */
    public static function attach(SmmIdea $idea, array $file): SmmPublication
    {
        $old = [];
        $p = DB::transaction(function () use ($idea, $file, &$old) {
            $p = SmmPublication::where('idea_id', $idea->id)->lockForUpdate()->first();
            if ($p) {
                self::assertEditable($p);
            }
            if ($file['purpose'] === 'cover') {
                if (! $p || $p->kind !== 'REELS' || ! $p->media_path) {
                    throw new BusinessRule(t('smm.ig.errors.coverNeedsVideo'));
                }
                $old = [$p->cover_path];
                $p->update(['cover_path' => $file['path'], 'cover_name' => $file['name'], 'cover_size' => $file['size'], 'updated_by_id' => access()->id()]);
                $action = 'ig_cover';
            } elseif ($file['purpose'] === 'telegram') {
                if (! $p || $p->kind !== 'REELS' || ! $p->media_path) {
                    throw new BusinessRule(t('smm.tg.errors.versionNeedsVideo'));
                }
                $old = [$p->tg_media_path];
                $p->update(['tg_media_path' => $file['path'], 'tg_media_name' => $file['name'], 'tg_media_mime' => $file['mime'],
                    'tg_media_size' => $file['size'], 'updated_by_id' => access()->id()]);
                $action = 'tg_media';
            } else {
                $data = ['kind' => $file['type'] === 'video' ? 'REELS' : 'IMAGE', 'media_path' => $file['path'], 'media_name' => $file['name'],
                    'media_mime' => $file['mime'], 'media_size' => $file['size'], 'file_deleted_at' => null, 'updated_by_id' => access()->id()];
                if ($p) {
                    if (self::hasPublished($p)) {
                        throw new BusinessRule(t('smm.pub.errors.mediaPublished'));
                    }
                    // Версия для Telegram относится к прежнему видео
                    $old = [$p->media_path, $p->tg_media_path];
                    $data += ['tg_media_path' => null, 'tg_media_name' => null, 'tg_media_mime' => null, 'tg_media_size' => null];
                    // Фото вместо видео — обложка Reels больше не нужна
                    if ($data['kind'] === 'IMAGE' && $p->cover_path) {
                        $old[] = $p->cover_path;
                        $data += ['cover_path' => null, 'cover_name' => null, 'cover_size' => null];
                    }
                    $p->update($data);
                } else {
                    $p = SmmPublication::create($data + ['idea_id' => $idea->id, 'status' => 'DRAFT', 'share_to_feed' => true, 'created_by_id' => access()->id()]);
                }
                $action = 'ig_media';
            }
            self::history($idea, $action, ['file' => ['old' => null, 'new' => $file['name'].' · '.SmmMedia::human($file['size'])]]);
            Audit::log('smm.'.$action, 'smm_publication', $p->id, ['file' => ['old' => null, 'new' => $file['name']], 'size' => ['old' => null, 'new' => $file['size']]]);

            return $p;
        });
        foreach ($old as $path) {
            SmmMedia::delete($path);
        }

        return $p->fresh();
    }

    /** Убрать версию для Telegram (тогда в Telegram уходит исходный файл). */
    public static function removeTelegramFile(SmmIdea $idea): void
    {
        $p = self::for($idea);
        abort_if(! $p || ! $p->tg_media_name, 404);
        self::assertEditable($p);
        $path = $p->tg_media_path;
        DB::transaction(function () use ($idea, $p) {
            self::history($idea, 'tg_media_removed', ['file' => ['old' => $p->tg_media_name, 'new' => null]]);
            Audit::log('smm.tg_media_removed', 'smm_publication', $p->id, ['file' => ['old' => $p->tg_media_name, 'new' => null]]);
            $p->update(['tg_media_path' => null, 'tg_media_name' => null, 'tg_media_mime' => null, 'tg_media_size' => null, 'updated_by_id' => access()->id()]);
        });
        SmmMedia::delete($path);
    }

    /**
     * Выбранные места: ключ → [kind, channel]. Неизвестный ключ или удалённый канал — ошибка.
     *
     * @param  list<string>  $keys
     * @return array<string, array{kind:string, channel:?SmmTelegramChannel}>
     */
    private static function resolveTargets(array $keys): array
    {
        $out = [];
        foreach (array_unique(array_map('strval', $keys)) as $key) {
            if ($key === self::INSTAGRAM) {
                $out[$key] = ['kind' => 'INSTAGRAM', 'channel' => null];

                continue;
            }
            $ch = str_starts_with($key, 'tg:') ? SmmTelegramChannel::find(substr($key, 3)) : null;
            if (! $ch) {
                throw new BusinessRule(t('smm.pub.errors.unknownTarget'), ['targets' => t('smm.pub.errors.unknownTarget')]);
            }
            $out[$key] = ['kind' => 'TELEGRAM', 'channel' => $ch];
        }

        return $out;
    }

    private static function labels(iterable $targets): string
    {
        $l = [];
        foreach ($targets as $t) {
            $l[] = $t instanceof SmmPublicationTarget ? $t->label() : ($t['kind'] === 'INSTAGRAM' ? 'Instagram' : 'Telegram · '.$t['channel']->title);
        }
        sort($l);

        return implode(', ', $l);
    }

    /** Есть ли у места файл для отправки (Telegram, где медиа уже отправлено, досылает только подпись — файл не нужен). */
    public static function fileReady(SmmPublication $p, SmmPublicationTarget $t): bool
    {
        if ($t->kind === 'TELEGRAM') {
            return (bool) $t->external_id || SmmMedia::exists(TelegramPublisher::file($p)[0]);
        }

        return SmmMedia::exists($p->media_path);
    }

    /**
     * Подпись, параметры, время и места. $schedule = true — «Запланировать», false — черновик (снять с расписания).
     * targets (необязательно) — ключи мест ('instagram', 'tg:<id канала>'); нет — места не меняются.
     * Опубликованные места остаются всегда.
     *
     * @param  array{caption?:?string, share_to_feed?:bool, thumb_offset_ms?:?int, scheduled_at?:?CarbonImmutable, targets?:list<string>}  $input
     */
    public static function save(SmmIdea $idea, array $input, bool $schedule): SmmPublication
    {
        $p = self::for($idea);
        if (! $p) {
            throw new BusinessRule(t('smm.ig.errors.noMedia'), ['file' => t('smm.ig.errors.noMedia')]);
        }
        self::assertEditable($p);
        $caption = isset($input['caption']) ? trim(str_replace("\r\n", "\n", (string) $input['caption'])) : $p->caption;
        if ($caption !== null && mb_strlen($caption) > self::CAPTION_MAX) {
            throw new BusinessRule(t('smm.ig.errors.captionLong', ['max' => self::CAPTION_MAX]), ['caption' => t('smm.ig.errors.captionLong', ['max' => self::CAPTION_MAX])]);
        }
        if ($caption !== null && preg_match_all('/(^|\s)#[\p{L}\p{N}_]+/u', $caption) > self::HASHTAGS_MAX) {
            throw new BusinessRule(t('smm.ig.errors.hashtags', ['max' => self::HASHTAGS_MAX]), ['caption' => t('smm.ig.errors.hashtags', ['max' => self::HASHTAGS_MAX])]);
        }
        $at = array_key_exists('scheduled_at', $input) ? $input['scheduled_at'] : $p->scheduled_at;

        $existing = $p->targets->keyBy('target_key');
        $published = $existing->where('status', 'PUBLISHED');
        $wanted = array_key_exists('targets', $input) ? self::resolveTargets((array) $input['targets']) : null;
        $keep = $wanted === null ? $existing->keys()->all() : array_keys($wanted);
        $keep = array_values(array_unique([...$keep, ...$published->keys()->all()]));
        $pending = array_values(array_filter($keep, fn ($k) => ($existing[$k]->status ?? null) !== 'PUBLISHED'));
        $describe = fn (string $k) => $existing[$k] ?? $wanted[$k];

        if ($schedule) {
            if (! in_array($idea->status, self::PUBLISHABLE, true)) {
                throw new BusinessRule(t('smm.ig.errors.notApproved'));
            }
            if ($pending === []) {
                throw new BusinessRule(t('smm.pub.errors.noTargets'), ['targets' => t('smm.pub.errors.noTargets')]);
            }
            if (! $p->media_path || ! SmmMedia::exists($p->media_path)) {
                throw new BusinessRule(t('smm.ig.errors.noMedia'), ['file' => t('smm.ig.errors.noMedia')]);
            }
            if (! $at) {
                throw new BusinessRule(t('smm.ig.errors.timeRequired'), ['scheduled_at' => t('smm.ig.errors.timeRequired')]);
            }
            if ($at->lt(now()->subMinute())) {
                throw new BusinessRule(t('smm.ig.errors.timePast'), ['scheduled_at' => t('smm.ig.errors.timePast')]);
            }
            foreach ($pending as $k) {
                $d = $describe($k);
                $kind = $d instanceof SmmPublicationTarget ? $d->kind : $d['kind'];
                $channel = $d instanceof SmmPublicationTarget ? $d->channel : $d['channel'];
                if ($kind === 'INSTAGRAM' && ! InstagramApi::token()) {
                    throw new BusinessRule(t('integrations.errors.noPageToken'), ['targets' => t('integrations.errors.noPageToken')]);
                }
                if ($kind !== 'TELEGRAM') {
                    continue;
                }
                if (! TelegramClient::configured()) {
                    throw new BusinessRule(t('smm.tg.errors.botNotConfigured'), ['targets' => t('smm.tg.errors.botNotConfigured')]);
                }
                if (! $channel || $channel->trashed() || ! $channel->can_post) {
                    $msg = t('smm.tg.errors.channelNotReady', ['channel' => $channel?->title ?? '—']);
                    throw new BusinessRule($msg, ['targets' => $msg]);
                }
                $alreadySent = $d instanceof SmmPublicationTarget && $d->external_id;
                if (! $alreadySent && TelegramPublisher::file($p)[2] > TelegramPublisher::limitBytes($p->kind)) {
                    $msg = TelegramPublisher::tooLargeMessage($p->kind);
                    throw new BusinessRule($msg, ['targets' => $msg]);
                }
            }
        }

        $data = [
            'caption' => $caption !== '' ? $caption : null,
            'share_to_feed' => array_key_exists('share_to_feed', $input) ? (bool) $input['share_to_feed'] : $p->share_to_feed,
            'thumb_offset_ms' => array_key_exists('thumb_offset_ms', $input) ? $input['thumb_offset_ms'] : $p->thumb_offset_ms,
            'scheduled_at' => $at,
            'updated_by_id' => access()->id(),
        ];

        $p = DB::transaction(function () use ($idea, $p, $data, $schedule, $existing, $wanted, $keep, $pending, $at) {
            $from = $p->status;
            $before = self::labels($existing->values());
            // Снятые галочки (кроме опубликованных)
            $removed = $existing->filter(fn ($t, $k) => ! in_array($k, $keep, true) && $t->status !== 'PUBLISHED');
            SmmPublicationTarget::whereIn('id', $removed->pluck('id'))->delete();
            foreach ($pending as $k) {
                $t = $existing[$k] ?? SmmPublicationTarget::create([
                    'publication_id' => $p->id, 'kind' => $wanted[$k]['kind'], 'channel_id' => $wanted[$k]['channel']?->id, 'target_key' => $k, 'status' => 'DRAFT',
                ]);
                $t->update($schedule
                    ? ['status' => 'SCHEDULED', 'scheduled_at' => $at, 'container_id' => null, 'next_check_at' => null, 'started_at' => null]
                    : ['status' => 'DRAFT', 'error' => null, 'next_check_at' => null]);
            }
            $p->update($data);
            SmmTargets::settle($p);
            $after = self::labels(SmmPublicationTarget::with('channel')->where('publication_id', $p->id)->get());
            $changes = array_filter([
                'igStatus' => $from !== $p->status ? ['old' => $from, 'new' => $p->status] : null,
                'scheduledAt' => $schedule ? ['old' => null, 'new' => Format::dateTime($data['scheduled_at'])] : null,
                'targets' => $before !== $after ? ['old' => $before ?: null, 'new' => $after ?: null] : null,
            ]);
            self::history($idea, $schedule ? 'ig_scheduled' : 'ig_saved', $changes);
            Audit::log('smm.'.($schedule ? 'ig_scheduled' : 'ig_saved'), 'smm_publication', $p->id, $changes + ['caption' => ['old' => null, 'new' => mb_substr((string) $data['caption'], 0, 200)]]);
            // Идея с запланированной публикацией — в колонку «Запланировано» с датой публикации (опубликованная остаётся)
            if ($schedule && ! in_array($idea->status, ['SCHEDULED', 'PUBLISHED'], true)) {
                $date = Format::local($data['scheduled_at'])->format('Y-m-d');
                $old = $idea->status;
                $idea->update(['status' => 'SCHEDULED', 'planned_publish_date' => $date]);
                self::history($idea, 'status', ['status' => ['old' => $old, 'new' => 'SCHEDULED'], 'planned_publish_date' => ['old' => null, 'new' => $date]]);
                Audit::log('smm.status', 'smm_idea', $idea->id, ['status' => ['old' => $old, 'new' => 'SCHEDULED']]);
                Outbox::publish('smm.idea_status_changed', ['ideaId' => $idea->id, 'from' => $old, 'to' => 'SCHEDULED']);
            } elseif ($schedule && $idea->status === 'SCHEDULED') {
                $idea->update(['planned_publish_date' => Format::local($data['scheduled_at'])->format('Y-m-d')]);
            }

            return $p;
        });
        // Оставшиеся места сняты, а остальные уже опубликованы — файлы не нужны
        SmmTargets::dropIfDone($p->fresh());

        return $p->fresh(['targets.channel']);
    }

    /** Повтор неудавшихся мест (или одного места $targetId) — сразу (cron в ближайшую минуту). */
    public static function retry(SmmIdea $idea, ?string $targetId = null): SmmPublication
    {
        $p = self::for($idea);
        abort_if(! $p, 404);
        $targets = $p->targets->where('status', 'FAILED');
        if ($targetId !== null) {
            abort_unless($p->targets->contains('id', $targetId), 404);
            $targets = $targets->where('id', $targetId);
        }
        if ($targets->isEmpty()) {
            throw new BusinessRule(t('smm.ig.errors.retryOnlyFailed'));
        }
        foreach ($targets as $t) {
            if (! self::fileReady($p, $t)) {
                throw new BusinessRule(t('smm.ig.errors.fileGone'));
            }
            if ($t->kind === 'TELEGRAM' && (! $t->channel || $t->channel->trashed())) {
                throw new BusinessRule(t('smm.tg.errors.channelRemoved'));
            }
        }
        if (! in_array($idea->status, self::PUBLISHABLE, true)) {
            throw new BusinessRule(t('smm.ig.errors.notApproved'));
        }

        return DB::transaction(function () use ($idea, $p, $targets) {
            foreach ($targets as $t) {
                $t->update(['status' => 'SCHEDULED', 'scheduled_at' => now(), 'container_id' => null, 'next_check_at' => null, 'started_at' => null]);
                $changes = ['igStatus' => ['old' => 'FAILED', 'new' => 'SCHEDULED']] + ($t->kind === 'TELEGRAM' ? ['channel' => ['old' => null, 'new' => $t->channel?->title]] : []);
                self::history($idea, 'ig_retry', $changes);
                Audit::log('smm.ig_retry', 'smm_publication', $p->id, ['status' => ['old' => 'FAILED', 'new' => 'SCHEDULED'], 'target' => ['old' => null, 'new' => $t->label()]]);
            }
            $p->update(['updated_by_id' => access()->id()]);

            return SmmTargets::settle($p)->fresh(['targets.channel']);
        });
    }

    /**
     * Отменить публикацию (не во время публикации): ни одно место не опубликовано — запись и файлы удаляются;
     * есть опубликованные — снимаются остальные места, файлы удаляются, запись с опубликованными остаётся.
     */
    public static function cancel(SmmIdea $idea): void
    {
        $p = self::for($idea);
        abort_if(! $p, 404);
        self::assertEditable($p);
        $paths = [$p->media_path, $p->cover_path, $p->tg_media_path];
        $keepRecord = self::hasPublished($p);
        DB::transaction(function () use ($idea, $p, $keepRecord) {
            self::history($idea, 'ig_cancelled', ['igStatus' => ['old' => $p->status, 'new' => $keepRecord ? 'PUBLISHED' : null]]);
            Audit::log('smm.ig_cancelled', 'smm_publication', $p->id, [
                'status' => ['old' => $p->status, 'new' => $keepRecord ? 'PUBLISHED' : null], 'file' => ['old' => $p->media_name, 'new' => null],
            ]);
            if ($keepRecord) {
                SmmPublicationTarget::where('publication_id', $p->id)->where('status', '!=', 'PUBLISHED')->delete();
                SmmTargets::settle($p);
                SmmTargets::dropFiles($p);
            } else {
                $p->delete();
            }
        });
        foreach ($paths as $path) {
            SmmMedia::delete($path);
        }
    }
}
