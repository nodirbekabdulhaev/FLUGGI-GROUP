# Переменные окружения

Все секреты задаются только через `.env` (не коммитится). Шаблон — [`.env.example`](.env.example).
API проверяет переменные при старте и не запустится с некорректными значениями.

| Переменная | Где | Обязательна | Описание |
|---|---|---|---|
| `NODE_ENV` | все | — | `development` / `test` / `production`. В production cookie получают флаг `Secure` |
| `TZ` | все | — | `Asia/Tashkent` |
| `DATABASE_URL` | api, db | ✅ | MySQL 5.7+ / 8.0: `mysql://user:pass@host:3306/fluggi` (спецсимволы пароля — в URL-кодировке) |
| `TEST_DATABASE_URL` | тесты | для тестов | отдельная БД; интеграционные тесты её **очищают**. Должна отличаться от `DATABASE_URL` |
| `AUTH_SECRET` | api | ✅ | не короче 32 символов: `openssl rand -base64 48` |
| `APP_URL` | api | ✅ | публичный адрес интерфейса, напр. `https://crm.fluggi.uz`. Запросы с другим `Origin` отклоняются (CSRF) |
| `PORT` | api | — | порт API, по умолчанию `4000` |
| `SESSION_TTL_DAYS` | api | — | срок скользящей сессии, по умолчанию 7 |
| `LOG_LEVEL` | api | — | `info` по умолчанию |
| `TRUST_PROXY` | api | — | значение express `trust proxy` для определения IP за Nginx. По умолчанию `loopback, linklocal, uniquelocal` |
| `RATE_LIMIT_DISABLED` | api | — | `true` отключает rate limit. **Только для тестов** |
| `API_INTERNAL_URL` | web | ✅ | адрес API для сервера Next.js и прокси `/api/*`, напр. `http://api:4000` |
| `SEED_DEMO` | seed | — | создавать демо-данные; по умолчанию `true` вне production |
| `SEED_DEMO_PASSWORD` | seed | — | общий пароль демо-аккаунтов; пусто — сгенерировать и показать в консоли |
| `SEED_CEO_EMAIL`, `SEED_CEO_PASSWORD`, `SEED_CEO_NAME` | seed | для первого запуска prod | создаёт первого CEO |
| `MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD`, `MYSQL_ROOT_PASSWORD` | docker-compose.prod | prod | параметры контейнера MySQL |
| `TELEGRAM_BOT_TOKEN` | api, worker | — | токен бота от @BotFather. Пусто — Telegram выключен, уведомления только в CRM |
| `TELEGRAM_BOT_USERNAME` | api | — | имя бота без `@`; если не задано — берётся через `getMe` |
| `TELEGRAM_MODE` | api, worker | `polling` | `polling` — бот сам забирает сообщения (локально, без домена); `webhook` — Telegram присылает их на `/api/v1/telegram/webhook` (production) |
| `TELEGRAM_WEBHOOK_SECRET` | api | — | секрет заголовка `X-Telegram-Bot-Api-Secret-Token`; без него webhook отвечает 403 |
| `META_APP_SECRET` | api | — | секрет приложения Meta: проверка подписи webhook (X-Hub-Signature-256) |
| `META_VERIFY_TOKEN` | api | — | токен проверки при подключении webhook (любое слово) |
| `META_PAGE_ACCESS_TOKEN` | api | — | долгосрочный токен страницы: ответы в Директ/комментарии, чтение лидов таргета |
| `META_GRAPH_BASE` | api | `https://graph.facebook.com/v21.0` | адрес Graph API |
| `TELEGRAM_PROXY_URL` | api, worker | — | прокси для запросов к Telegram, если сервер не видит `api.telegram.org` напрямую: `http://`, `https://` или `socks5://` (можно с логином: `socks5://user:pass@host:port`) |
| `TELEGRAM_API_BASE` | api, worker | `https://api.telegram.org` | адрес Bot API (меняется только в тестах) |
| `SCHEDULER_ENABLED` | api, worker | `true` | `false` — выключить задачи по расписанию (отчёты, напоминания, просрочки, отметка отсутствующих) |
| `STORAGE_DRIVER` | api | — | `local` (по умолчанию) — файлы в папке на диске; `s3` — S3-совместимое хранилище |
| `STORAGE_LOCAL_DIR` | api | — | папка для `local`; по умолчанию `apps/api/storage`. В Docker — `/data/storage` (volume) |
| `STORAGE_ENDPOINT`, `STORAGE_REGION`, `STORAGE_ACCESS_KEY`, `STORAGE_SECRET_KEY`, `STORAGE_BUCKET` | api | для `s3` | Beget S3: endpoint `https://s3.ru1.storage.beget.cloud`, ключи и бакет — из панели Beget |
| `OUTBOX_IN_API` | api | — | `false` — события, отправку в Telegram и планировщик выполняет только worker. По умолчанию всё это работает и в API, чтобы при разработке не запускать worker |
| `CRON_SECRET` | api | — | Секрет (16+ символов) для `POST /api/v1/internal/cron`: фоновые задачи по cron на виртуальном хостинге без worker (deploy/beget/README.md). Пусто — эндпоинт выключен (404) |
