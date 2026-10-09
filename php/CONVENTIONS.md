# Fluggi CRM (PHP) — правила разработки модулей

PHP-версия Fluggi CRM/ERP для **обычного виртуального хостинга** (Beget): PHP 8.2+, MySQL 5.7/8.0,
без Node.js, Docker, Redis, очередей и демонов на сервере. Фоновая работа — только cron раз в минуту
(`php artisan schedule:run`). Прежняя версия на Node.js (`apps/api`, `apps/web`, `packages/*`) —
**эталон поведения**: переносим её логику, правила и тексты без упрощений.

Источники требований: ТЗ (разделы 1–16), код прежней версии:
- `apps/api/src/modules/<модуль>/*.service.ts` — бизнес-логика, проверки, события;
- `apps/api/src/modules/<модуль>/*.controller.ts` — эндпоинты и права;
- `packages/contracts/src/schemas/*.ts` — валидация (zod) и DTO;
- `apps/web/src/features/<модуль>/*` и `apps/web/src/app/(app)/**/page.tsx` — экраны;
- `apps/api/src/**/*.test.ts`, `*.int.test.ts` — что проверять в тестах.

## Структура

| Что | Где |
| --- | --- |
| Модели (сгенерированы по схеме, связи готовы) | `app/Models/*` — не менять колонки; можно добавлять методы/scope только в модели своего модуля |
| Бизнес-формулы (готово, с тестами) | `app/Domain/*` — `Money`, `Proposal`, `LeadScore`, `Commission`, `Finance`, `People`, `Tasks`, `Analytics`, `Forecast`, `Recurrence`, `Words` |
| Логика модуля | `app/Services/<Модуль>/*Service.php` |
| Контроллеры | `app/Http/Controllers/<Модуль>/*Controller.php` |
| Валидация | `app/Http/Requests/<Модуль>/*Request.php` или `$request->validate()` в контроллере |
| Маршруты после входа | `routes/modules/<модуль>.php` (подключаются автоматически, уже внутри группы `web` + `fluggi.auth`) |
| Публичные маршруты (вебхуки, формы) | `routes/public/<модуль>.php`, адреса `hooks/...` (без CSRF) и `f/...` |
| Экраны | `resources/views/<модуль>/*.blade.php`, `@extends('layouts.app')` |
| Переводы | `lang/ru/<группа>.json` и `lang/uz/<группа>.json` — **оба языка обязательно** |
| Тесты | `tests/Feature/<Модуль>/*Test.php` (MySQL), `tests/Unit/...` |

## Доступ и данные

- Каждый маршрут после входа объявляет доступ: `->middleware('perm:lead.read')`,
  `perm:lead.read|deal.read` (любое из), `perm:finance.read,ALL` (не ниже области) или `auth.only`.
  Тест `RouteAccessTest` падает, если доступ не объявлен.
- Права текущего пользователя: `access()` (`App\Auth\Access`): `->id()`, `->user`, `->roleCode`,
  `->can('deal.update')`, `->authorize('payment.confirm')`, `->scope('lead.read')` (OWN|TEAM|ALL),
  `->teamIds()`, `->directionIds`.
- Ограничение выборок по области — **всегда на сервере**, в каждом запросе списка и карточки:
  `access()->restrictOwned(Lead::query(), 'lead.read')` (owner_id/team_id) или
  `access()->restrict($q, 'project.read', own: fn ($q, $uid) => …, team: fn ($q, $teamIds, $uid, $directionIds) => …)`.
  Карточка чужой записи — 404 (как в прежней версии), действие без права — 403.
- Изменения — в `DB::transaction()`, внутри: изменение + `Audit::log($action, $entityType, $id, $changes)`
  (`Audit::diff($model, $data, [поля])`) + `Outbox::publish($type, $payload)`.
- Неизменяемые таблицы (только добавление): `audit_logs`, `activities`, `stage_history`,
  `proposal_versions`, `task_status_history`, `reminder_log`. Без удаления: `payments`, `contracts`,
  `commissions`, `payroll_entries` (только отмена/сторно с причиной).
- Деньги: `Brick\Math\BigDecimal` и `App\Domain\Money` — никаких float. В базе DECIMAL, модель отдаёт
  строку (`decimal:2`). Суммы в UZS считаются по курсу на дату (`exchange_rates`), курс фиксируется в записи.
- Время: в базе UTC (`DATETIME(3)`), показывать по Ташкенту: `dt($v)`, `d($v)`,
  `App\Support\Format::toLocalInput()/fromLocalInput()/today()`. «Сегодня», месяц, отчёты — по Ташкенту.
- Номера: `Format::code('L', $lead->number)` → `L-00001`. После `create()` поле `number`
  (AUTO_INCREMENT) появляется только после `$model->refresh()`.
- Поиск по тексту: `LIKE` (сравнение в MySQL уже без учёта регистра, utf8mb4_unicode_ci).
- Блокировка строк очередей: `App\Support\Db::lockRows()` (`FOR UPDATE SKIP LOCKED` на MySQL 8, `FOR UPDATE` на 5.7).
- Настройки: `App\Support\Settings::automation()`, `::company()`, `::get('ключ')`, `::save()`.
- Никаких вымышленных данных, заглушек «скоро будет», TODO. Нет данных — пустое состояние `<x-empty>`.
- Секреты — только из `.env`; в коде читать через `config('fluggi.…')` (`config/fluggi.php`, дополнять там). `env()` вне `config/` не использовать (кэш конфигурации на хостинге).

## События (outbox)

`Outbox::publish('deal.won', [...])` в транзакции изменения. Подписчики регистрируются в
`App\Providers\<Модуль>ServiceProvider::boot()` через `Outbox::on('тип', fn (array $payload, array $meta) => …)`,
доставку выполняет cron. Каталог событий и полей payload (как в `apps/api/src/core/outbox/events.ts`):

```
user.created {userId, roleCode}            user.blocked {userId}         user.role_changed {userId, from, to}
lead.created {leadId, ownerId, teamId, createdById, budgetUzs}
lead.assigned {leadId, ownerId, previousOwnerId}   lead.converted {leadId, dealId, clientId}   lead.closed {leadId, status}
deal.created {dealId, ownerId, teamId, amountUzs}  deal.stage_changed {dealId, from, to}
deal.lost {dealId, ownerId, teamId, amountUzs, reason}   deal.won {dealId, ownerId, teamId, amountUzs}
meeting.created {meetingId, managerId, ropId, startsAt}   meeting.completed {meetingId, managerId, ropId}
proposal.approval_requested {proposalId, dealId, teamId}  proposal.sent {proposalId, dealId}  proposal.accepted {proposalId, dealId}
contract.signed {contractId, dealId, managerId, teamId}
payment.created {paymentId, dealId}
payment.paid {paymentId, dealId, amountUzs, managerId, teamId, projectId, projectCreated}
payment.refunded {paymentId, dealId, amountUzs}
project.created {projectId, dealId, ropId, managerId}   project.member_added {projectId, userId}
project.status_changed {projectId, from, to}
task.assigned {taskId, assigneeId}   task.status_changed {taskId, projectId, from, to}
task.overdue {taskId, overdueDays}   task.deadline_changed {taskId}
```

Модуль «Уведомления/Telegram» подписывается на эти события и рассылает уведомления — остальные
модули только публикуют события. Новые события — добавить в этот список.

## Интерфейс

- Серверные страницы Blade, интерактивность — Alpine.js (`x-data`). JSON-эндпоинты — для Kanban,
  перетаскивания, счётчиков; из JS — `window.api(url, {method, body})`, сообщения — `window.toast(text)`.
- Формы: обычный POST + редирект (`return back()->with('ok', t('…'))`), ошибки — `$errors`,
  компоненты `<x-field>`, `<x-select>`, `<x-modal>` (открыть: `$dispatch('open-modal', 'имя')`),
  `<x-page-header>`, `<x-card>`, `<x-table>`, `<x-badge color="green|blue|yellow|red|gray">`,
  `<x-stat>`, `<x-empty>`, `<x-tabs>`, `<x-icon name="…">` (список — `components/icon.blade.php`),
  пагинация `$items->links('vendor.pagination.fluggi')`.
- Классы: `btn btn-primary|btn-outline|btn-ghost|btn-danger|btn-accent btn-sm`, `input`, `label`, `card`,
  `table`, `badge`, `link`, `muted`. Tailwind собирается в CI (`npm run build`); новые классы в Blade подхватываются.
- Телефон: все экраны без горизонтальной прокрутки страницы (таблицы — внутри `<x-table>`), формы в одну колонку.
- Тексты — только через `t('группа.ключ', [...])`, оба языка (`lang/ru`, `lang/uz`, узбекский — латиница
  с ʻ/ʼ). Названия из справочников: `name_uz ?? name_ru` при узбекском языке.
- Меню — `config/navigation.php` (уже содержит все разделы). Адреса страниц — как в прежней версии
  (`/sales/leads`, `/sales/deals/{id}`, `/projects/all`, `/finance/payments` …).

## Тесты

- Каждый модуль: тесты прав (OWN/TEAM/ALL, 403/404), бизнес-правил, аудита и событий; формулы — unit.
- `Tests\TestCase`: `$this->user('MANAGER', team: $team)`, `$this->team($rop)`,
  `$this->actingAsUser($user)->post(...)`, `$this->as($user)` (права без HTTP).
- Своя тестовая база, чтобы не мешать другим: `DB_DATABASE=fluggi_test_<модуль> php vendor/bin/phpunit tests/Feature/<Модуль>`
  (создать: `docker exec mysql80 mysql -uroot -prootpw -e "CREATE DATABASE IF NOT EXISTS fluggi_test_<модуль>"`;
  MySQL 8 на 127.0.0.1:3308, root/rootpw — уже в `.env`).
- Перед сдачей: `php vendor/bin/pint` по своим файлам и полный прогон своих тестов.
