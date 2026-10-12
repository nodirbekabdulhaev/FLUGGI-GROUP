<?php

namespace App\Services\Integrations;

use App\Exceptions\BusinessRule;
use App\Models\Lead;
use App\Models\Setting;
use App\Models\SocialMessage;
use App\Models\SocialThread;
use App\Models\User;
use App\Services\Notifications\Notifier;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Instagram / Facebook через Meta Graph API:
 *  • Директ → переписка во «Входящих», лид (настройка «из Директа»);
 *  • комментарии → переписка, лид по ключевым словам («цена», «сколько»…);
 *  • лид-формы таргета (leadgen) → лид с источником «Таргет», кампанией и объявлением.
 * Подпись каждого webhook проверяется секретом приложения (X-Hub-Signature-256).
 */
final class MetaService
{
    public const GRAPH_BASE = 'https://graph.facebook.com/v21.0';

    public const HEALTH_KEY = 'meta.health';

    private static function token(): ?string
    {
        return config('fluggi.meta.page_access_token') ?: null;
    }

    private static function graph(string $method, string $path, array $params = [], ?array $body = null): array
    {
        $token = self::token();
        if (! $token) {
            throw new BusinessRule(t('integrations.errors.noPageToken'));
        }
        try {
            $req = Http::timeout(15)->acceptJson();
            $url = self::GRAPH_BASE.'/'.ltrim($path, '/').'?'.http_build_query($params + ['access_token' => $token]);
            $res = $method === 'GET' ? $req->get($url) : ($body !== null ? $req->asJson()->post($url, $body) : $req->post($url));
        } catch (ConnectionException $e) {
            throw new BusinessRule(t('integrations.errors.metaNetwork', ['error' => $e->getMessage()]));
        }
        $json = $res->json() ?? [];
        if (! $res->successful()) {
            $message = $json['error']['message'] ?? 'HTTP '.$res->status();
            self::health($message);
            throw new BusinessRule('Meta: '.$message);
        }

        return $json;
    }

    private static function health(?string $error): void
    {
        Setting::updateOrCreate(['key' => self::HEALTH_KEY], ['value' => ['at' => now()->toIso8601ZuluString('millisecond'), 'error' => $error]]);
    }

    /** GET-проверка при подключении webhook в кабинете Meta. */
    public static function verify(?string $mode, ?string $token, ?string $challenge): ?string
    {
        $expected = config('fluggi.meta.verify_token');
        if ($mode === 'subscribe' && $expected && is_string($token) && hash_equals((string) $expected, $token) && $challenge) {
            return $challenge;
        }

        return null;
    }

    /** Подпись X-Hub-Signature-256 = sha256 HMAC тела запроса секретом приложения. */
    public static function signatureValid(string $raw, ?string $header): bool
    {
        $secret = config('fluggi.meta.app_secret');
        if (! $secret || ! $header || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $raw, $secret), $header);
    }

    public static function handle(array $body): void
    {
        $object = $body['object'] ?? null;
        foreach ((array) ($body['entry'] ?? []) as $entry) {
            $ownId = $entry['id'] ?? null;
            foreach ((array) ($entry['messaging'] ?? []) as $m) {
                self::safe(fn () => self::onMessage((array) $m, $ownId, $object));
            }
            foreach ((array) ($entry['changes'] ?? []) as $c) {
                $field = $c['field'] ?? null;
                if ($field === 'comments' || $field === 'feed') {
                    self::safe(fn () => self::onComment((array) ($c['value'] ?? []), $object));
                }
                if ($field === 'leadgen') {
                    self::safe(fn () => self::onLeadgen((array) ($c['value'] ?? [])));
                }
            }
        }
        self::health(null);
    }

    /** Ошибка одного события не должна терять остальные. */
    private static function safe(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            Log::warning('Meta event failed: '.$e->getMessage());
            self::health($e->getMessage());
        }
    }

    private static function thread(string $channel, string $peerId, array $peer): SocialThread
    {
        $existing = SocialThread::where('channel', $channel)->where('peer_id', $peerId)->first();
        if ($existing) {
            if ((! empty($peer['username']) && ! $existing->peer_username) || (! empty($peer['name']) && ! $existing->peer_name)) {
                $existing->update([
                    'peer_username' => $existing->peer_username ?? ($peer['username'] ?? null),
                    'peer_name' => $existing->peer_name ?? ($peer['name'] ?? null),
                ]);
            }

            return $existing;
        }
        $settings = IntakeService::settings();
        $ownerId = IntakeService::pickOwner($settings['ownerId'], $settings['teamId']);

        return SocialThread::create([
            'channel' => $channel, 'peer_id' => $peerId, 'peer_name' => $peer['name'] ?? null, 'peer_username' => $peer['username'] ?? null,
            'owner_id' => $ownerId, 'team_id' => User::whereKey($ownerId)->value('team_id'), 'last_message_at' => now(),
        ]);
    }

    private static function addMessage(SocialThread $t, string $direction, string $text, ?string $externalId, ?string $mediaId, CarbonImmutable $at): ?SocialMessage
    {
        if ($externalId && SocialMessage::where('external_id', $externalId)->exists()) {
            return null;
        }
        $msg = SocialMessage::create(['thread_id' => $t->id, 'direction' => $direction, 'text' => mb_substr($text, 0, 4000),
            'external_id' => $externalId, 'media_id' => $mediaId, 'created_at' => $at]);
        $t->last_message_at = $at;
        if ($direction === 'IN') {
            $t->unread = $t->unread + 1;
        }
        $t->save();

        return $msg;
    }

    /** Лид из переписки: дубль ищется по Instagram; ссылка лида сохраняется в переписке. */
    private static function leadFor(SocialThread $t, string $text, string $channel): void
    {
        if ($t->lead_id) {
            return;
        }
        $settings = IntakeService::settings();
        $who = $t->peer_username ? '@'.$t->peer_username : ($t->peer_name ?? 'Instagram');
        $res = IntakeService::intake([
            'title' => $who.' — '.$channel,
            'contactName' => $t->peer_name ?? $t->peer_username,
            'instagram' => $t->peer_username,
            'comment' => $channel.': '.$text,
            'sourceCode' => 'INSTAGRAM',
            'serviceId' => $settings['serviceId'],
            'ownerId' => $t->owner_id,
            'channel' => $channel,
        ]);
        $t->update(['lead_id' => $res['leadId']]);
    }

    private static function onMessage(array $m, ?string $ownId, ?string $object): void
    {
        $message = $m['message'] ?? null;
        $sender = $m['sender']['id'] ?? null;
        $text = $message['text'] ?? (! empty($message['attachments']) ? '[вложение]' : null);
        if (! $message || ! $text || ! $sender) {
            return;
        }
        $echo = ! empty($message['is_echo']) || ($ownId !== null && $sender === $ownId);
        $peerId = $echo ? ($m['recipient']['id'] ?? null) : $sender;
        if (! $peerId) {
            return;
        }
        $profile = $echo ? [] : self::profile((string) $peerId, $object);
        $t = self::thread('INSTAGRAM_DM', (string) $peerId, $profile);
        $at = isset($m['timestamp']) ? CarbonImmutable::createFromTimestampMsUTC((int) $m['timestamp']) : CarbonImmutable::now('UTC');
        $msg = self::addMessage($t, $echo ? 'OUT' : 'IN', $text, $message['mid'] ?? null, null, $at);
        if (! $msg || $echo) {
            return;
        }
        if (IntakeService::settings()['autoLeadFromDirect']) {
            self::leadFor($t, $text, 'Директ Instagram');
        }
        $who = $t->peer_username ? '@'.$t->peer_username : $t->peer_name;
        Notifier::notify([$t->owner_id], 'social.message', fn () => [t('inbox.notify.direct', ['who' => $who ?? t('inbox.notify.message')]), mb_substr($text, 0, 300)], '/inbox?thread='.$t->id);
    }

    /** Имя и username собеседника (Instagram User Profile API); без токена — пусто. */
    private static function profile(string $id, ?string $object): array
    {
        if ($object !== 'instagram' || ! self::token()) {
            return [];
        }
        try {
            $p = self::graph('GET', $id, ['fields' => 'name,username']);

            return ['name' => $p['name'] ?? null, 'username' => $p['username'] ?? null];
        } catch (Throwable) {
            return [];
        }
    }

    private static function onComment(array $v, ?string $object): void
    {
        $from = (array) ($v['from'] ?? []);
        $commentId = $v['id'] ?? $v['comment_id'] ?? null;
        $text = $v['text'] ?? $v['message'] ?? null;
        if (empty($from['id']) || ! $commentId || ! $text) {
            return;
        }
        if ($object === 'page' && ($v['item'] ?? null) !== 'comment') {
            return;
        }
        if (! empty($v['verb']) && $v['verb'] !== 'add') {
            return;
        }
        $channel = $object === 'page' ? 'FACEBOOK_COMMENT' : 'INSTAGRAM_COMMENT';
        $t = self::thread($channel, (string) $from['id'], ['name' => $from['name'] ?? null, 'username' => $from['username'] ?? null]);
        $msg = self::addMessage($t, 'IN', (string) $text, (string) $commentId, $v['media']['id'] ?? ($v['post_id'] ?? null), CarbonImmutable::now('UTC'));
        if (! $msg) {
            return;
        }
        $s = IntakeService::settings();
        $lower = mb_strtolower((string) $text);
        $hot = $s['autoLeadFromComments'] === 'all' || ($s['autoLeadFromComments'] === 'keywords'
            && collect($s['commentKeywords'])->contains(fn ($k) => str_contains($lower, mb_strtolower($k))));
        if ($hot) {
            self::leadFor($t, (string) $text, 'Комментарий');
        }
        $who = ! empty($from['username']) ? '@'.$from['username'] : ($from['name'] ?? '');
        Notifier::notify([$t->owner_id], 'social.message', fn () => [t('inbox.notify.comment', ['who' => $who]), mb_substr((string) $text, 0, 300)], '/inbox?thread='.$t->id);
    }

    /** Лид-форма таргета: данные лида запрашиваются у Meta по leadgen_id. */
    private static function onLeadgen(array $v): void
    {
        $id = $v['leadgen_id'] ?? null;
        if (! $id) {
            return;
        }
        $lead = self::graph('GET', (string) $id, ['fields' => 'field_data,campaign_name,ad_name,form_id,platform,created_time']);
        $f = [];
        foreach ((array) ($lead['field_data'] ?? []) as $x) {
            $f[$x['name']] = implode(', ', (array) ($x['values'] ?? []));
        }
        $pick = function (string ...$keys) use ($f) {
            foreach ($keys as $k) {
                if (! empty($f[$k])) {
                    return $f[$k];
                }
            }

            return null;
        };
        $name = $pick('full_name') ?? (trim(implode(' ', array_filter([$pick('first_name'), $pick('last_name')]))) ?: null);
        $known = ['full_name', 'first_name', 'last_name', 'phone_number', 'email', 'company_name'];
        $extra = [];
        foreach ($f as $k => $val) {
            if (! in_array($k, $known, true)) {
                $extra[] = $k.': '.$val;
            }
        }
        $s = IntakeService::settings();
        $comment = implode("\n", array_filter([
            'Таргет '.(($lead['platform'] ?? null) === 'ig' ? 'Instagram' : 'Facebook'),
            ! empty($lead['campaign_name']) ? 'Кампания: '.$lead['campaign_name'] : null,
            ! empty($lead['ad_name']) ? 'Объявление: '.$lead['ad_name'] : null,
            ...$extra,
        ]));
        IntakeService::intake([
            'title' => ($pick('company_name') ?? $name ?? 'Лид таргета').' — '.($lead['campaign_name'] ?? 'таргет'),
            'contactName' => $name,
            'companyName' => $pick('company_name'),
            'phone' => $pick('phone_number'),
            'email' => $pick('email'),
            'comment' => $comment,
            'sourceCode' => 'TARGET',
            'serviceId' => $s['serviceId'],
            'ownerId' => $s['ownerId'],
            'teamId' => $s['teamId'],
            'channel' => 'Таргет (лид-форма)',
        ]);
    }

    // ─────────────── Ответы из CRM ───────────────

    public static function sendDirect(string $peerId, string $text): array
    {
        return self::graph('POST', 'me/messages', [], ['recipient' => ['id' => $peerId], 'message' => ['text' => $text], 'messaging_type' => 'RESPONSE']);
    }

    /** Публичный ответ на комментарий (Instagram: /replies; Facebook: /comments). */
    public static function replyComment(string $commentId, string $text, bool $facebook): array
    {
        return self::graph('POST', $commentId.'/'.($facebook ? 'comments' : 'replies'), ['message' => $text]);
    }

    /** Ответ на комментарий в Директ (private reply). */
    public static function privateReply(string $commentId, string $text): array
    {
        return self::graph('POST', 'me/messages', [], ['recipient' => ['comment_id' => $commentId], 'message' => ['text' => $text]]);
    }

    public static function status(): array
    {
        $missing = [];
        foreach (['META_APP_SECRET' => 'app_secret', 'META_VERIFY_TOKEN' => 'verify_token', 'META_PAGE_ACCESS_TOKEN' => 'page_access_token'] as $env => $key) {
            if (! config('fluggi.meta.'.$key)) {
                $missing[] = $env;
            }
        }
        $h = Setting::find(self::HEALTH_KEY)?->value;

        return [
            'configured' => ! $missing,
            'missing' => $missing,
            'webhookUrl' => rtrim((string) config('app.url'), '/').'/hooks/meta',
            'lastEventAt' => $h['at'] ?? null,
            'lastError' => $h['error'] ?? null,
            'threads' => SocialThread::count(),
            'adsLeads' => Lead::whereHas('source', fn ($q) => $q->where('code', 'TARGET'))->count(),
        ];
    }
}
