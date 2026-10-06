# Матрица прав (RBAC)

> Статус: **черновик на согласование**.

## Модель
- Право = `module.action` (например `deal.update`).
- У роли право выдаётся со **scope**: `OWN` — свои записи, `TEAM` — записи своего отдела
  (команда, которую возглавляет РОП), `ALL` — все.
- Проверка выполняется **на backend** трижды: guard на endpoint → scope-фильтр в
  репозитории (`WHERE owner_id = … / team_id = … / member`) → policy полей (финансовые
  поля вырезаются из ответа). Frontend только скрывает недоступное.
- Роли и их права хранятся в БД (`roles`, `role_permissions`), seed создаёт значения
  ниже; CEO может их менять в настройках.

### Что значит «своё»
| Сущность | OWN | TEAM |
|---|---|---|
| Лид / сделка / клиент | `owner_id = я` | `team_id = мой отдел` |
| Встреча | `manager_id = я` | отдел менеджера |
| КП / договор / оплата | через сделку | через сделку |
| Проект | я — участник (`project_members`) или менеджер | `rop_id = я` или менеджер из отдела |
| Задача | `assignee_id = я` или `creator_id = я` | задачи проектов отдела |

## Матрица

Обозначения: **A** — все, **T** — отдел, **O** — свои, **—** — нет доступа.
R — чтение, C — создание, U — изменение, D — удаление (soft).

| Модуль / действие | CEO | РОП | Менеджер | Исполнитель | HR/Admin |
|---|---|---|---|---|---|
| Дашборд CEO | A | — | — | — | — |
| Дашборд РОП / менеджера / исполнителя | A | T | O | O | — |
| Лиды R / C / U | A | T | O (C: себе) | — | — |
| Лиды: распределение (смена owner) | A | T | — | — | — |
| Лиды/сделки D | A | T | — | — | — |
| Клиенты R / C / U | A | T | O | — | — |
| Сделки R / C / U, смена стадии | A | T | O | — | — |
| Встречи R / C / U | A | T | O | — | — |
| КП R / C / U / send | A | T | O | — | — |
| КП: утверждение | A | T | — | — | — |
| Договоры R / C / U | A | T | O | — | — |
| Оплаты R | A | T | O | — | — |
| Оплаты C / подтверждение PAID | A | T | C: O (статус PENDING) | — | — |
| Проекты R | A | T | O | O (участник) | — |
| Проекты C / U, назначение исполнителей | A | T | — | — | — |
| Задачи R | A | T | O | O | — |
| Задачи C | A | T | O (в своих проектах) | — | — |
| Задачи U (статус, %, файлы, комментарии) | A | T | O | O (свои) | — |
| Финансы проекта (стоимость, расходы, прибыль) | A | T | — | — | — |
| Расходы C / U | A | T (проектные) | — | — | — |
| Финансы компании, расходы компании | A | — | — | — | — |
| Комиссии R | A | T | O | — | — |
| Правила комиссий | A | — | — | — | — |
| KPI R | A | T | O | O | A |
| KPI цели (установка) | A | T (менеджеры отдела) | — | — | A |
| Посещаемость R / U | A | T (R) | O (R) | O (R) | A |
| Рабочие графики | A | — | — | — | A |
| Зарплата R | A | — | O | O | ❓ (вопрос 4) |
| Зарплата: расчёт/утверждение | A | — | — | — | — |
| Сотрудники R | A | T (без HR-данных) | — | — | A |
| Сотрудники C / U / блокировка | A | — | — | — | A |
| Роли и права | A | — | — | — | — |
| Справочники (услуги, источники, стадии, шаблоны) | A | R | R | — | A (кроме финансовых полей) |
| Системные настройки | A | — | — | — | A (не финансовые) |
| Аналитика | A | T | O | — | — |
| Экспорт | A | T | — | — | A (сотрудники, посещаемость) |
| Audit log R | A | — | — | — | A |
| Audit log U / D | — | — | — | — | — |
| Глобальный поиск | по доступным данным | по доступным | по доступным | по доступным | по доступным |

## Коды прав (seed)

```
dashboard.ceo  dashboard.team  dashboard.own
lead.read lead.create lead.update lead.assign lead.delete
client.read client.create client.update
deal.read deal.create deal.update deal.change_stage deal.delete
meeting.read meeting.create meeting.update
proposal.read proposal.create proposal.update proposal.send proposal.approve
contract.read contract.create contract.update
payment.read payment.create payment.confirm payment.refund
project.read project.create project.update project.assign
task.read task.create task.update
finance.read finance.company.read expense.create expense.update
commission.read commission_rule.manage
kpi.read kpi.target.manage
attendance.read attendance.manage schedule.manage
payroll.read payroll.manage
employee.read employee.manage role.manage
settings.manage reference.manage
analytics.read export.run audit.read
```
