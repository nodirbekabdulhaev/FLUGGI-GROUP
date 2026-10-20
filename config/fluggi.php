<?php

/** Настройки Fluggi из .env (после `php artisan optimize` env() вне config/ не читается). */
return [
    'session_ttl_days' => (int) env('SESSION_TTL_DAYS', 7),
    'cron_secret' => env('CRON_SECRET'),

    'telegram' => [
        'token' => env('TELEGRAM_BOT_TOKEN'),
        'username' => env('TELEGRAM_BOT_USERNAME'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
    ],

    'meta' => [
        'app_secret' => env('META_APP_SECRET'),
        'verify_token' => env('META_VERIFY_TOKEN'),
        'page_access_token' => env('META_PAGE_ACCESS_TOKEN'),
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
