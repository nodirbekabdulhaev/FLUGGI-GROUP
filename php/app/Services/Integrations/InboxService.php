<?php

namespace App\Services\Integrations;

use App\Auth\Access;
use App\Exceptions\BusinessRule;
use App\Models\SocialMessage;
use App\Models\SocialThread;
use Illuminate\Database\Eloquent\Builder;

/**
 * «Входящие»: Директ и комментарии Instagram/Facebook. Видимость — как у лидов:
 * CEO — все, РОП — отдела, менеджер — свои переписки.
 */
final class InboxService
{
    public const CHANNELS = ['INSTAGRAM_DM', 'INSTAGRAM_COMMENT', 'FACEBOOK_COMMENT'];

    public static function scope(Access $a): Builder
    {
        return $a->restrict(SocialThread::query(), 'lead.read',
            fn ($q, $uid) => $q->where('owner_id', $uid),
            fn ($q, $teamIds, $uid) => $q->where('owner_id', $uid)->orWhereIn('team_id', $teamIds ?: ['-']));
    }

    public static function thread(Access $a, string $id): SocialThread
    {
        return self::scope($a)->with(['lead:id,number,title', 'owner:id,full_name'])->whereKey($id)->firstOrFail();
    }

    public static function list(Access $a, ?string $channel, bool $unreadOnly = false, int $perPage = 50)
    {
        $items = self::scope($a)->with(['lead:id,number,title', 'owner:id,full_name'])
            ->when($channel, fn ($q) => $q->where('channel', $channel))
            ->when($unreadOnly, fn ($q) => $q->where('unread', '>', 0))
            ->orderByDesc('last_message_at')->paginate($perPage)->withQueryString();
        $last = SocialMessage::whereIn('id', function ($q) use ($items) {
            $q->selectRaw('(SELECT m.id FROM social_messages m WHERE m.thread_id = t.id ORDER BY m.created_at DESC LIMIT 1)')
                ->from('social_threads as t')->whereIn('t.id', $items->pluck('id')->all() ?: ['-']);
        })->get()->keyBy('thread_id');
        $unread = self::scope($a)->where('unread', '>', 0)->count();

        return ['items' => $items, 'last' => $last, 'unread' => $unread];
    }

    /** Сообщения переписки; открытие — прочитано. */
    public static function messages(Access $a, string $id)
    {
        $t = self::thread($a, $id);
        $rows = SocialMessage::with('author:id,full_name')->where('thread_id', $id)->orderBy('created_at')->limit(500)->get();
        $t->update(['unread' => 0]);

        return $rows;
    }

    /** Ответ из CRM: в Директ, публично под комментарием или в Директ на комментарий. */
    public static function reply(Access $a, string $id, string $text, ?string $commentId, string $mode)
    {
        $t = self::thread($a, $id);
        if ($t->channel === 'INSTAGRAM_DM') {
            $externalId = MetaService::sendDirect($t->peer_id, $text)['message_id'] ?? null;
        } else {
            if (! $commentId) {
                throw new BusinessRule(t('inbox.errors.pickComment'));
            }
            if (! SocialMessage::where('thread_id', $id)->where('external_id', $commentId)->exists()) {
                throw new BusinessRule(t('inbox.errors.foreignComment'));
            }
            $externalId = $mode === 'private'
                ? (MetaService::privateReply($commentId, $text)['message_id'] ?? null)
                : (MetaService::replyComment($commentId, $text, $t->channel === 'FACEBOOK_COMMENT')['id'] ?? null);
        }
        $now = now();
        SocialMessage::create([
            'thread_id' => $id, 'direction' => 'OUT',
            'text' => $mode === 'private' && $t->channel !== 'INSTAGRAM_DM' ? '[в Директ] '.$text : $text,
            'external_id' => $externalId, 'author_id' => $a->id(), 'created_at' => $now,
        ]);
        $t->update(['last_message_at' => $now, 'unread' => 0]);

        return self::messages($a, $id);
    }

    /** «Создать лид» из переписки (или привязать к найденному по Instagram). */
    public static function createLead(Access $a, string $id): SocialThread
    {
        $t = self::thread($a, $id);
        if (! $t->lead_id) {
            $first = SocialMessage::where('thread_id', $id)->where('direction', 'IN')->orderBy('created_at')->first();
            $settings = IntakeService::settings();
            $who = $t->peer_username ? '@'.$t->peer_username : ($t->peer_name ?? 'Instagram');
            $channel = $t->channel === 'INSTAGRAM_DM' ? 'Директ Instagram' : 'Комментарий';
            $res = IntakeService::intake([
                'title' => $who.' — '.$channel,
                'contactName' => $t->peer_name ?? $t->peer_username,
                'instagram' => $t->peer_username,
                'comment' => $first ? $channel.': '.$first->text : null,
                'sourceCode' => $t->channel === 'FACEBOOK_COMMENT' ? 'OTHER' : 'INSTAGRAM',
                'serviceId' => $settings['serviceId'],
                'ownerId' => $t->owner_id ?? $a->id(),
                'channel' => $channel,
            ]);
            $t->update(['lead_id' => $res['leadId']]);
        }

        return self::thread($a, $id);
    }
}
