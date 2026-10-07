# Fluggi OS — Структура API (предложение)

> Статус: **согласовано**. Схемы запросов/ответов — в `packages/contracts` (Zod), общие для backend и frontend.
>
> ✅ **Реализовано в Phase 1:** `auth/*` (login, logout, me, change-password, sessions), `users` (список, карточка, создание, изменение, block/unblock, reset-password), `teams` (CRUD, soft delete), `roles` (список, каталог прав, изменение прав), `audit-logs`, `health`, `ready`.
>
> ✅ **Реализовано в Phase 2:** `references` (справочники, услуги, источники, причины потерь, этапы, курс USD), `leads` (+ stage, assign, close, reopen, convert), `clients` (+ contacts), `deals` (+ stage, assign, close, reopen), `pipeline`, `meetings` (+ complete), `timeline`, `stage-history`, `comments`, `notifications`. Заголовок `Idempotency-Key` работает для всех POST. 
>
> ✅ **Реализовано в Phase 3:** `proposals` (+ versions, pdf, submit-approval, approve, send, mark-viewed, accept, reject; `PUT` после отправки создаёт новую версию), `contracts` (+ `POST /contracts/:id/send|sign|cancel`, создание из КП — `POST /contracts` с `proposalId`), `payments` (+ confirm, cancel, refund), `GET /deals/:id/money`, `commissions`, `commission-rules` (GET/POST/PUT), `projects` (только чтение: список и карточка), `files` (`POST /files` multipart, `GET /files?dealId|projectId|taskId`, `GET /files/:id/download`, `DELETE /files/:id`).
>
> ✅ **Реализовано в Phase 4:** `GET /projects?view=all|active|overdue|completed&status&ropId&clientId&q`, `GET/PATCH /projects/:id`, `POST /projects/:id/status { status, comment }`, `POST /projects/:id/apply-template { templateId }`, `GET /projects/:id/timeline`, `GET /projects/:id/candidates`, `POST /projects/:id/members`, `PATCH /projects/:id/members/:memberId`, `GET /tasks?view=all|today|overdue|in_progress|review|done&projectId&assigneeId&mine&status&q`, `GET/POST /tasks`, `GET/PATCH/DELETE /tasks/:id`, `POST /tasks/:id/move { status, beforeId }`, `POST /tasks/:id/comments`, `GET/POST /project-templates`, `PUT /project-templates/:id`. Файлы проекта и задачи — `POST /files` с `projectId` или `taskId`.
>
> ✅ **Реализовано в Phase 5:** `GET/POST /expenses`, `PATCH/DELETE /expenses/:id` (мягкое удаление), `GET /projects/:id/finance`, `GET /finance/summary?period=today|week|month|quarter|year|custom&from&to`, `GET /finance/projects`, `POST /commissions/approve|pay { ids }` (право `commission.approve`, только CEO), `GET /commissions?status`, `PATCH /payments/:id` (только неподтверждённая оплата). 
>
> ✅ **Реализовано в Phase 6:** `GET /dashboard?period` (разделы по ролям), `GET /kpi?period=YYYY-MM&group=MANAGER|ROP|EXECUTOR`, `GET/PUT /kpi/targets`, `GET/POST /work-schedules`, `PUT /work-schedules/:id`, `GET /attendance/today`, `POST /attendance/check-in|check-out`, `GET /attendance?dateFrom&dateTo&userId`, `GET /attendance/summary`, `PUT /attendance` (HR/CEO), `GET /payroll?period`, `POST /payroll/calculate`, `PATCH /payroll/:id`, `POST /payroll/approve|pay`. Остальные endpoint'ы ниже — план следующих фаз.

## 1. Соглашения

| Тема | Правило |
|---|---|
| Базовый путь | `/api/v1` (в примерах ниже опущен) |
| Формат | JSON, `camelCase`; даты — ISO 8601 UTC; деньги — строка-decimal `"9000000.00"` + `currency` |
| Аутентификация | httpOnly cookie `fluggi_session`; мутации требуют `X-CSRF-Token` |
| Авторизация | каждый endpoint помечен правом (`@RequirePermission('deal.update')`); выборки автоматически ограничены scope роли |
| Валидация | Zod; ошибка 422 с перечнем полей |
| Идемпотентность | `Idempotency-Key` на `POST` создания и переходах (защита от двойного клика) |
| Пагинация | `?page=1&pageSize=25` → `{ items, total, page, pageSize }` |
| Фильтры (§43) | `?dateFrom&dateTo&status&ownerId&teamId&clientId&serviceId&sourceId&amountMin&amountMax&q&sort=-createdAt` |
| Rate limit | 429 + `Retry-After` |

Формат ошибки (stack trace никогда не отдаётся):
```json
{ "error": { "code": "VALIDATION_ERROR", "message": "Проверьте поля формы", "details": [{ "path": "phone", "message": "Обязательное поле" }], "requestId": "…" } }
```
Коды: `UNAUTHENTICATED` 401 · `FORBIDDEN` 403 · `NOT_FOUND` 404 · `CONFLICT` 409 · `VALIDATION_ERROR` 422 · `BUSINESS_RULE_VIOLATION` 422 (напр. `LOST_REASON_REQUIRED`) · `RATE_LIMITED` 429 · `INTERNAL` 500.

## 2. Ресурсы

Переходы состояний — отдельные action-endpoint'ы (`POST /x/:id/<action>`), а не
произвольный `PATCH status`: так бизнес-правила и права проверяются в одном месте.

### Auth / профиль
```
POST   /auth/login                  POST /auth/logout          GET /auth/me
POST   /auth/change-password        GET  /auth/sessions        DELETE /auth/sessions/:id
POST   /me/telegram/link            → { deepLink: "https://t.me/FluggiBot?start=<token>" }
DELETE /me/telegram
GET    /me/notification-settings    PUT /me/notification-settings
```

### Команда и доступ
```
GET/POST        /users              GET/PATCH /users/:id       POST /users/:id/block|unblock|reset-password
GET/POST        /teams              PATCH/DELETE /teams/:id
GET/PATCH       /employees/:userId  (HR-данные)
GET             /roles              GET /roles/permissions     PUT /roles/:id/permissions
```

### CRM
```
GET/POST        /leads              GET/PATCH/DELETE /leads/:id
POST            /leads/:id/stage    { stageCode, comment }
POST            /leads/:id/assign   { ownerId }
POST            /leads/:id/convert  { clientId | newClient, contact, amount, currency } → { clientId, dealId }
POST            /leads/:id/lose     { lossReasonId, comment }
GET/POST        /clients            GET/PATCH /clients/:id     GET /clients/:id/summary (LTV, health, сделки)
GET/POST        /clients/:id/contacts                          PATCH/DELETE /contacts/:id
GET/POST        /deals              GET/PATCH/DELETE /deals/:id
POST            /deals/:id/stage    POST /deals/:id/lose        POST /deals/:id/pause
GET             /pipeline           ?scope&ownerId&serviceId — колонки лидов и сделок
GET             /timeline           ?leadId|dealId|clientId|projectId — activities
GET/POST        /comments           ?leadId|dealId|clientId|projectId
GET/POST        /meetings           GET/PATCH /meetings/:id     POST /meetings/:id/complete { result }
```

### Продажи
```
GET/POST        /proposals          GET/PATCH /proposals/:id   (PATCH после отправки → новая версия)
GET             /proposals/:id/versions
POST            /proposals/:id/submit-approval|approve|send|mark-viewed|accept|reject
GET             /proposals/:id/pdf
GET/POST        /contracts          GET/PATCH /contracts/:id
POST            /contracts/:id/send|sign|cancel                 POST /contracts/from-proposal/:proposalId
GET/POST        /payments           GET/PATCH /payments/:id     (PATCH только для PENDING)
POST            /payments/:id/confirm  → PAID: { payment, project, commissions[] }
POST            /payments/:id/cancel   POST /payments/:id/refund { amount, comment }
```

### Проекты и задачи
```
GET/POST        /projects           ?view=all|active|overdue|completed
GET/PATCH       /projects/:id       POST /projects/:id/status|complete|cancel
GET/POST        /projects/:id/members                           PATCH/DELETE /project-members/:id
POST            /projects/:id/apply-template { templateId }
GET             /projects/:id/finance
GET             /projects/:id/board  (Kanban)
GET/POST        /tasks              GET/PATCH/DELETE /tasks/:id
POST            /tasks/:id/move     { status, sortOrder }  (drag & drop)
GET/POST        /tasks/:id/comments
```

### Финансы, KPI, HR
```
GET             /finance/summary    ?period&dateFrom&dateTo&currency
GET             /finance/revenue|receivables|profit
GET/POST        /expenses           PATCH/DELETE /expenses/:id
GET             /commissions        POST /commissions/:id/approve|mark-paid
GET/POST        /commission-rules   PATCH /commission-rules/:id    POST /commission-rules/preview
GET             /kpi                ?userId|teamId&period
GET/PUT         /kpi/targets
GET/POST        /attendance         PATCH /attendance/:id          (после MVP)
GET/POST        /work-schedules                                    (после MVP)
GET             /payroll            POST /payroll/:period/calculate|approve (после MVP)
GET/PUT         /exchange-rates
```

### Аналитика, поиск, экспорт (Phase 8)
```
GET  /analytics/sales      ?period&from&to&teamId&userId   → точки, итоги, % к прошлому периоду
GET  /analytics/funnel     /analytics/sources   /analytics/services   /analytics/losses
GET  /analytics/forecast   ?teamId&userId                   → 3 месяца: получено, ожидаемые, воронка×вероятность, план
GET  /analytics/clients    ?sort=ltv|health|lastPayment&health&page   → LTV и здоровье клиентов
GET  /clients/:id/insight                                   → LTV и здоровье одного клиента
GET  /search?q=            → клиенты, лиды, сделки, проекты, договоры, задачи, сотрудники (с учётом прав)
GET  /exports/:entity      ?format=xlsx|csv&period=all|today|week|month|quarter|year|custom&from&to
                           entity: leads|deals|clients|payments|expenses|projects|tasks (право export.run, аудит)
GET  /exports/accountant-package?year=2026   → годовой пакет для бухгалтера (finance.company.read ALL + export.run)
GET/PUT /settings/company                   → реквизиты компании (settings.manage ALL)
GET  /notifications      POST /notifications/:id/read   POST /notifications/read-all
GET  /audit-logs         ?actorId&entityType&entityId&dateFrom&dateTo
```

### Справочники и настройки
```
GET/POST/PATCH  /services  /lead-sources  /loss-reasons  /deal-stages
GET/POST/PATCH  /templates/projects  /templates/tasks  /templates/proposals  /templates/contracts  /templates/notifications
GET/PUT         /settings/:key
POST            /files/upload-url   { category, mime, size, entityRef } → { fileId, uploadUrl }
POST            /files/:id/complete GET /files/:id/download-url
```

### Telegram и автоматизация (Phase 7)
```
GET/DELETE      /me/telegram          статус привязки / отключить
POST            /me/telegram/link     → { deepLink, command, expiresAt } (код на 15 минут)
POST            /me/telegram/test     тестовое сообщение себе
GET/PUT         /notifications/settings   { settings: [{ eventType, channel: IN_APP|TELEGRAM, enabled }] }
GET/PUT         /settings/automation  (settings.manage ALL) порог, интервалы follow-up, отчёты
GET             /automation/jobs      POST /automation/jobs/:name/run   (settings.manage ALL)
GET             /reports/preview?kind=daily|weekly   CEO — компания, РОП — свой отдел
GET             /follow-ups           ?status&due=true&clientId&page
POST            /follow-ups/:id/complete  { status: DONE|SKIPPED, result?, createDeal? }
```

### Дела, регулярные дела, чат
```
GET    /todos              ?view=mine|assigned|all&status&clientId&dealId&page   (all: CEO — компания, РОП — свой отдел)
GET    /todos/dock          → только мои открытые личные дела («Список дел»)
POST   /todos              PATCH /todos/:id   POST /todos/:id/complete|reopen   DELETE /todos/:id
GET    /recurring-todos    POST /recurring-todos   PUT/DELETE /recurring-todos/:id
POST   /recurring-todos/tax-calendar          → налоговый календарь IT-Park себе (без дублей)
GET    /chats              GET /chats/unread   GET /chats/contacts   POST /chats/direct { userId }
GET    /chats/:id/messages ?before&limit      POST /chats/:id/messages { body }   POST /chats/:id/read
```

### Тарифы, категории, себестоимость, документы (Phase 10)
```
GET    /finance-categories ?kind=EXPENSE|INCOME   POST / PUT /:id / DELETE /:id   (reference.manage ALL)
GET    /other-incomes      ?category&dateFrom&dateTo&page   POST / PUT /:id / DELETE /:id   (finance.company.read ALL)
GET    /settings/finance   PUT /settings/finance { overheadDivisor: number|null }
GET    /work-items         POST / PUT /:id                   — работы исполнителей и базовые ставки
GET    /users/:id/rates    PUT /users/:id/rates { rates[] } — личные сдельные ставки (PUT: payroll.manage ALL)
GET    /tariffs            ?serviceId&all=true   POST / PUT /:id  — economics только с finance.company.read ALL
GET    /projects/:id/cost-lines   PATCH /cost-lines/:id   POST /cost-lines/:id/accrue|cancel
GET    /documents/proposals/:id   ?format=html|pdf|docx|txt&download=true   (proposal.read)
GET    /documents/contracts/:id   ?format=html|pdf|docx|txt&download=true   (contract.read)
GET    /documents/{proposals|contracts}/:id/check  → { missing: string[] }
GET    /settings/documents  PUT /settings/documents { city, contractTemplate, proposalIntro, proposalNote }
PUT    /clients/:id/requisites   (client.update)
```
`GET /proposals/:id/pdf` удалён — используйте `/documents/proposals/:id?format=pdf`.

### Направления бизнеса и проект-менеджер
```
GET  /references            → + directions[]; services[].directionId
POST /directions            PUT /directions/:id   { name, sort, isActive }  (reference.manage ALL)
POST/PATCH /users           + directionIds[] — направления сотрудника (проект-менеджер)
GET  /projects              ?directionId   → project.direction
PATCH /projects/:id         + directionId (только CEO, project.update ALL)
GET  /dashboard             → + projects { directions, active, overdueProjects, unassigned, tasksOpen, tasksOverdue, tasksToday, completed } (PROJECT_MANAGER)
POST /recurring-todos/tax-calendar   — только CEO; регулярные дела другим ставит только CEO
POST/PUT /proposals         — позиция с тарифом: цену и скидку меняет только proposal.approve (РОП/CEO)
```

### Интеграции: формы сайта, Instagram, таргет
```
GET/POST /lead-forms   PUT/DELETE /lead-forms/:id   GET /lead-forms/:id/submissions   (settings.manage ALL)
GET/PUT  /settings/integrations   GET /integrations/meta   (settings.manage ALL)
GET  /public/forms/:key           → форма для посетителя (без входа, CORS *)
POST /public/forms/:key           { data, utm?, page?, website?, renderedAt? } или плоская HTML-форма / webhook плагина
GET  /public/meta/webhook         ?hub.mode&hub.verify_token&hub.challenge — подключение webhook
POST /public/meta/webhook         Instagram messages/comments, Page feed/leadgen; подпись X-Hub-Signature-256
GET  /inbox ?channel&unread&page  GET /inbox/:id/messages   (lead.read; видимость как у лидов)
POST /inbox/:id/reply { text, commentId?, mode: public|private }   (lead.update)
POST /inbox/:id/lead  — создать лид из переписки   (lead.create)
POST /client-errors   — ошибка виджета интерфейса в лог API
```

### Прочее
```
POST /client-errors   { widget, message, stack?, path?, userAgent? } — ошибка виджета интерфейса → лог API (WARN ClientError)
GET  /dashboard       → + myKpi { pct, targets[], bonusTarget, bonusUzs, commissionUzs, baseSalary, expectedUzs } (кроме CEO)
PATCH /payroll/:id    + kpiBonusTarget — «KPI-бонус при 100%» в карточке сотрудника (CEO)
```

### Внешние
```
POST /telegram/webhook   (проверка X-Telegram-Bot-Api-Secret-Token)
GET  /health             GET /ready
```
