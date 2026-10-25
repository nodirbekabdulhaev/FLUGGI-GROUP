<?php

namespace App\Services\Smm;

use App\Exceptions\BusinessRule;
use App\Models\SmmIdea;
use App\Models\SmmIdeaHistory;
use App\Models\SmmPublication;
use App\Support\Audit;
use App\Support\Format;
use App\Support\Outbox;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Автопубликация идеи в Instagram — действия сотрудника (карточка идеи):
 *  - файл (Reels MP4/MOV или фото JPEG/PNG) и обложка Reels — загрузкой частями (SmmMedia);
 *  - подпись (до 2200 символов, до 30 хэштегов), «Поделиться в ленте», кадр обложки, дата и время по Ташкенту;
 *  - статусы: DRAFT → SCHEDULED → PUBLISHING → PUBLISHED / FAILED (повтор — снова SCHEDULED).
 * Менять может тот, кто может редактировать идею (автор, ответственный, проект-менеджер, CEO);
 * запланировать — только утверждённую идею (этапы «Утверждено» … «Запланировано»).
 * Файл удаляется сразу после публикации, при отмене и при замене; у неудачной — через failed_keep_days дней.
 */
final class SmmPublicationService
{
    public const CAPTION_MAX = 2200;

    public const HASHTAGS_MAX = 30;

    /** Этапы идеи, на которых можно планировать публикацию (идея утверждена, ещё не опубликована). */
    public const SCHEDULABLE = ['APPROVED', 'SHOOTING', 'EDITING', 'SCHEDULED'];

    /** Статусы, в которых публикацию можно менять. */
    public const EDITABLE = ['DRAFT', 'SCHEDULED', 'FAILED'];

    public const COLORS = ['DRAFT' => 'gray', 'SCHEDULED' => 'blue', 'PUBLISHING' => 'yellow', 'PUBLISHED' => 'green', 'FAILED' => 'red'];

    /** Идея в зоне видимости и право её редактировать (иначе 404 / 403). */
    public static function editableIdea(string $ideaId): SmmIdea
    {
        $idea = SmmAccess::idea($ideaId);
        $uid = access()->id();
        abort_unless($idea->assignee_id === $uid || $idea->author_id === $uid || SmmAccess::canApprove(), 403);

        return $idea;
    }

    public static function canEdit(SmmIdea $idea): bool
    {
        $uid = access()->id();

        return $idea->assignee_id === $uid || $idea->author_id === $uid || SmmAccess::canApprove();
    }

    public static function for(SmmIdea $idea): ?SmmPublication
    {
        return SmmPublication::where('idea_id', $idea->id)->first();
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

    /** Загруженный файл (результат SmmMedia::finish) → публикация; прежний файл удаляется. */
    public static function attach(SmmIdea $idea, array $file): SmmPublication
    {
        $old = null;
        $p = DB::transaction(function () use ($idea, $file, &$old) {
            $p = SmmPublication::where('idea_id', $idea->id)->lockForUpdate()->first();
            if ($p) {
                self::assertEditable($p);
            }
            if ($file['purpose'] === 'cover') {
                if (! $p || $p->kind !== 'REELS' || ! $p->media_path) {
                    throw new BusinessRule(t('smm.ig.errors.coverNeedsVideo'));
                }
                $old = $p->cover_path;
                $p->update(['cover_path' => $file['path'], 'cover_name' => $file['name'], 'cover_size' => $file['size'], 'updated_by_id' => access()->id()]);
                $action = 'ig_cover';
            } else {
                $data = ['kind' => $file['type'] === 'video' ? 'REELS' : 'IMAGE', 'media_path' => $file['path'], 'media_name' => $file['name'],
                    'media_mime' => $file['mime'], 'media_size' => $file['size'], 'file_deleted_at' => null, 'updated_by_id' => access()->id()];
                if ($p) {
                    $old = [$p->media_path];
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
        foreach ((array) $old as $path) {
            SmmMedia::delete($path);
        }

        return $p->fresh();
    }

    /**
     * Подпись, параметры и время. $schedule = true — «Запланировать», false — черновик (снять с расписания).
     *
     * @param  array{caption?:?string, share_to_feed?:bool, thumb_offset_ms?:?int, scheduled_at?:?CarbonImmutable}  $input
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
        if ($schedule) {
            if (! in_array($idea->status, self::SCHEDULABLE, true)) {
                throw new BusinessRule(t('smm.ig.errors.notApproved'));
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
        }
        $data = [
            'caption' => $caption !== '' ? $caption : null,
            'share_to_feed' => array_key_exists('share_to_feed', $input) ? (bool) $input['share_to_feed'] : $p->share_to_feed,
            'thumb_offset_ms' => array_key_exists('thumb_offset_ms', $input) ? $input['thumb_offset_ms'] : $p->thumb_offset_ms,
            'scheduled_at' => $at,
            'status' => $schedule ? 'SCHEDULED' : 'DRAFT',
            'error' => $schedule ? $p->error : null,
            'updated_by_id' => access()->id(),
        ];

        return DB::transaction(function () use ($idea, $p, $data, $schedule) {
            $from = $p->status;
            $p->update($data);
            $changes = array_filter([
                'igStatus' => $from !== $data['status'] ? ['old' => $from, 'new' => $data['status']] : null,
                'scheduledAt' => $schedule ? ['old' => null, 'new' => Format::dateTime($data['scheduled_at'])] : null,
            ]);
            self::history($idea, $schedule ? 'ig_scheduled' : 'ig_saved', $changes);
            Audit::log('smm.'.($schedule ? 'ig_scheduled' : 'ig_saved'), 'smm_publication', $p->id, $changes + ['caption' => ['old' => null, 'new' => mb_substr((string) $data['caption'], 0, 200)]]);
            // Идея с запланированной публикацией — в колонку «Запланировано» с датой публикации
            if ($schedule && $idea->status !== 'SCHEDULED') {
                $date = Format::local($data['scheduled_at'])->format('Y-m-d');
                $old = $idea->status;
                $idea->update(['status' => 'SCHEDULED', 'planned_publish_date' => $date]);
                self::history($idea, 'status', ['status' => ['old' => $old, 'new' => 'SCHEDULED'], 'planned_publish_date' => ['old' => null, 'new' => $date]]);
                Audit::log('smm.status', 'smm_idea', $idea->id, ['status' => ['old' => $old, 'new' => 'SCHEDULED']]);
                Outbox::publish('smm.idea_status_changed', ['ideaId' => $idea->id, 'from' => $old, 'to' => 'SCHEDULED']);
            } elseif ($schedule) {
                $idea->update(['planned_publish_date' => Format::local($data['scheduled_at'])->format('Y-m-d')]);
            }

            return $p->fresh();
        });
    }

    /** Повтор неудачной публикации — сразу (cron в ближайшую минуту). */
    public static function retry(SmmIdea $idea): SmmPublication
    {
        $p = self::for($idea);
        abort_if(! $p, 404);
        if ($p->status !== 'FAILED') {
            throw new BusinessRule(t('smm.ig.errors.retryOnlyFailed'));
        }
        if (! SmmMedia::exists($p->media_path)) {
            throw new BusinessRule(t('smm.ig.errors.fileGone'));
        }
        if (! in_array($idea->status, self::SCHEDULABLE, true)) {
            throw new BusinessRule(t('smm.ig.errors.notApproved'));
        }

        return DB::transaction(function () use ($idea, $p) {
            $p->update(['status' => 'SCHEDULED', 'scheduled_at' => now(), 'container_id' => null, 'next_check_at' => null,
                'publishing_started_at' => null, 'updated_by_id' => access()->id()]);
            self::history($idea, 'ig_retry', ['igStatus' => ['old' => 'FAILED', 'new' => 'SCHEDULED']]);
            Audit::log('smm.ig_retry', 'smm_publication', $p->id, ['status' => ['old' => 'FAILED', 'new' => 'SCHEDULED']]);

            return $p->fresh();
        });
    }

    /** Отменить публикацию: запись и файлы удаляются (не во время публикации и не после неё). */
    public static function cancel(SmmIdea $idea): void
    {
        $p = self::for($idea);
        abort_if(! $p, 404);
        self::assertEditable($p);
        $paths = [$p->media_path, $p->cover_path];
        DB::transaction(function () use ($idea, $p) {
            self::history($idea, 'ig_cancelled', ['igStatus' => ['old' => $p->status, 'new' => null]]);
            Audit::log('smm.ig_cancelled', 'smm_publication', $p->id, [
                'status' => ['old' => $p->status, 'new' => null], 'file' => ['old' => $p->media_name, 'new' => null],
            ]);
            $p->delete();
        });
        foreach ($paths as $path) {
            SmmMedia::delete($path);
        }
    }
}
