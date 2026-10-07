# Fluggi OS

Внутренняя CRM + Project Management + KPI + Finance система компании Fluggi.

```
Лид → Сделка → КП → Договор → Оплата → Проект → Задачи → Прибыль → KPI → Повторная продажа
```

## Статус

| Фаза | Содержание | Статус |
|---|---|---|
| 1. Foundation | монорепо, БД и миграции, вход и сессии, роли и права (RBAC), отделы, сотрудники, журнал аудита, outbox событий, layout и навигация, CI | ✅ готово |
| 2. CRM | лиды (оценка, этапы, причины потерь), клиенты и контакты, сделки, воронка с drag & drop, встречи, таймлайн, комментарии, история этапов, in-app уведомления, справочники и курс USD | ✅ готово |
| 3. Sales | КП с позициями, версиями, утверждением и PDF; договоры; оплаты с подтверждением РОП → авто-проект и комиссии по правилам; возвраты (сторно); файлы (local/S3) | ✅ готово |
| 4. Projects | проекты (списки, карточка, статусы), команда проекта, задачи с Kanban и drag & drop, шаблоны задач по услуге, просрочки и напоминания 1/3/7 дней, файлы и комментарии к задачам | ✅ готово |
| 5. Finance | расходы проекта и компании, финансовая карточка проекта (прибыль, маржа), финансовый дашборд за период, прибыль по проектам, утверждение и выплата комиссий, правила комиссий в настройках (вкл. «% от прибыли»), ввод сумм с разрядами, исправление неподтверждённой оплаты | ✅ готово |
| 6. KPI и сотрудники | дашборды по ролям (CEO, РОП, менеджер, исполнитель), KPI менеджеров/РОП/исполнителей, цели и выполнение, посещаемость с отметкой прихода/ухода и опозданиями, рабочие графики, зарплата (оклад + KPI-бонус + комиссия + бонусы − штраф) | ✅ готово |
| 7. Telegram и автоматизация | Telegram-бот, напоминания, cron, follow-up, ежедневный и еженедельный отчёты | следующая |
| 8. Аналитика | графики продаж, воронка, прогноз, LTV, client health, глобальный поиск, экспорт | запланировано |

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
packages/domain     чистые расчёты: lead score, деньги и курс, прогноз, конверсия, итоги КП, комиссии, сроки задач, прибыль и маржа
packages/db         Prisma: схема, миграции, seed, справочники
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

Создаёт роли и права, справочники CRM (услуги, источники, причины потерь, этапы воронки), рабочие графики (менеджеры 09:00–18:00, РОП 09:30–18:00, исполнители, обучение 08:00–12:00), правила комиссий по умолчанию (менеджер 10%, РОП 10% / 15% при среднем чеке > 3000 USD или > 15 заказов в месяц), шаблоны проектов (SMM, брендинг, сайт, таргет), 2 отдела продаж, 12 демо-сотрудников, демо-курс USD и демо-CRM: 10 клиентов, 22 лида, 10 сделок. Пароли в репозитории не хранятся:
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
pnpm --filter @fluggi/api dev:worker    # необязательно: в dev события и поиск просрочек обрабатывает сам API
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

## 8. Файлы

`STORAGE_DRIVER=local` (по умолчанию) — файлы лежат в `STORAGE_LOCAL_DIR`; `s3` — любое
S3-совместимое хранилище (Beget S3), см. [DEPLOYMENT.md](DEPLOYMENT.md). Тип файла проверяется
по содержимому (сигнатуре), лимит — 25 МБ.

## 9. Telegram-бот

Реализуется в **Phase 7**. Переменные `TELEGRAM_BOT_TOKEN` и `TELEGRAM_WEBHOOK_SECRET` уже
зарезервированы в `.env.example`; инструкция по созданию бота через @BotFather появится здесь
вместе с реализацией.

## 10. Переменные окружения

См. [ENVIRONMENT.md](ENVIRONMENT.md). Секреты — только в `.env`, файл не коммитится.
