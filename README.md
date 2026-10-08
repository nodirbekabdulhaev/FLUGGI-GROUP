# FLUGGI EDU ERP

ERP / CRM для учебного центра: **лид → продажа → ученик → группа → посещение → оплата → продление → доход → прибыль** в одной системе.
Технические требования — [ТЗ v1.0](#соответствие-тз). Работает на обычном shared-хостинге (Beget): PHP + MySQL + cron, без VPS, Docker и Node.js-сервера.

| | |
|---|---|
| Backend | PHP 8.2+, Laravel 12, Sanctum, DomPDF |
| База | MySQL 8 / MariaDB 10.6+ (в тестах также SQLite) |
| Frontend | Blade, Tailwind CSS 3 (собранный `public/css/app.css` закоммичен), Alpine.js, Chart.js (лежат в `public/vendor/`) |
| Языки | русский, o‘zbekcha (латиница) |
| Валюта / часовой пояс | UZS / Asia/Tashkent |

## Быстрый старт (локально)

```bash
composer install
cp .env.example .env && php artisan key:generate     # в .env: DB_CONNECTION=sqlite для локалки
touch database/database.sqlite
php artisan migrate --seed --seeder=DemoSeeder         # demo: 1 организация, 2 филиала, 100+ учеников, 10 групп, оплаты, лиды…
php artisan serve
```

Demo-пользователи (пароль `Demo2026pass`): `admin@demo.uz` (Super Admin), `director@demo.uz`, `administrator@demo.uz` (только филиал Sardoba),
`sales@demo.uz`, `accountant@demo.uz`, `teacher1@demo.uz` (видит только свои группы).

Пустая «боевая» установка вместо demo: `php artisan erp:install` — миграции, роли, справочники, организация, первый филиал и Super Admin.

Front-end (нужен только разработчику, не серверу): `npm ci && npm run build` пересобирает `public/css/app.css` и копирует Alpine/Chart.js в `public/vendor/`.

## Развёртывание на Beget

Пошаговая инструкция — [docs/DEPLOY_BEGET.md](docs/DEPLOY_BEGET.md). Коротко: поддомен `erp.example.uz` → document root на `…/public`,
`.env` из `.env.example`, `bash bin/deploy.sh --install`, одна cron-строка `* * * * * php …/artisan schedule:run`, SSL из панели Beget.

## Что внутри

```
app/
  Http/Controllers/Web/      веб-контроллеры (тонкие) + CrudController для простых справочников
  Http/Controllers/Api/      REST API /api/v1 + webhook лидов
  Http/Middleware/           SetTenant (организация/филиал/язык), WebhookAuth, CanAny, SecurityHeaders
  Models/                    Eloquent + Concerns: BelongsToOrganization, BranchScoped, Auditable, NormalizesPhone
  Services/                  вся бизнес-логика, критичные операции — в DB-транзакциях
  Support/                   Tenant, Period, Lookup, Menu, Upload
  Console/Commands/          cron-команды, backup, erp:install, erp:organization
database/migrations|seeders  схема (47 таблиц) и DemoSeeder
routes/ web.php api.php console.php (расписание cron)
tests/Feature/               82 теста: бизнес-логика, права, изоляция организаций, API, Telegram, backup
```

Поток запроса: `Controller → Service → Model → DB`. Платёж, перевод ученика, конвертация лида, зарплата проходят через
`PaymentService / EnrollmentService / LeadService / SalaryService` внутри `DB::transaction` (платёж + пересчёт долга + история ученика + аудит + уведомление — атомарно).

### Модули и URL

| Раздел | URL | Что умеет |
|---|---|---|
| Dashboard | `/dashboard` | 8 KPI, периоды (сегодня … произвольный), воронка с contact/booking/show-up/conversion rate, графики, источники, занятия дня, крупнейшие долги |
| CRM | `/leads`, `/leads/funnel`, `/reports/sources`, `/reports/managers` | карточка лида с историей, статусы настраиваются, воронка (канбан), конвертация в ученика (лид сохраняется со статусом «Ученик») |
| Обучение | `/students`, `/parents`, `/courses`, `/groups`, `/schedule`, `/lessons`, `/attendance` | карточка ученика (родители, обучение, финансы, посещаемость, timeline), переводы/заморозка/продление, журнал посещаемости |
| Финансы | `/finance/payments`, `/finance/debts`, `/finance/expenses`, `/finance/salaries` | оплата за 4 действия, возвраты и коррекции, долги с фильтрами, расходы с чеками, начисления и выплаты зарплат |
| Персонал / Филиалы | `/teachers`, `/employees`, `/branches`, `/rooms` | KPI преподавателя, ставки, аудитории |
| Аналитика | `/reports/sales|finance|students|attendance|teachers` | CSV и PDF для основных отчётов |
| Telegram | `/notifications/telegram` | очередь, повтор ошибок, привязка аккаунтов по числовому Telegram ID |
| Настройки | `/settings`, `/settings/dictionaries`, `/users`, `/roles`, `/audit` | организация, логотип, шаблоны уведомлений, справочники, backup/restore |

Глобальный поиск (`Ctrl+K` / иконка лупы): имя, телефон, ID ученика/лида (`#123`), Telegram, название группы.
Колокольчик 🔔: новые лиды, долги, конфликты расписания, ошибки Telegram, системные сообщения.

### Роли

| Право | Super Admin | Директор | Админ | Sales | Преподаватель | Бухгалтер |
|---|:-:|:-:|:-:|:-:|:-:|:-:|
| Dashboard | ✓ | ✓ | ✓ | ✓ (свои лиды) | — | ✓ |
| Лиды / воронка | ✓ | ✓ | ✓ | свои + неназначенные | — | — |
| Ученики, родители | ✓ | ✓ | ✓ | просмотр | только своих групп | просмотр |
| Группы, расписание, посещаемость | ✓ | ✓ | ✓ | — | только свои | — |
| Оплаты, долги | ✓ | ✓ | приём оплат, долги | — | — | ✓ + возвраты/коррекции |
| Расходы, зарплаты | ✓ | ✓ | — | — | — | ✓ |
| Отчёты | все | все | — | продажи | — | финансы |
| Филиалы, пользователи, роли, настройки, backup | ✓ | — | — | — | — | — |

Роли и права редактируются в `/roles`; пользователь может выдавать только те права, которыми обладает сам. Пользователя можно ограничить одним филиалом.

## Мультиарендность (готовность к SaaS)

Каждая сущность хранит `organization_id`; `TenantScope` добавляется глобально, `organization_id` принудительно проставляется при создании,
валидация внешних ключей (`Lookup::exists`) учитывает организацию, route-model binding возвращает 404 для чужих записей.
Вторая организация: `php artisan erp:organization "Center B" admin@b.uz 'Пароль-10+'`. Тарифы (Start/Business/Pro/Enterprise) — поле `organizations.plan`.

## Cron

Одна запись `* * * * * php /path/artisan schedule:run`, дальше расписание лежит в [routes/console.php](routes/console.php):

| Время (Asia/Tashkent) | Команда | Назначение |
|---|---|---|
| каждые 5 минут | `notifications:process` | отправка очереди Telegram, повтор с back-off, 3 попытки |
| 08:00 | `reminders:lessons` | напоминания о занятиях сегодня (ученикам/родителям) |
| 20:00 | `debts:check` | напоминания о долге + сводка в 🔔 |
| 21:00 | `report:daily` | дневной отчёт руководителю (Telegram + 🔔) |
| 02:00 | `lessons:extend` | достройка занятий вперёд, поиск конфликтов расписания |
| 03:00 | `backup:run` | дамп БД (`storage/app/backups/*.sql.gz`, хранится `BACKUP_KEEP`, по умолчанию 14) |
| 1-е число | `salary:accrue` | начисление зарплат за прошлый месяц |
| воскресенье | `housekeeping:run` | чистка старых уведомлений и сессий |

Результаты и ошибки cron — `storage/logs/cron-*.log`, Telegram — `telegram-*.log`, вход — `auth-*.log`.

## Telegram

1. Создайте бота у @BotFather, в `.env`: `TELEGRAM_BOT_TOKEN`, `TELEGRAM_BOT_USERNAME`, `TELEGRAM_WEBHOOK_SECRET` (любая длинная случайная строка).
2. `php artisan telegram:webhook` — регистрирует `https://домен/telegram/webhook/<secret>`.
3. В карточке ученика/родителя (или `/notifications/telegram`) нажмите «Получить ссылку» и отправьте её человеку: после «Start» связь
   создаётся по **числовому Telegram ID** (username меняется — он не используется как идентификатор). Ссылка одноразовая.
4. Шаблоны сообщений (новая заявка, напоминание о занятии, долг, пропуск, дневной отчёт) настраиваются в `/settings`.

Токен хранится только в `.env` и вырезается из сообщений об ошибках.

## API и webhook

Токен: `POST /api/v1/auth/token {login, password}` → `Authorization: Bearer …`. Эндпоинты: `students`, `leads`, `groups`, `lessons`, `attendance`, `payments`,
право доступа проверяется так же, как в вебе. Приём лидов с сайта/рекламы: `POST /api/v1/webhooks/leads` с заголовком `X-Webhook-Key`
(ключ организации в `/settings`). Подробности и примеры — [docs/API.md](docs/API.md).

## Безопасность

CSRF на всех формах; Blade экранирует вывод (XSS); только параметризованные запросы; пароли `bcrypt`; блокировка входа на 15 минут после 5 неудач;
rate limiting для входа/API/webhook; права проверяются на каждом маршруте и при выборке данных; загрузки — белый список по **содержимому** файла (jpg/png/webp/pdf, до 5 МБ),
случайные имена, хранение вне `public/` и выдача только авторизованным; заголовки `X-Content-Type-Options`, `X-Frame-Options`, HSTS; `.env` и каталоги приложения закрыты `.htaccess`;
технические ошибки только в логи, пользователю — нейтральная страница. Перед запуском: `APP_DEBUG=false`, HTTPS, `SESSION_SECURE_COOKIE=true`.

## Тесты

```bash
php artisan test        # 82 теста, SQLite in-memory
DB_CONNECTION=mysql DB_DATABASE=erp_test DB_USERNAME=… DB_PASSWORD=… php artisan test    # то же на MySQL
```

Покрыто: авторизация и блокировка, права, изоляция организаций, воронка и конвертация лида, конфликты расписания, посещаемость (4 статуса),
оплата / частичная оплата / долг / возврат / коррекция / аудит, переводы и заморозка, зарплата (4 схемы), Telegram (отправка, сбой, повтор, привязка),
backup/restore, API и webhook, экспорт, загрузки файлов, а также «дымовой» прогон всех страниц под каждой ролью на demo-данных.

Нагрузка (замер на MariaDB 10.11 с 10 000 учеников, 100 000 лидов, 300 000 оплат и 300 000 отметок): списки — 50–150 мс, дашборд за месяц — 0.2 с, годовые отчёты — 0.6–2 с;
N+1 в окружении разработки логируется (`Model::preventLazyLoading`).

## Соответствие ТЗ

Все пункты приоритетов **P0 и P1** реализованы (аутентификация, роли, филиалы, лиды, ученики, родители, курсы, группы, преподаватели, расписание, занятия, посещаемость, оплаты, долги, dashboard,
расходы, зарплаты, Telegram, отчёты, аудит, backup, CSV/PDF). Из P2 заложено: PWA-manifest, API, `organization_id`. Не входит в v1.0 (§69): нативные приложения, LMS, онлайн-платежи, WhatsApp, QR, ИИ.

Принятые решения и ограничения:
- **Excel**: экспорт в CSV (UTF-8 BOM, разделитель `;`) — открывается в Excel без настройки; `.xlsx` не генерируется.
- **PDF** — для списков оплат/долгов и основных отчётов (DomPDF, шрифт DejaVu, поддерживает кириллицу).
- **Долг** считается по каждому зачислению: `начислено (цена − скидка) − оплачено`; оплата без указания группы относится к самому старому неоплаченному зачислению; переплата — кредит. Переводы переносят оплаты вместе с учеником.
- **Продление** — дополнительное начисление в зачислении + сдвиг даты следующей оплаты на длительность курса.
- **Конверсия воронки** считается по достигнутому этапу (`leads.max_stage`), который не снижается при смене статуса; этап каждого статуса настраивается.
- **Часовой пояс** приложения задаётся `APP_TIMEZONE`; поле организации — справочное (одна зона на установку).
- **Перевод интерфейса** — все основные экраны на uz/ru; справочные данные (названия статусов, курсов и т. д.) хранятся на языке, на котором их ввёл администратор.
- Управленческий учёт (кассовый метод), не бухгалтерия.
