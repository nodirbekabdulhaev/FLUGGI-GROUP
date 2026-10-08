# API v1

Базовый URL: `https://домен/api/v1`. Формат — JSON. Ограничение: 120 запросов в минуту на пользователя.

## Авторизация

```bash
curl -X POST https://erp.example.uz/api/v1/auth/token \
  -H 'Accept: application/json' -d 'login=admin@demo.uz' -d 'password=…' -d 'device_name=my-app'
# {"token":"1|abc…","user":{"id":1,"name":"…"}}
```
Дальше: `Authorization: Bearer 1|abc…`. `DELETE /auth/token` отзывает текущий токен. Права пользователя те же, что в веб-интерфейсе (преподаватель видит только свои группы и занятия, менеджер — только свои лиды и т. д.).

## Эндпоинты

| Метод | URL | Право | Описание |
|---|---|---|---|
| GET | `/students?q=&status=&per_page=` | students.view | список с балансом (`balance` > 0 — долг) |
| GET | `/students/{id}` | students.view | карточка с родителями |
| POST | `/students` | students.manage | `first_name`, `branch_id`, … `group_id` — сразу записать в группу |
| GET | `/leads?status_id=&source_id=&manager_id=` | leads.view | |
| GET | `/leads/{id}` | leads.view | |
| POST | `/leads` | leads.manage | `first_name`, `phone`, … |
| GET | `/groups` | groups.view / groups.view_own | |
| GET | `/lessons?from=&to=&group_id=` | schedule.view / attendance.mark | диапазон до 93 дней |
| POST | `/attendance` | attendance.mark | `{"lesson_id":1,"rows":[{"student_id":5,"status":"present|absent|late|excused","comment":""}]}` |
| POST | `/payments` | payments.create | `student_id`, `amount`, `method_id`, `group_id?`, `paid_at?`, `comment?` — долг пересчитывается автоматически |

Ошибки валидации — `422 {"message":…,"errors":{…}}`, нет прав — `403`, чужие/несуществующие записи — `404`.

## Webhook лидов

`POST /api/v1/webhooks/leads` — публичный приём заявок (сайт, Instagram-формы, рекламные системы, другие CRM).
Авторизация — заголовок `X-Webhook-Key` (ключ организации показан в `/settings`, его можно перевыпустить). Лимит — 60 запросов в минуту.

```bash
curl -X POST https://erp.example.uz/api/v1/webhooks/leads \
  -H 'X-Webhook-Key: <ключ>' -H 'Content-Type: application/json' \
  -d '{"name":"Muhammad","phone":"+998901234567","course":"IELTS","source":"instagram"}'
# 201 {"id":123,"duplicate":false}
```
Поля: `name`, `phone` (обязательные), `last_name`, `course` и `source` (по названию без учёта регистра; неизвестный источник → «Другое»), `branch`, `telegram`, `instagram`, `age`, `comment`,
`campaign`, `utm_source|medium|campaign|content|term`. Повторная заявка с тем же телефоном в течение 10 минут возвращает `200 {"duplicate":true}` и не создаёт дубль.
Новая заявка сразу попадает в 🔔 и в Telegram менеджерам.
