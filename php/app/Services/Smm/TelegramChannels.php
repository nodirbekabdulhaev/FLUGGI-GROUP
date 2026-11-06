<?php

namespace App\Services\Smm;

use App\Exceptions\BusinessRule;
use App\Models\SmmPublication;
use App\Models\SmmPublicationTarget;
use App\Models\SmmTelegramChannel;
use App\Services\Telegram\TelegramApiError;
use App\Services\Telegram\TelegramClient;
use App\Support\Audit;
use App\Support\Secrets;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;

/**
 * Настройки → «Интеграции» → «Telegram-каналы для публикаций» (settings.manage):
 *  - добавить канал по @username, ссылке t.me/… или числовому id (-100…): getChat (это канал) + getChatMember(бот) —
 *    бот должен быть администратором с правом публиковать сообщения (can_post_messages);
 *  - «Проверить» — то же для сохранённого канала (название, @username и право обновляются);
 *  - удалить — мягко (опубликованные записи сохраняют название канала); пока канал выбран в запланированной
 *    или идущей публикации — нельзя; черновые и неудавшиеся места этого канала снимаются.
 */
final class TelegramChannels
{
    /** @return Collection<int, SmmTelegramChannel> */
    public static function list(): Collection
    {
        return SmmTelegramChannel::orderBy('title')->get();
    }

    /** «@name», «t.me/name», «https://t.me/name», «-1001234567890» → id для Bot API; неверный ввод — null. */
    public static function normalize(string $input): ?string
    {
        $v = trim($input);
        if (preg_match('#^(?:https?://)?(?:t|telegram)\.me/([A-Za-z0-9_]+)/?$#i', $v, $m)) {
            $v = $m[1];
        }
        if (preg_match('/^-?\d{5,20}$/', $v)) {
            return $v;
        }
        $v = ltrim($v, '@');

        return preg_match('/^[A-Za-z][A-Za-z0-9_]{3,31}$/', $v) ? '@'.$v : null;
    }

    /**
     * Проверка канала в Telegram.
     *
     * @return array{ok:bool, chatId?:string, title?:string, username?:?string, error?:string}
     */
    public static function check(string $chat): array
    {
        if (! TelegramClient::configured() || ! TelegramClient::botId()) {
            return ['ok' => false, 'error' => t('smm.tg.errors.botNotConfigured')];
        }
        try {
            $info = (array) TelegramClient::call('getChat', ['chat_id' => $chat]);
        } catch (TelegramApiError $e) {
            return ['ok' => false, 'error' => $e->status === 400 || $e->status === 403
                ? t('smm.tg.errors.notFound', ['bot' => self::bot()])
                : t('smm.tg.errors.api', ['error' => $e->description])];
        } catch (ConnectionException $e) {
            return ['ok' => false, 'error' => t('smm.tg.errors.api', ['error' => Secrets::redact($e->getMessage())])];
        }
        if (($info['type'] ?? null) !== 'channel') {
            return ['ok' => false, 'error' => t('smm.tg.errors.notChannel')];
        }
        $base = ['chatId' => (string) $info['id'], 'title' => mb_substr((string) ($info['title'] ?? $chat), 0, 255), 'username' => $info['username'] ?? null];
        try {
            $member = (array) TelegramClient::call('getChatMember', ['chat_id' => $base['chatId'], 'user_id' => TelegramClient::botId()]);
        } catch (TelegramApiError $e) {
            return $base + ['ok' => false, 'error' => t('smm.tg.errors.notAdmin', ['bot' => self::bot()])];
        } catch (ConnectionException $e) {
            return $base + ['ok' => false, 'error' => t('smm.tg.errors.api', ['error' => Secrets::redact($e->getMessage())])];
        }
        if (($member['status'] ?? null) !== 'administrator') {
            return $base + ['ok' => false, 'error' => t('smm.tg.errors.notAdmin', ['bot' => self::bot()])];
        }
        if (empty($member['can_post_messages'])) {
            return $base + ['ok' => false, 'error' => t('smm.tg.errors.cannotPost', ['bot' => self::bot()])];
        }

        return $base + ['ok' => true];
    }

    /** «@fluggi_bot» для подсказок. */
    public static function bot(): string
    {
        $name = TelegramClient::configuredUsername();

        return $name ? '@'.$name : t('smm.tg.theBot');
    }

    /** Добавить канал: только если проверка прошла (удалённый ранее — восстанавливается). */
    public static function add(string $input): SmmTelegramChannel
    {
        $chat = self::normalize($input);
        if (! $chat) {
            throw new BusinessRule(t('smm.tg.errors.invalid'), ['chat' => t('smm.tg.errors.invalid')]);
        }
        $r = self::check($chat);
        if (! $r['ok']) {
            throw new BusinessRule($r['error'], ['chat' => $r['error']]);
        }

        return DB::transaction(function () use ($r) {
            $ch = SmmTelegramChannel::withTrashed()->where('chat_id', $r['chatId'])->lockForUpdate()->first();
            if ($ch && ! $ch->trashed()) {
                throw new BusinessRule(t('smm.tg.errors.duplicate'), ['chat' => t('smm.tg.errors.duplicate')]);
            }
            $data = ['title' => $r['title'], 'username' => $r['username'], 'can_post' => true, 'checked_at' => now(), 'error' => null, 'deleted_at' => null];
            if ($ch) {
                $ch->update($data + ['created_by_id' => access()->id()]);
            } else {
                $ch = SmmTelegramChannel::create($data + ['chat_id' => $r['chatId'], 'created_by_id' => access()->id()]);
            }
            Audit::log('integrations.telegram_channel_added', 'smm_telegram_channel', $ch->id, [
                'chat_id' => ['old' => null, 'new' => $r['chatId']], 'title' => ['old' => null, 'new' => $r['title']], 'username' => ['old' => null, 'new' => $r['username']],
            ]);

            return $ch;
        });
    }

    /** Повторная проверка сохранённого канала. */
    public static function recheck(string $id): array
    {
        $ch = SmmTelegramChannel::findOrFail($id);
        $r = self::check($ch->chat_id);
        DB::transaction(function () use ($ch, $r) {
            $data = ['can_post' => $r['ok'], 'checked_at' => now(), 'error' => $r['ok'] ? null : mb_substr($r['error'], 0, 2000)]
                + (isset($r['title']) ? ['title' => $r['title'], 'username' => $r['username']] : []);
            $changes = Audit::diff($ch, $data, ['title', 'username', 'can_post']);
            $ch->update($data);
            Audit::log('integrations.telegram_channel_checked', 'smm_telegram_channel', $ch->id, ($changes ?? []) + ['result' => ['old' => null, 'new' => $r['ok'] ? 'ok' : $r['error']]]);
        });

        return $r;
    }

    public static function remove(string $id): void
    {
        $ch = SmmTelegramChannel::findOrFail($id);
        if (SmmPublicationTarget::where('channel_id', $ch->id)->whereIn('status', ['SCHEDULED', 'PUBLISHING'])->exists()) {
            throw new BusinessRule(t('smm.tg.errors.inUse'));
        }
        $publications = [];
        DB::transaction(function () use ($ch, &$publications) {
            $pending = SmmPublicationTarget::where('channel_id', $ch->id)->whereIn('status', ['DRAFT', 'FAILED'])->get();
            $publications = $pending->pluck('publication_id')->unique()->all();
            SmmPublicationTarget::whereIn('id', $pending->pluck('id'))->delete();
            Audit::log('integrations.telegram_channel_removed', 'smm_telegram_channel', $ch->id, ['title' => ['old' => $ch->title, 'new' => null], 'chat_id' => ['old' => $ch->chat_id, 'new' => null]]);
            $ch->delete();
        });
        foreach (SmmPublication::whereIn('id', $publications)->get() as $p) {
            SmmTargets::settle($p);
            SmmTargets::dropIfDone($p);
        }
    }
}
