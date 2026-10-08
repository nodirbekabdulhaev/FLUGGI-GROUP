# Развёртывание на Beget (shared hosting)

Нужно: тариф с PHP 8.2+ (лучше 8.3), MySQL, SSH, cron, SSL (Let's Encrypt из панели).

## 1. Домен и каталог

1. Панель Beget → «Домены и поддомены» → создайте поддомен `erp.example.uz`.
2. По SSH (или через «Файловый менеджер») загрузите проект в **отдельную** папку рядом с сайтом, например `~/erp.example.uz/app/` (не в `public_html`).
3. Document root поддомена должен указывать на `~/erp.example.uz/app/public`. В Beget это делается символической ссылкой:
   ```bash
   cd ~/erp.example.uz && rm -rf public_html && ln -s app/public public_html
   ```
   Если сменить document root нельзя — оставьте проект в `public_html`: корневой `.htaccess` перенаправит всё в `public/` и закроет `.env`, `vendor`, `storage`, `app` и т. д.
4. PHP: в панели выберите версию 8.2+ для сайта; для CLI используйте тот же бинарник, например `/usr/local/bin/php8.3` (`php -v`).

## 2. База данных и `.env`

1. Панель → «MySQL» → создайте БД и пользователя (кодировка `utf8mb4`).
2. ```bash
   cd ~/erp.example.uz/app
   cp .env.example .env
   nano .env      # APP_URL, DB_DATABASE, DB_USERNAME, DB_PASSWORD, MAIL_*, TELEGRAM_*
   ```
   `APP_DEBUG=false`, `APP_ENV=production`, `SESSION_SECURE_COOKIE=true` — не менять. Секреты не коммитятся в Git.

## 3. Установка

```bash
PHP=/usr/local/bin/php8.3 COMPOSER="php8.3 /usr/local/bin/composer" bash bin/deploy.sh --install
```
Скрипт ставит зависимости (`--no-dev`), генерирует `APP_KEY`, выполняет миграции, кэширует конфиг/маршруты/шаблоны и запускает `erp:install`
(название центра, email и пароль Super Admin). Если на тарифе нет Composer по SSH — выполните `composer install --no-dev` локально и загрузите каталог `vendor/`.

Проверка: откройте `https://erp.example.uz/login`, войдите, затем `/settings` → блок «Система» (Debug должен быть `off`).

## 4. Cron

Панель → «Cron» → добавить задание, каждую минуту:

```
* * * * * /usr/local/bin/php8.3 /home/USER/erp.example.uz/app/artisan schedule:run >/dev/null 2>&1
```
Дальше Laravel сам запускает очередь Telegram (каждые 5 минут), напоминания 08:00, долги 20:00, дневной отчёт 21:00, backup 03:00 и т. д.
Проверить расписание: `php artisan schedule:list`.

## 5. Telegram

В `.env` задайте `TELEGRAM_BOT_TOKEN`, `TELEGRAM_BOT_USERNAME`, `TELEGRAM_WEBHOOK_SECRET`, затем `php artisan config:cache && php artisan telegram:webhook`.

## 6. Резервные копии

- Ежедневно в 03:00 создаётся `storage/app/backups/backup_ГГГГ-ММ-ДД_ЧЧММСС.sql.gz` (хранится `BACKUP_KEEP` последних). Дамп делается на чистом PHP — `mysqldump` и `exec()` не нужны.
- Скачать/создать вручную: `/settings` → «Резервные копии».
- Восстановление: `php artisan backup:restore backup_….sql.gz` или кнопка «Восстановить» в `/settings` (только Super Admin, подтверждение `RESTORE`; перед восстановлением автоматически создаётся копия текущего состояния).
- Для надёжности периодически копируйте каталог `storage/app/backups` и `storage/app/private` (фото, чеки) за пределы хостинга.

## 7. Обновление

```bash
git pull && bash bin/deploy.sh      # миграции + пересборка кэша; сайт на время обновления в режиме обслуживания
```

## Чек-лист перед запуском

- [ ] HTTPS включён, `APP_URL` начинается с `https://`
- [ ] `APP_DEBUG=false`; `https://домен/.env` и `https://домен/storage/logs/laravel.log` отдают 403/404
- [ ] cron добавлен (`schedule:list` показывает задания), `storage/logs/cron-*.log` пишется
- [ ] первый backup создан и скачивается
- [ ] Telegram: тестовое сообщение из `/notifications/telegram` доходит
- [ ] пароли demo-пользователей не используются (`erp:install` без `--demo`)
