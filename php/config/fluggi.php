<?php

/** Настройки Fluggi из .env (после `php artisan optimize` env() вне config/ не читается). */
return [
    'session_ttl_days' => (int) env('SESSION_TTL_DAYS', 7),
    'cron_secret' => env('CRON_SECRET'),

    'telegram' => [
        'token' => env('TELEGRAM_BOT_TOKEN'),
        'username' => env('TELEGRAM_BOT_USERNAME'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        // Если хостинг не достаёт до api.telegram.org: свой адрес-посредник (например, Cloudflare Worker)
        // или прокси (http://… / socks5h://логин:пароль@хост:порт); IPv4 — только при проблемах с IPv6
        'api_base' => rtrim((string) env('TELEGRAM_API_BASE', 'https://api.telegram.org'), '/'),
        'proxy' => env('TELEGRAM_PROXY'),
        'force_ipv4' => (bool) env('TELEGRAM_FORCE_IPV4', false),
        // Лимиты загрузки файлов ботом (api.telegram.org): видео/документы — 50 МБ, фото — 10 МБ
        'upload_video_mb' => 50,
        'upload_photo_mb' => 10,
    ],

    'meta' => [
        'app_secret' => env('META_APP_SECRET'),
        'verify_token' => env('META_VERIFY_TOKEN'),
        'page_access_token' => env('META_PAGE_ACCESS_TOKEN'),
    ],

    // Web Push (системные уведомления со звуком): ключи VAPID — php artisan fluggi:webpush-keys
    'webpush' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        // Контакт для push-сервисов (mailto: или https:); по умолчанию — адрес CRM
        'subject' => env('VAPID_SUBJECT'),
        // Push-сервисы браузеров: запросы уходят только на эти домены (защита от подстановки адреса)
        'hosts' => ['fcm.googleapis.com', 'android.googleapis.com', 'updates.push.services.mozilla.com', 'push.services.mozilla.com',
            'web.push.apple.com', 'notify.windows.com', 'push.apple.com'],
    ],

    // Автопубликация SMM (Instagram, Telegram-каналы): файлы — storage/app/smm-media (не публично)
    'smm_media' => [
        'max_video_mb' => (int) env('SMM_MEDIA_MAX_VIDEO_MB', 1024),
        'max_image_mb' => 8,
        'chunk_mb' => (int) env('SMM_MEDIA_CHUNK_MB', 4),
        // Сколько хранить файл неудавшейся публикации и брошенные части загрузки
        'failed_keep_days' => 7,
        'chunks_keep_hours' => 6,
    ],

    'files' => [
        // Разрешённые типы и лимит загрузки (ТЗ §46)
        'max_bytes' => 25 * 1024 * 1024,
        'types' => [
            'application/pdf' => ['pdf'],
            'image/png' => ['png'],
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/webp' => ['webp'],
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
            'application/zip' => ['zip'],
        ],
    ],
];
