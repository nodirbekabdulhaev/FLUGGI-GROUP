<?php

namespace App\Services\Chat;

use App\Exceptions\BusinessRule;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\User;
use App\Services\Notifications\Notifier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Чат сотрудников: личная переписка (обновление — опросом, без websockets). Новое сообщение —
 * уведомление в Telegram, но не чаще одного, пока получатель не прочитал переписку.
 */
final class ChatService
{
    private static function iso($v): ?string
    {
        return $v?->toIso8601ZuluString('millisecond');
    }

    /** С кем можно переписываться: все активные сотрудники. */
    public static function contacts(string $userId): array
    {
        return User::with(['role', 'team'])->where('status', 'ACTIVE')->where('id', '<>', $userId)
            ->orderBy('full_name')->get()
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->full_name, 'role' => t('roles.'.$u->role->code), 'team' => $u->team?->name])
            ->all();
    }

    /** Участник переписки, иначе 404. */
    private static function member(string $userId, string $conversationId): void
    {
        abort_unless(ConversationMember::where('conversation_id', $conversationId)->where('user_id', $userId)->exists(), 404);
    }

    /** @return array<string,int> conversation_id → непрочитанных */
    private static function unreadByConversation(string $userId): array
    {
        $rows = DB::select(
            'SELECT m.conversation_id, COUNT(*) AS n FROM chat_messages m
             JOIN conversation_members cm ON cm.conversation_id = m.conversation_id AND cm.user_id = ?
             WHERE m.created_at > cm.last_read_at AND m.author_id <> ?
             GROUP BY m.conversation_id',
            [$userId, $userId],
        );

        return collect($rows)->mapWithKeys(fn ($r) => [$r->conversation_id => (int) $r->n])->all();
    }

    public static function unreadTotal(string $userId): int
    {
        return array_sum(self::unreadByConversation($userId));
    }

    public static function list(User $me): array
    {
        $rows = Conversation::whereHas('members', fn ($q) => $q->where('user_id', $me->id))
            ->with(['members.user' => fn ($q) => $q->withTrashed()->with('role')])
            ->orderByDesc('last_message_at')->limit(100)->get();
        $last = ChatMessage::whereIn('id', function ($q) use ($rows) {
            $q->selectRaw('(SELECT m2.id FROM chat_messages m2 WHERE m2.conversation_id = c.id ORDER BY m2.created_at DESC LIMIT 1)')
                ->from('conversations as c')->whereIn('c.id', $rows->pluck('id')->all() ?: ['-']);
        })->get()->keyBy('conversation_id');
        $unread = self::unreadByConversation($me->id);
        $out = [];
        foreach ($rows as $c) {
            $peer = $c->members->firstWhere('user_id', '<>', $me->id)?->user ?? $me;
            $m = $last->get($c->id);
            $item = [
                'id' => $c->id,
                'peer' => ['id' => $peer->id, 'name' => $peer->full_name, 'role' => t('roles.'.$peer->role->code)],
                'lastMessage' => $m ? ['body' => $m->body, 'createdAt' => self::iso($m->created_at), 'mine' => $m->author_id === $me->id] : null,
                'unread' => $unread[$c->id] ?? 0,
            ];
            if ($item['lastMessage'] || $item['unread']) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /** Открыть (или создать) личную переписку с сотрудником. */
    public static function direct(string $meId, string $userId): string
    {
        if ($userId === $meId) {
            throw new BusinessRule(t('chat.errors.self'));
        }
        abort_unless(User::where('status', 'ACTIVE')->whereKey($userId)->exists(), 404);
        $ids = [$meId, $userId];
        sort($ids);
        $key = implode(':', $ids);
        if ($existing = Conversation::where('direct_key', $key)->value('id')) {
            return $existing;
        }
        try {
            return DB::transaction(function () use ($key, $meId, $userId) {
                $c = Conversation::create(['direct_key' => $key, 'last_message_at' => now()]);
                foreach ([$meId, $userId] as $uid) {
                    ConversationMember::insert(['conversation_id' => $c->id, 'user_id' => $uid,
                        'last_read_at' => now()->format('Y-m-d H:i:s.v'), 'joined_at' => now()->format('Y-m-d H:i:s.v')]);
                }

                return $c->id;
            });
        } catch (UniqueConstraintViolationException) {
            // Параллельный запрос уже создал переписку
            return Conversation::where('direct_key', $key)->value('id');
        }
    }

    private static function dto(ChatMessage $m, string $meId): array
    {
        return [
            'id' => $m->id,
            'conversationId' => $m->conversation_id,
            'author' => ['id' => $m->author_id, 'name' => $m->author?->full_name],
            'body' => $m->body,
            'createdAt' => self::iso($m->created_at),
            'time' => dt($m->created_at),
            'mine' => $m->author_id === $meId,
        ];
    }

    public static function messages(string $meId, string $conversationId, ?string $before = null, int $limit = 50): array
    {
        self::member($meId, $conversationId);

        return ChatMessage::with(['author' => fn ($q) => $q->withTrashed()])->where('conversation_id', $conversationId)
            ->when($before, fn ($q) => $q->where('created_at', '<', \Carbon\CarbonImmutable::parse($before)->utc()))
            ->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get()
            ->reverse()->values()->map(fn ($m) => self::dto($m, $meId))->all();
    }

    public static function send(User $me, string $conversationId, string $body): array
    {
        self::member($me->id, $conversationId);
        $now = now();
        // Время с миллисекундами: «прочитано» сравнивается с моментом сообщения
        $stamp = $now->format('Y-m-d H:i:s.v');
        $m = DB::transaction(function () use ($me, $conversationId, $body, $now, $stamp) {
            $m = ChatMessage::create(['id' => (string) Str::uuid(), 'conversation_id' => $conversationId, 'author_id' => $me->id, 'body' => $body, 'created_at' => $now]);
            Conversation::whereKey($conversationId)->update(['last_message_at' => $stamp]);
            ConversationMember::where('conversation_id', $conversationId)->where('user_id', $me->id)->update(['last_read_at' => $stamp]);

            return $m;
        });
        // Telegram: один раз на «пачку» непрочитанных
        $others = ConversationMember::where('conversation_id', $conversationId)->where('user_id', '<>', $me->id)->get();
        foreach ($others as $o) {
            if ($o->notified_at && $o->notified_at->gte($o->last_read_at)) {
                continue;
            }
            ConversationMember::where('conversation_id', $conversationId)->where('user_id', $o->user_id)->update(['notified_at' => $stamp]);
            Notifier::notify(
                [$o->user_id],
                'chat.message',
                ['💬 '.$me->full_name, mb_strlen($body) > 300 ? mb_substr($body, 0, 300).'…' : $body],
                '/dashboard?chat='.$conversationId,
                null,
                inApp: false,
            );
        }
        $m->setRelation('author', $me);

        return self::dto($m, $me->id);
    }

    public static function read(string $meId, string $conversationId): void
    {
        self::member($meId, $conversationId);
        ConversationMember::where('conversation_id', $conversationId)->where('user_id', $meId)->update(['last_read_at' => now()->format('Y-m-d H:i:s.v')]);
    }
}
