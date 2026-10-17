<?php

namespace App\Services\Smm;

use App\Domain\People;
use App\Domain\Tasks;
use App\Exceptions\BusinessRule;
use App\Models\SmmIdea;
use App\Models\SmmIdeaComment;
use App\Models\SmmIdeaHistory;
use App\Models\User;
use App\Support\Audit;
use App\Support\Format;
use App\Support\Outbox;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Идеи роликов (раздел «SMM»). Этапы: IDEA → APPROVED → SHOOTING → EDITING → SCHEDULED → PUBLISHED, REJECTED.
 *  - Идею утверждают двое: проект-менеджер (SMM) и CEO; когда есть обе подписи — «Утверждено» автоматически.
 *    Нет активных проект-менеджеров — достаточно подписи CEO. Любой из них может отклонить с причиной.
 *  - Съёмка → монтаж → запланировано (нужна дата публикации) → опубликовано: только после утверждения;
 *    двигают ответственный, проект-менеджер и CEO (вперёд и назад). Вернуть из «Опубликовано» — только CEO.
 *  - Отклонённую идею автор или ответственный может отправить на утверждение заново (подписи сбрасываются).
 * Каждое изменение — в истории идеи (только добавление) и в журнале аудита.
 */
final class SmmIdeaService
{
    /** Источник лида «Видео SMM»: в лиде обязателен опубликованный ролик. */
    public const LEAD_SOURCE = 'SMM_VIDEO';

    public const STATUSES = ['IDEA', 'APPROVED', 'SHOOTING', 'EDITING', 'SCHEDULED', 'PUBLISHED', 'REJECTED'];

    /** Колонки Kanban. */
    public const KANBAN = ['IDEA', 'APPROVED', 'SHOOTING', 'EDITING', 'SCHEDULED', 'PUBLISHED'];

    /** Производственные этапы (после утверждения). */
    public const WORK = ['SHOOTING', 'EDITING', 'SCHEDULED', 'PUBLISHED'];

    /** Поля, изменения которых видны в истории. */
    public const TRACKED = ['title', 'scenario', 'notes', 'assignee_id', 'planned_publish_date', 'published_at', 'publish_url'];

    public const COLORS = [
        'IDEA' => 'gray', 'APPROVED' => 'blue', 'SHOOTING' => 'yellow', 'EDITING' => 'yellow',
        'SCHEDULED' => 'blue', 'PUBLISHED' => 'green', 'REJECTED' => 'red',
    ];

    public static function code(SmmIdea $idea): string
    {
        return Format::code('V', $idea->number);
    }

    /**
     * Список и Kanban: фильтры — статус, ответственный, месяц (дата публикации, плановая дата или дата создания, по Ташкенту), поиск.
     *
     * @param  array{status?:?string, assignee_id?:?string, month?:?string, q?:?string}  $f
     */
    public static function list(array $f): Collection
    {
        $q = SmmAccess::ideas()->with(['assignee:id,full_name', 'author:id,full_name'])->withCount('comments')
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['assignee_id'] ?? null, fn ($q, $v) => $q->where('assignee_id', $v))
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('title', 'like', '%'.$v.'%')->orWhere('scenario', 'like', '%'.$v.'%')));
        if ($month = $f['month'] ?? null) {
            ['from' => $from, 'to' => $to] = People::monthRange($month);
            $d1 = $month.'-01';
            $d2 = CarbonImmutable::parse($d1)->addMonthNoOverflow()->format('Y-m-d');
            $q->where(fn ($w) => $w->where(fn ($x) => $x->where('published_at', '>=', $from)->where('published_at', '<', $to))
                ->orWhere(fn ($x) => $x->whereNull('published_at')->where('planned_publish_date', '>=', $d1)->where('planned_publish_date', '<', $d2))
                ->orWhere(fn ($x) => $x->whereNull('published_at')->whereNull('planned_publish_date')->where('created_at', '>=', $from)->where('created_at', '<', $to)));
        }
        $items = $q->orderBy('sort_order')->orderBy('created_at')->limit(500)->get();
        self::decorate($items);

        return $items;
    }

    /** Права на действия с карточкой (для интерфейса; сервер проверяет те же правила). */
    public static function decorate(Collection $ideas): void
    {
        $access = access();
        $approve = SmmAccess::canApprove();
        $ceo = $access->roleCode === 'CEO';
        foreach ($ideas as $i) {
            $mine = $i->assignee_id === $access->id() || $i->author_id === $access->id();
            $i->setAttribute('can_edit', $mine || $approve);
            $i->setAttribute('can_move', $i->status === 'PUBLISHED' ? $ceo : ($i->assignee_id === $access->id() || $approve));
            $i->setAttribute('can_approve', $approve && $i->status === 'IDEA'
                && (SmmAccess::approverSlot() === 'ceo' ? ! $i->ceo_approved_by_id : ! $i->pm_approved_by_id));
            $i->setAttribute('can_reject', $approve && in_array($i->status, ['IDEA', 'APPROVED'], true));
            $i->setAttribute('can_resubmit', $i->status === 'REJECTED' && ($mine || $approve));
            $i->setAttribute('can_delete', $ceo || ($i->author_id === $access->id() && in_array($i->status, ['IDEA', 'REJECTED'], true)));
        }
    }

    public static function get(string $id): SmmIdea
    {
        $idea = SmmAccess::idea($id);
        $idea->load(['assignee:id,full_name', 'author:id,full_name', 'pmApprovedBy:id,full_name', 'ceoApprovedBy:id,full_name', 'rejectedBy:id,full_name']);
        self::decorate(collect([$idea]));
        $idea->setRelation('comments', SmmIdeaComment::with('author:id,full_name')->where('idea_id', $id)->orderBy('created_at')->get());
        $idea->setRelation('history', SmmIdeaHistory::with('user:id,full_name')->where('idea_id', $id)->orderByDesc('created_at')->orderByDesc('id')->get());

        return $idea;
    }

    /** Запись в историю идеи и в журнал аудита. */
    private static function log(SmmIdea $idea, string $action, ?array $changes = null, ?string $comment = null): void
    {
        $actor = access(false)?->id();
        SmmIdeaHistory::create([
            'idea_id' => $idea->id, 'user_id' => $actor, 'action' => $action,
            'changes' => $changes ?: null, 'comment' => $comment, 'created_at' => now(),
        ]);
        Audit::log('smm.'.$action, 'smm_idea', $idea->id, ($changes ?: []) + ($comment !== null ? ['comment' => ['old' => null, 'new' => $comment]] : []) ?: null, $actor);
    }

    /**
     * @param  array{title:string, scenario?:?string, notes?:?string, assignee_id?:?string, planned_publish_date?:?string}  $input
     */
    public static function create(array $input): SmmIdea
    {
        SmmAccess::authorize('smm.create');
        $access = access();
        $assigneeId = ($input['assignee_id'] ?? null) ?: (SmmAccess::isSmmExecutor($access->user) ? $access->id() : null);
        if (! $assigneeId) {
            throw new BusinessRule(t('smm.errors.assigneeRequired'), ['assignee_id' => t('smm.errors.assigneeRequired')]);
        }
        if (! SmmAccess::isAssignable($assigneeId)) {
            throw new BusinessRule(t('smm.errors.assigneeInvalid'), ['assignee_id' => t('smm.errors.assigneeInvalid')]);
        }

        return DB::transaction(function () use ($input, $access, $assigneeId) {
            $last = SmmIdea::where('status', 'IDEA')->max('sort_order');
            $idea = SmmIdea::create([
                'title' => $input['title'],
                'scenario' => $input['scenario'] ?? null,
                'notes' => $input['notes'] ?? null,
                'status' => 'IDEA',
                'author_id' => $access->id(),
                'assignee_id' => $assigneeId,
                'planned_publish_date' => $input['planned_publish_date'] ?? null,
                'sort_order' => Tasks::sortBetween($last === null ? null : (float) $last, null),
            ]);
            $idea->refresh();
            self::log($idea, 'created', [
                'title' => ['old' => null, 'new' => $idea->title],
                'assignee' => ['old' => null, 'new' => self::userName($assigneeId)],
            ]);
            Outbox::publish('smm.idea_created', ['ideaId' => $idea->id, 'authorId' => $access->id(), 'assigneeId' => $assigneeId]);

            return $idea;
        });
    }

    private static function userName(?string $id): ?string
    {
        return $id ? User::withTrashed()->whereKey($id)->value('full_name') : null;
    }

    private static function assertCanEdit(SmmIdea $idea): void
    {
        $uid = access()->id();
        abort_unless($idea->assignee_id === $uid || $idea->author_id === $uid || SmmAccess::canApprove(), 403);
    }

    /**
     * Сценарий, название, заметки, ответственный, даты, ссылка на пост.
     *
     * @param  array<string,mixed>  $input
     */
    public static function update(string $id, array $input): SmmIdea
    {
        $idea = SmmAccess::idea($id);
        self::assertCanEdit($idea);
        $data = array_intersect_key($input, array_flip(['title', 'scenario', 'notes', 'assignee_id', 'planned_publish_date', 'publish_url', 'published_at']));
        if (array_key_exists('title', $data) && ! filled($data['title'])) {
            unset($data['title']);
        }
        if (array_key_exists('assignee_id', $data)) {
            if (! $data['assignee_id'] || $data['assignee_id'] === $idea->assignee_id) {
                unset($data['assignee_id']);
            } elseif (! SmmAccess::isAssignable($data['assignee_id'])) {
                throw new BusinessRule(t('smm.errors.assigneeInvalid'), ['assignee_id' => t('smm.errors.assigneeInvalid')]);
            }
        }
        if ($idea->status === 'SCHEDULED' && array_key_exists('planned_publish_date', $data) && ! $data['planned_publish_date']) {
            throw new BusinessRule(t('smm.errors.plannedDateRequired'), ['planned_publish_date' => t('smm.errors.plannedDateRequired')]);
        }
        // Дата публикации — только у опубликованного ролика
        if (array_key_exists('published_at', $data) && ($idea->status !== 'PUBLISHED' || ! $data['published_at'])) {
            unset($data['published_at']);
        }

        return DB::transaction(function () use ($idea, $data) {
            $old = self::comparable($idea);
            $idea->update($data);
            $idea->refresh();
            $changes = Audit::diff($old, self::comparable($idea), self::TRACKED);
            if ($changes) {
                if (isset($changes['assignee_id'])) {
                    $changes['assignee'] = ['old' => self::userName($changes['assignee_id']['old']), 'new' => self::userName($changes['assignee_id']['new'])];
                    unset($changes['assignee_id']);
                }
                self::log($idea, 'updated', $changes);
                if (isset($changes['assignee'])) {
                    Outbox::publish('smm.idea_status_changed', ['ideaId' => $idea->id, 'from' => $idea->status, 'to' => $idea->status, 'assigned' => true]);
                }
            }

            return $idea;
        });
    }

    /** Значения для сравнения «было/стало» (даты — строкой). */
    private static function comparable(SmmIdea $idea): array
    {
        return [
            'title' => $idea->title,
            'scenario' => $idea->scenario,
            'notes' => $idea->notes,
            'assignee_id' => $idea->assignee_id,
            'planned_publish_date' => $idea->planned_publish_date?->format('Y-m-d'),
            'published_at' => $idea->published_at ? Format::dateTime($idea->published_at) : null,
            'publish_url' => $idea->publish_url,
        ];
    }

    /** Подпись проект-менеджера или CEO. Обе есть (или нет проект-менеджеров) — идея утверждена. */
    public static function approve(string $id): SmmIdea
    {
        SmmAccess::authorize('smm.approve');
        $idea = SmmAccess::idea($id);
        $slot = SmmAccess::approverSlot();

        return DB::transaction(function () use ($idea, $slot) {
            $idea = SmmIdea::whereKey($idea->id)->lockForUpdate()->first();
            if ($idea->status !== 'IDEA') {
                throw new BusinessRule(t('smm.errors.approveOnlyIdea'));
            }
            if ($idea->{$slot.'_approved_by_id'}) {
                throw new BusinessRule(t('smm.errors.alreadyApproved'));
            }
            $actor = access()->id();
            $idea->update([$slot.'_approved_by_id' => $actor, $slot.'_approved_at' => now()]);
            self::log($idea, $slot === 'ceo' ? 'approved_ceo' : 'approved_pm');

            $pmOk = $idea->pm_approved_by_id !== null || SmmAccess::projectManagerIds() === [];
            if ($idea->ceo_approved_by_id && $pmOk) {
                $idea->update(['status' => 'APPROVED', 'sort_order' => self::positionIn('APPROVED', null, $idea->id)]);
                self::log($idea, 'status', ['status' => ['old' => 'IDEA', 'new' => 'APPROVED']]);
                Outbox::publish('smm.idea_approved', ['ideaId' => $idea->id, 'assigneeId' => $idea->assignee_id, 'authorId' => $idea->author_id]);
            }

            return $idea->fresh();
        });
    }

    /** Отклонение с причиной — проект-менеджер или CEO, пока ролик не в работе. */
    public static function reject(string $id, string $reason): SmmIdea
    {
        SmmAccess::authorize('smm.approve');
        $idea = SmmAccess::idea($id);
        if (! in_array($idea->status, ['IDEA', 'APPROVED'], true)) {
            throw new BusinessRule(t('smm.errors.rejectOnlyIdea'));
        }

        return DB::transaction(function () use ($idea, $reason) {
            $from = $idea->status;
            $idea->update([
                'status' => 'REJECTED', 'rejected_by_id' => access()->id(), 'rejected_at' => now(), 'reject_reason' => $reason,
                'sort_order' => self::positionIn('REJECTED', null, $idea->id),
            ]);
            self::log($idea, 'rejected', ['status' => ['old' => $from, 'new' => 'REJECTED']], $reason);
            Outbox::publish('smm.idea_rejected', ['ideaId' => $idea->id, 'assigneeId' => $idea->assignee_id, 'authorId' => $idea->author_id, 'reason' => $reason]);

            return $idea->fresh();
        });
    }

    /**
     * Перемещение по этапам (Kanban, «Переместить в» на телефоне).
     *
     * @param  array{planned_publish_date?:?string, published_at?:?CarbonImmutable, publish_url?:?string}  $extra
     */
    public static function move(string $id, string $to, ?string $beforeId = null, array $extra = []): SmmIdea
    {
        $idea = SmmAccess::idea($id);
        $access = access();
        $from = $idea->status;
        $uid = $access->id();
        $approver = SmmAccess::canApprove();
        $mine = $idea->assignee_id === $uid || $idea->author_id === $uid;

        if ($from === $to) {
            abort_unless($mine || $approver, 403);
        } elseif ($from === 'PUBLISHED') {
            // Опубликованный ролик (он уже в зарплате и в источниках лидов) возвращает только CEO
            if ($access->roleCode !== 'CEO') {
                throw new BusinessRule(t('smm.errors.publishedRevertCeo'));
            }
        } elseif ($to === 'REJECTED') {
            throw new BusinessRule(t('smm.errors.rejectWithReason'));
        } elseif ($to === 'IDEA') {
            // Отклонённую идею — снова на утверждение
            if ($from !== 'REJECTED') {
                throw new BusinessRule(t('smm.errors.backToIdea'));
            }
            abort_unless($mine || $approver, 403);
        } elseif (in_array($from, ['IDEA', 'REJECTED'], true)) {
            throw new BusinessRule(t('smm.errors.needsApproval'));
        } else {
            // APPROVED / SHOOTING / EDITING / SCHEDULED → APPROVED / SHOOTING / EDITING / SCHEDULED / PUBLISHED
            abort_unless($idea->assignee_id === $uid || $approver, 403);
        }

        $data = ['status' => $to, 'sort_order' => self::positionIn($to, $beforeId, $idea->id)];
        if ($from !== $to) {
            if ($to === 'SCHEDULED') {
                $date = ($extra['planned_publish_date'] ?? null) ?: $idea->planned_publish_date?->format('Y-m-d');
                if (! $date) {
                    throw new BusinessRule(t('smm.errors.plannedDateRequired'), ['planned_publish_date' => t('smm.errors.plannedDateRequired')]);
                }
                $data['planned_publish_date'] = $date;
            }
            if ($to === 'PUBLISHED') {
                $data['published_at'] = $extra['published_at'] ?? now();
                if (filled($extra['publish_url'] ?? null)) {
                    $data['publish_url'] = $extra['publish_url'];
                }
            } elseif ($from === 'PUBLISHED') {
                $data['published_at'] = null;
            }
            if ($to === 'IDEA') {
                $data += ['pm_approved_by_id' => null, 'pm_approved_at' => null, 'ceo_approved_by_id' => null, 'ceo_approved_at' => null,
                    'rejected_by_id' => null, 'rejected_at' => null, 'reject_reason' => null];
            }
        }

        return DB::transaction(function () use ($idea, $data, $from, $to) {
            $old = self::comparable($idea);
            $idea->update($data);
            $idea->refresh();
            if ($from === $to) {
                return $idea;
            }
            $changes = ['status' => ['old' => $from, 'new' => $to]]
                + (Audit::diff($old, self::comparable($idea), ['planned_publish_date', 'published_at', 'publish_url']) ?? []);
            self::log($idea, $to === 'IDEA' ? 'resubmitted' : 'status', $changes);
            Outbox::publish('smm.idea_status_changed', ['ideaId' => $idea->id, 'from' => $from, 'to' => $to]);

            return $idea;
        });
    }

    /** sort_order для карточки перед $beforeId (или в конце колонки). */
    private static function positionIn(string $status, ?string $beforeId, string $selfId): float
    {
        $column = fn () => SmmIdea::where('status', $status)->whereKeyNot($selfId);
        if ($beforeId) {
            $before = $column()->whereKey($beforeId)->first();
            if ($before) {
                $prev = $column()->where('sort_order', '<', $before->sort_order)->max('sort_order');

                return Tasks::sortBetween($prev === null ? null : (float) $prev, (float) $before->sort_order);
            }
        }
        $last = $column()->max('sort_order');

        return Tasks::sortBetween($last === null ? null : (float) $last, null);
    }

    /** Комментарий может оставить любой, кто видит идею. */
    public static function comment(string $id, string $body): SmmIdeaComment
    {
        $idea = SmmAccess::idea($id);

        return DB::transaction(function () use ($idea, $body) {
            $c = SmmIdeaComment::create(['idea_id' => $idea->id, 'author_id' => access()->id(), 'body' => $body]);
            self::log($idea, 'commented', null, $body);

            return $c;
        });
    }

    /** Удаление (мягкое): CEO — любую, автор — свою идею до утверждения или отклонённую. */
    public static function remove(string $id): void
    {
        $idea = SmmAccess::idea($id);
        self::decorate(collect([$idea]));
        abort_unless($idea->can_delete, 403);
        if (DB::table('leads')->where('smm_idea_id', $idea->id)->exists() || DB::table('deals')->where('smm_idea_id', $idea->id)->exists()) {
            throw new BusinessRule(t('smm.errors.usedInLeads'));
        }
        DB::transaction(function () use ($idea) {
            self::log($idea, 'deleted', ['title' => ['old' => $idea->title, 'new' => null]]);
            $idea->delete();
        });
    }

    // ─────────────── Для продаж и зарплаты ───────────────

    /** Опубликованные ролики для выбора в лиде/сделке: [id => «V-00001 · название»]. */
    public static function publishedOptions(?string $keepId = null): array
    {
        return SmmIdea::query()->where(fn ($q) => $q->where('status', 'PUBLISHED')->when($keepId, fn ($q) => $q->orWhere('id', $keepId)))
            ->orderByDesc('published_at')->limit(300)->get(['id', 'number', 'title'])
            ->mapWithKeys(fn ($i) => [$i->id => self::code($i).' · '.$i->title])->all();
    }

    public static function isPublished(?string $id): bool
    {
        return $id !== null && SmmIdea::whereKey($id)->where('status', 'PUBLISHED')->exists();
    }

    /**
     * Ролик для лида/сделки: при источнике «Видео SMM» обязателен опубликованный ролик, при другом — не хранится.
     * $keepId — уже сохранённый ролик (его можно оставить, даже если публикацию потом отменили).
     */
    public static function videoFor(?string $sourceCode, ?string $ideaId, ?string $keepId = null): ?string
    {
        if ($sourceCode !== self::LEAD_SOURCE) {
            return null;
        }
        if (! $ideaId) {
            throw new BusinessRule(t('smm.errors.videoRequired'), ['smm_idea_id' => t('smm.errors.videoRequired')]);
        }
        if ($ideaId !== $keepId && ! self::isPublished($ideaId)) {
            throw new BusinessRule(t('smm.errors.videoNotPublished'), ['smm_idea_id' => t('smm.errors.videoNotPublished')]);
        }

        return $ideaId;
    }

    /** Опубликовано роликов сотрудником за месяц (по Ташкенту). */
    public static function publishedCount(string $userId, string $period): int
    {
        ['from' => $from, 'to' => $to] = People::monthRange($period);

        return SmmIdea::where('assignee_id', $userId)->where('status', 'PUBLISHED')
            ->where('published_at', '>=', $from)->where('published_at', '<', $to)->count();
    }
}
