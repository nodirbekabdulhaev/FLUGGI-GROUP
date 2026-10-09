# Fluggi CRM/ERP — PHP-версия для хостинга

Web ERP/CRM FLUGGI GROUP (IT, Медиа, Маркетинг): лиды, сделки, КП, договоры, оплаты, проекты,
задачи, финансы, сотрудники, KPI, посещаемость, зарплата, уведомления и Telegram, аналитика.

- **Платформа:** PHP 8.2+ (Laravel 11) + MySQL 5.7/8.0 — обычный виртуальный хостинг (Beget), без Docker и Node.js на сервере.
- **Интерфейс:** узбекский (латиница) и русский, адаптивный для телефона, устанавливается как приложение (PWA).
- **Фоновые задачи:** cron раз в минуту — `php artisan schedule:run`.

## Установка на Beget

Инструкция: [`deploy/beget/README.md`](deploy/beget/README.md). Готовая сборка (код + зависимости)
публикуется GitHub Actions в ветку `hosting` после каждого изменения.

## Разработка

```bash
composer install && npm ci && npm run build
cp .env.example .env && php artisan key:generate     # укажите DB_* в .env
php artisan fluggi:install --ceo-email=you@example.com
php artisan serve
php vendor/bin/phpunit                                # тесты на MySQL (база fluggi_php_test)
```

Правила кода и устройство модулей — [`CONVENTIONS.md`](CONVENTIONS.md). Структура базы — раздел 13 ТЗ.
