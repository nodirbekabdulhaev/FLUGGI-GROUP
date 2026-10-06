# Переменные окружения

Все секреты задаются только через `.env` (не коммитится). Шаблон — [`.env.example`](.env.example).
API проверяет переменные при старте и не запустится с некорректными значениями.

| Переменная | Где | Обязательна | Описание |
|---|---|---|---|
| `NODE_ENV` | все | — | `development` / `test` / `production`. В production cookie получают флаг `Secure` |
| `TZ` | все | — | `Asia/Tashkent` |
| `DATABASE_URL` | api, db | ✅ | PostgreSQL: `postgresql://user:pass@host:5432/fluggi?schema=public` |
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
| `POSTGRES_USER`, `POSTGRES_PASSWORD`, `POSTGRES_DB` | docker-compose.prod | prod | параметры контейнера PostgreSQL |
| `TELEGRAM_BOT_TOKEN`, `TELEGRAM_WEBHOOK_SECRET` | api | Phase 7 | зарезервированы, пока не используются |
| `STORAGE_ENDPOINT`, `STORAGE_REGION`, `STORAGE_ACCESS_KEY`, `STORAGE_SECRET_KEY`, `STORAGE_BUCKET` | api | Phase 2+ | S3-хранилище файлов (Beget S3 / MinIO), пока не используются |
