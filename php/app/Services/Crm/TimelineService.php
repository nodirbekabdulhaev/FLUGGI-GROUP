<?php

namespace App\Services\Crm;

use App\Models\Activity;
use App\Models\Comment;
use App\Models\Lead;
use App\Models\StageHistory;
use App\Support\Audit;
use App\Support\Format;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Таймлайн, история этапов и комментарии карточек лида, сделки и клиента (ТЗ §12).
 * Доступ проверяется по самой записи (право *.read с её областью) — до вызова.
 */
final class TimelineService
{
    /** @param  array{lead_id?:string, deal_id?:string, client_id?:string}  $target */
    public static function assertAccess(array $target): void
    {
        $target = array_filter($target);
        if (count($target) !== 1) {
            abort(404);
        }
        match (array_key_first($target)) {
            'lead_id' => CrmAccess::lead($target['lead_id']),
            'deal_id' => CrmAccess::deal($target['deal_id']),
            'client_id' => CrmAccess::client($target['client_id']),
        };
    }

    /** @return Collection<int, Activity> Таймлайн сделки включает историю лида, из которого она возникла. */
    public static function activities(array $target): Collection
    {
        $q = Activity::with('actor')->orderByDesc('created_at')->limit(200);
        if (! empty($target['deal_id'])) {
            $leadId = Lead::withTrashed()->where('deal_id', $target['deal_id'])->value('id');
            $q->where(fn ($q) => $q->where('deal_id', $target['deal_id'])->when($leadId, fn ($q) => $q->orWhere('lead_id', $leadId)));
        } elseif (! empty($target['lead_id'])) {
            $q->where('lead_id', $target['lead_id']);
        } else {
            $q->where('client_id', $target['client_id']);
        }

        return $q->get();
    }

    /** @return Collection<int, StageHistory> */
    public static function history(array $target): Collection
    {
        if (! empty($target['client_id'])) {
            return collect();
        }

        return StageHistory::with(['fromStage', 'toStage', 'changedBy'])
            ->when(! empty($target['lead_id']), fn ($q) => $q->where('lead_id', $target['lead_id']), fn ($q) => $q->where('deal_id', $target['deal_id']))
            ->orderByDesc('created_at')->orderByDesc('id')->get();
    }

    /** @return Collection<int, Comment> */
    public static function comments(array $target): Collection
    {
        $key = array_key_first(array_filter($target));

        return Comment::with('author')->where($key, $target[$key])->orderByDesc('created_at')->get();
    }

    public function addComment(array $target, string $body): Comment
    {
        $target = array_filter($target);
        self::assertAccess($target);

        return DB::transaction(function () use ($target, $body) {
            $comment = Comment::create($target + ['body' => $body, 'author_id' => access()->id()]);
            Activities::log('comment.added', $target, ['commentId' => $comment->id, 'preview' => mb_substr($body, 0, 140)]);

            return $comment;
        });
    }

    /** Автор может скрыть свой комментарий (мягкое удаление, след остаётся в аудите). */
    public function deleteComment(string $id): Comment
    {
        $comment = Comment::findOrFail($id);
        if ($comment->author_id !== access()->id()) {
            throw new HttpException(403, t('crm.errors.ownCommentOnly'));
        }
        DB::transaction(function () use ($comment) {
            $comment->delete();
            Audit::log('comment.delete', 'comment', $comment->id, ['body' => ['old' => $comment->body, 'new' => null]]);
        });

        return $comment;
    }

    /** Текст события таймлайна (как в прежней версии: подпись + подробности из payload). */
    public static function describe(Activity $a): string
    {
        $p = $a->payload ?? [];
        $key = 'crm.activity.'.$a->type;
        $label = \App\Support\Lang::has($key) || \App\Support\Lang::has($key, 'ru') ? t($key) : $a->type;
        $str = fn ($v) => is_scalar($v) ? (string) $v : '';

        return match ($a->type) {
            'lead.stage_changed', 'deal.stage_changed' => $label.': '.$str($p['from'] ?? '').' → '.$str($p['to'] ?? '')
                .(! empty($p['auto']) ? ' ('.t('crm.common.auto').')' : ''),
            'lead.assigned', 'deal.assigned' => $label.': '.$str($p['to'] ?? ''),
            'lead.closed', 'deal.closed' => $label.': '.t('crm.closeStatus.'.$str($p['status'] ?? ''))
                .(! empty($p['reason']) ? ' — '.$str($p['reason']) : '')
                .(! empty($p['comment']) ? ' («'.$str($p['comment']).'»)' : ''),
            'deal.amount_changed' => $label.': '.self::change($p['changes']['amount'] ?? null)
                .(isset($p['changes']['currency']) ? ' ('.self::change($p['changes']['currency']).')' : ''),
            'lead.updated', 'deal.updated', 'client.updated', 'meeting.updated' => $label.': '.collect(array_keys($p['changes'] ?? []))
                ->map(fn ($f) => self::fieldLabel($f))->implode(', '),
            'meeting.created' => t('crm.common.meetingAt', ['label' => $label, 'date' => Format::dateTime($p['startsAt'] ?? null)]),
            'meeting.completed' => $label.': '.$str($p['result'] ?? ''),
            'comment.added' => $label.': '.$str($p['preview'] ?? ''),
            'lead.converted' => $label.' ('.$str($p['client'] ?? '').')',
            default => $label,
        };
    }

    public static function fieldLabel(string $field): string
    {
        $key = Str::camel(preg_replace('/_id$/', '', $field));

        return \App\Support\Lang::has('crm.fields.'.$key, 'ru') ? t('crm.fields.'.$key) : $field;
    }

    private static function change(?array $c): string
    {
        if (! $c) {
            return '';
        }
        $fmt = fn ($v) => is_numeric($v) ? Format::number((string) $v) : (string) $v;

        return $fmt($c['old'] ?? '').' → '.$fmt($c['new'] ?? '');
    }

    /** Длительность на этапе: «5 мин», «3 ч», «2 дн». */
    public static function duration(int $sec): string
    {
        return match (true) {
            $sec < 3600 => t('crm.common.minutes', ['n' => max(1, (int) round($sec / 60))]),
            $sec < 86400 => t('crm.common.hours', ['n' => (int) round($sec / 3600)]),
            default => t('crm.common.days', ['n' => (int) round($sec / 86400)]),
        };
    }
}
