<?php

namespace App\Support;

/**
 * Скрытие секретов в текстах ошибок, которые сохраняются в базе, показываются в интерфейсе или пишутся в лог.
 * Ошибки HTTP-клиента содержат полный адрес запроса — с токеном бота (…/bot<id>:<токен>/…) или access_token Meta.
 */
final class Secrets
{
    public static function redact(?string $text): string
    {
        $s = (string) $text;
        $s = (string) preg_replace('~bot\d+:[A-Za-z0-9_-]+~', 'bot***', $s);
        $s = (string) preg_replace('~(access_token|client_secret|appsecret_proof)=[^&\s"\'<>)]+~i', '$1=***', $s);
        $s = (string) preg_replace('~(OAuth|Bearer)\s+[A-Za-z0-9._-]{20,}~', '$1 ***', $s);
        foreach (['fluggi.telegram.token', 'fluggi.meta.page_access_token', 'fluggi.meta.app_secret', 'fluggi.meta.verify_token'] as $key) {
            $v = (string) config($key);
            if (strlen($v) >= 8) {
                $s = str_replace($v, '***', $s);
            }
        }

        return $s;
    }
}
