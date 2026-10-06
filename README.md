# Fluggi OS

Внутренняя CRM + Project Management + KPI + Finance система компании Fluggi.

```
Лид → Сделка → КП → Договор → Оплата → Проект → Задачи → Прибыль → KPI → Повторная продажа
```

## Статус

| Фаза | Содержание | Статус |
|---|---|---|
| 1. Foundation | монорепо, БД и миграции, вход и сессии, роли и права (RBAC), отделы, сотрудники, журнал аудита, outbox событий, layout и навигация, CI | ✅ готово |
| 2. CRM | лиды, клиенты, сделки, воронка, встречи | следующая |
| 3–8 | продажи, проекты, финансы, KPI, Telegram, аналитика | запланировано |

Разделы меню, которые ещё не реализованы, открываются с явной пометкой «Раздел в разработке» и номером фазы — без вымышленных данных.

| Документ | Содержание |
|---|---|
| [ARCHITECTURE.md](ARCHITECTURE.md) | архитектура, стек, структура, события, безопасность, этапы |
| [DATABASE.md](DATABASE.md) | ERD и таблицы |
| [API.md](API.md) | REST API |
| [DEPLOYMENT.md](DEPLOYMENT.md) | развёртывание на Beget VPS |
| [ENVIRONMENT.md](ENVIRONMENT.md) | переменные окружения |
| [docs/PERMISSIONS.md](docs/PERMISSIONS.md) | матрица прав |
| [docs/BUSINESS_RULES.md](docs/BUSINESS_RULES.md) | бизнес-правила и формулы |

## Структура

```
apps/api            NestJS — REST API, бизнес-логика, RBAC, аудит; worker.ts — фоновые задачи
apps/web            Next.js — интерфейс
packages/contracts  общие Zod-схемы, enum'ы, матрица прав
packages/db         Prisma: схема, миграции, seed
e2e                 Playwright
```

## 1. Установка

Нужны **Node.js 22+**, **pnpm 10** (`corepack enable`), **PostgreSQL 16** (или Docker).

```bash
pnpm install
cp .env.example .env      # заполните AUTH_SECRET: openssl rand -base64 48
```

Все переменные описаны в [ENVIRONMENT.md](ENVIRONMENT.md).

## 2. База данных

Через Docker:

```bash
docker compose up -d postgres
```

Или своя PostgreSQL: создайте пользователя и БД и укажите `DATABASE_URL` в `.env`.

```sql
CREATE USER fluggi WITH PASSWORD 'fluggi' CREATEDB;
CREATE DATABASE fluggi OWNER fluggi;
CREATE DATABASE fluggi_test OWNER fluggi;   -- для интеграционных тестов
```

## 3. Миграции

```bash
pnpm db:migrate      # разработка: применить и создать новые миграции
pnpm db:deploy       # production: только применить существующие
```

Схема меняется **только** через миграции (`packages/db/prisma/migrations`).

## 4. Seed (демо-данные)

```bash
pnpm db:seed
```

Создаёт роли и права, 2 отдела продаж и 12 демо-сотрудников. Пароли в репозитории не хранятся:
если задан `SEED_DEMO_PASSWORD` — он используется для всех демо-аккаунтов, иначе пароли
генерируются и печатаются в консоль **один раз**.

| Роль | Логин |
|---|---|
| CEO | `ceo@fluggi.demo` |
| РОП (отдел 1 / отдел 2) | `rop@fluggi.demo`, `rop2@fluggi.demo` |
| Менеджеры | `manager1@…`, `manager2@…` (отдел 1), `manager3@…` (отдел 2) |
| Исполнители | `smm@…`, `designer@…`, `video@…`, `target@…`, `dev@…` |
| HR / Админ | `hr@fluggi.demo` |

## 5. Разработка

```bash
pnpm build                              # один раз: собрать общие пакеты
pnpm --filter @fluggi/api dev           # API → http://localhost:4000/api/v1
pnpm --filter @fluggi/api dev:worker    # worker (outbox-события)
pnpm --filter @fluggi/web dev           # Web → http://localhost:3000
```

Браузер обращается только к `localhost:3000`; запросы `/api/*` Next.js проксирует в API.

## 6. Проверки и тесты

```bash
pnpm lint                 # prettier --check
pnpm typecheck
pnpm test                 # unit-тесты
pnpm test:integration     # API + реальная PostgreSQL (TEST_DATABASE_URL, БД очищается!)
pnpm test:e2e             # Playwright; api и web должны быть запущены, БД — после seed
```

Для E2E запустите API с `RATE_LIMIT_DISABLED=true` (тест много раз входит в систему) и задайте
`SEED_DEMO_PASSWORD`, тот же, что при seed.

## 7. Production-сборка

Процессы читают переменные из окружения (файл `.env` сами не загружают):

```bash
pnpm build
set -a && . ./.env && set +a
node apps/api/dist/main.js        # API
node apps/api/dist/worker.js      # worker
cd apps/web && pnpm start         # Web
```

Docker и Beget VPS — в [DEPLOYMENT.md](DEPLOYMENT.md).

## 8. Telegram-бот

Реализуется в **Phase 7**. Переменные `TELEGRAM_BOT_TOKEN` и `TELEGRAM_WEBHOOK_SECRET` уже
зарезервированы в `.env.example`; инструкция по созданию бота через @BotFather появится здесь
вместе с реализацией.

## 9. Переменные окружения

См. [ENVIRONMENT.md](ENVIRONMENT.md). Секреты — только в `.env`, файл не коммитится.
