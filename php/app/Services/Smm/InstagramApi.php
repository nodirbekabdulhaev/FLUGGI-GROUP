<?php

namespace App\Services\Smm;

use App\Services\Integrations\MetaService;
use App\Support\Secrets;
use App\Support\Settings;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Instagram Graph API — публикация контента (Content Publishing) от имени аккаунта Instagram,
 * привязанного к странице Facebook (токен страницы META_PAGE_ACCESS_TOKEN).
 * Нужны права instagram_basic, instagram_content_publish, pages_read_engagement (и pages_show_list).
 *  - Reels: контейнер с upload_type=resumable, затем исходный файл целиком на rupload.facebook.com
 *    (Meta получает оригинал без пережатия у нас; перекодирует Meta сама).
 *  - Фото: контейнер с image_url — временная подписанная ссылка на файл в CRM.
 *  - Статус контейнера проверяется в следующих запусках cron, затем media_publish и permalink.
 * id аккаунта Instagram запоминается в настройке «instagram».
 */
final class InstagramApi
{
    public const SETTINGS_KEY = 'instagram';

    public const DEFAULTS = ['igUserId' => null, 'username' => null, 'checkedAt' => null, 'error' => null,
        'quotaUsage' => null, 'quotaTotal' => null, 'missingScopes' => []];

    /** Права токена, без которых публикация не работает. */
    public const REQUIRED_SCOPES = ['instagram_basic', 'instagram_content_publish', 'pages_read_engagement'];

    public static function token(): ?string
    {
        return config('fluggi.meta.page_access_token') ?: null;
    }

    public static function settings(): array
    {
        return array_replace(self::DEFAULTS, Settings::get(self::SETTINGS_KEY));
    }

    private static function remember(array $values): array
    {
        return Settings::save(self::SETTINGS_KEY, array_replace(self::settings(), $values));
    }

    /** Запрос к Graph API; ошибка Meta → InstagramError с текстом Meta. */
    private static function call(string $method, string $path, array $params = [], int $timeout = 30): array
    {
        $token = self::token();
        if (! $token) {
            throw new InstagramError(t('integrations.errors.noPageToken'));
        }
        $url = MetaService::GRAPH_BASE.'/'.ltrim($path, '/');
        try {
            $req = Http::timeout($timeout)->acceptJson();
            $res = $method === 'GET'
                ? $req->get($url, $params + ['access_token' => $token])
                : $req->asForm()->post($url, $params + ['access_token' => $token]);
        } catch (ConnectionException $e) {
            throw new InstagramError(t('integrations.errors.metaNetwork', ['error' => Secrets::redact($e->getMessage())]));
        }

        return self::json($res);
    }

    private static function json(Response $res): array
    {
        $json = $res->json() ?? [];
        if (! $res->successful() || isset($json['error'])) {
            $e = (array) ($json['error'] ?? []);
            $message = (string) ($e['error_user_msg'] ?? $e['message'] ?? ('HTTP '.$res->status()));
            $code = (int) ($e['code'] ?? 0);
            // Нет прав у токена: коды 10 и 200–299 или упоминание права в тексте
            $permission = $code === 10 || ($code >= 200 && $code < 300) || str_contains($message, 'instagram_content_publish');
            throw new InstagramError(
                $permission ? t('smm.ig.errors.permission', ['error' => $message]) : 'Meta: '.$message,
                $code,
                $permission,
            );
        }

        return is_array($json) ? $json : [];
    }

    /** id аккаунта Instagram страницы (из настройки или запросом GET /me?fields=instagram_business_account). */
    public static function igUserId(bool $refresh = false): string
    {
        $s = self::settings();
        if (! $refresh && $s['igUserId']) {
            return (string) $s['igUserId'];
        }
        $me = self::call('GET', 'me', ['fields' => 'id,name,instagram_business_account{id,username}']);
        $ig = $me['instagram_business_account'] ?? null;
        if (empty($ig['id'])) {
            self::remember(['igUserId' => null, 'username' => null, 'error' => t('smm.ig.errors.noAccount')]);
            throw new InstagramError(t('smm.ig.errors.noAccount'));
        }
        self::remember(['igUserId' => (string) $ig['id'], 'username' => $ig['username'] ?? null]);

        return (string) $ig['id'];
    }

    /**
     * Лимит публикаций за сутки (content_publishing_limit).
     *
     * @return array{usage:int, total:int}
     */
    public static function publishingLimit(string $igUserId): array
    {
        $r = self::call('GET', $igUserId.'/content_publishing_limit', ['fields' => 'config,quota_usage']);
        $row = (array) ($r['data'][0] ?? []);
        $limit = ['usage' => (int) ($row['quota_usage'] ?? 0), 'total' => (int) ($row['config']['quota_total'] ?? 50)];
        self::remember(['quotaUsage' => $limit['usage'], 'quotaTotal' => $limit['total']]);

        return $limit;
    }

    /** Лимит исчерпан → InstagramError. */
    public static function assertQuota(string $igUserId): void
    {
        $l = self::publishingLimit($igUserId);
        if ($l['total'] > 0 && $l['usage'] >= $l['total']) {
            throw new InstagramError(t('smm.ig.errors.quota', ['usage' => $l['usage'], 'total' => $l['total']]));
        }
    }

    /**
     * «Проверить доступ к публикации» (Настройки → Интеграции): аккаунт Instagram, права токена, лимит.
     *
     * @return array{ok:bool, username:?string, igUserId:?string, missingScopes:list<string>, quotaUsage:?int, quotaTotal:?int, error:?string}
     */
    public static function check(): array
    {
        $missing = [];
        $error = null;
        $limit = null;
        try {
            // Права токена: debug_token с самим токеном (если Meta не ответит — права проверит запрос лимита)
            try {
                $debug = self::call('GET', 'debug_token', ['input_token' => (string) self::token()]);
                $scopes = (array) ($debug['data']['scopes'] ?? []);
                if ($scopes) {
                    $missing = array_values(array_diff(self::REQUIRED_SCOPES, $scopes));
                }
            } catch (InstagramError $e) {
                if (! self::token()) {
                    throw $e;
                }
            }
            $ig = self::igUserId(true);
            $limit = self::publishingLimit($ig);
            if ($missing) {
                $error = t('smm.ig.errors.missingScopes', ['scopes' => implode(', ', $missing)]);
            }
        } catch (InstagramError $e) {
            $error = $e->getMessage();
        }
        $s = self::remember(['checkedAt' => now()->toIso8601ZuluString('millisecond'), 'error' => $error, 'missingScopes' => $missing]);

        return [
            'ok' => $error === null,
            'username' => $s['username'],
            'igUserId' => $s['igUserId'],
            'missingScopes' => $missing,
            'quotaUsage' => $limit['usage'] ?? null,
            'quotaTotal' => $limit['total'] ?? null,
            'error' => $error,
        ];
    }

    /**
     * Контейнер Reels для загрузки файла (resumable).
     *
     * @return array{id:string, uri:string}
     */
    public static function createReelsContainer(string $igUserId, ?string $caption, bool $shareToFeed, ?int $thumbOffsetMs, ?string $coverUrl): array
    {
        $params = array_filter([
            'media_type' => 'REELS',
            'upload_type' => 'resumable',
            'caption' => $caption,
            'share_to_feed' => $shareToFeed ? 'true' : 'false',
            'thumb_offset' => $thumbOffsetMs !== null ? (string) $thumbOffsetMs : null,
            'cover_url' => $coverUrl,
        ], fn ($v) => $v !== null && $v !== '');
        $r = self::call('POST', $igUserId.'/media', $params);
        if (empty($r['id'])) {
            throw new InstagramError('Meta: no container id');
        }
        $uri = (string) ($r['uri'] ?? 'https://rupload.facebook.com/ig-api-upload/'.basename(MetaService::GRAPH_BASE).'/'.$r['id']);

        return ['id' => (string) $r['id'], 'uri' => $uri];
    }

    /** Исходный файл целиком — на адрес загрузки контейнера (поток с диска, без чтения в память). */
    public static function uploadVideo(string $uri, string $path): void
    {
        $size = filesize($path);
        $fh = fopen($path, 'rb');
        if ($size === false || $fh === false) {
            throw new InstagramError(t('smm.ig.errors.fileMissing'));
        }
        try {
            $res = Http::timeout(1800)->connectTimeout(30)->withHeaders([
                'Authorization' => 'OAuth '.self::token(),
                'offset' => '0',
                'file_size' => (string) $size,
            ])->withBody(Utils::streamFor($fh), 'application/octet-stream')->post($uri);
        } catch (ConnectionException $e) {
            throw new InstagramError(t('integrations.errors.metaNetwork', ['error' => Secrets::redact($e->getMessage())]));
        } finally {
            if (is_resource($fh)) {
                fclose($fh);
            }
        }
        $json = self::json($res);
        if (array_key_exists('success', $json) && ! $json['success']) {
            throw new InstagramError('Meta: '.($json['debug_info']['message'] ?? 'upload failed'));
        }
    }

    public static function createImageContainer(string $igUserId, string $imageUrl, ?string $caption): string
    {
        $r = self::call('POST', $igUserId.'/media', array_filter(['image_url' => $imageUrl, 'caption' => $caption], fn ($v) => $v !== null && $v !== ''));
        if (empty($r['id'])) {
            throw new InstagramError('Meta: no container id');
        }

        return (string) $r['id'];
    }

    /** @return array{code:string, status:?string} FINISHED / IN_PROGRESS / ERROR / EXPIRED / PUBLISHED */
    public static function containerStatus(string $containerId): array
    {
        $r = self::call('GET', $containerId, ['fields' => 'status_code,status']);

        return ['code' => strtoupper((string) ($r['status_code'] ?? '')), 'status' => isset($r['status']) ? (string) $r['status'] : null];
    }

    public static function publish(string $igUserId, string $containerId): string
    {
        $r = self::call('POST', $igUserId.'/media_publish', ['creation_id' => $containerId], 60);
        if (empty($r['id'])) {
            throw new InstagramError('Meta: no media id');
        }

        return (string) $r['id'];
    }

    public static function permalink(string $mediaId): ?string
    {
        try {
            return self::call('GET', $mediaId, ['fields' => 'permalink'])['permalink'] ?? null;
        } catch (InstagramError) {
            return null;
        }
    }
}
