# Fluggi OS — Структура API (предложение)

> Статус: **согласовано**. Схемы запросов/ответов — в `packages/contracts` (Zod), общие для backend и frontend.
>
> ✅ **Реализовано в Phase 1:** `auth/*` (login, logout, me, change-password, sessions), `users` (список, карточка, создание, изменение, block/unblock, reset-password), `teams` (CRUD, soft delete), `roles` (список, каталог прав, изменение прав), `audit-logs`, `health`, `ready`. Остальные endpoint'ы ниже — план следующих фаз.

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

### Аналитика, поиск, экспорт
```
GET  /dashboard/ceo|rop|manager|executor   ?period=today|month|quarter|year|custom
GET  /analytics/sales                      ?groupBy=day|week|month|manager|source|service
GET  /analytics/funnel   /analytics/sources   /analytics/loss-reasons
GET  /analytics/forecast /analytics/employees/:id
GET  /search?q=          → клиенты, лиды, сделки, проекты, договоры, сотрудники (с учётом прав)
POST /exports            { entity, format: csv|xlsx|pdf, filters } → { fileId } (через очередь)
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

### Внешние
```
POST /telegram/webhook   (проверка X-Telegram-Bot-Api-Secret-Token)
GET  /health             GET /ready
```
