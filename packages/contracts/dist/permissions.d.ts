import type { RoleCode, Scope } from './enums';
export declare const PERMISSIONS: {
    readonly 'dashboard.ceo': "Дашборд CEO";
    readonly 'dashboard.team': "Дашборд отдела";
    readonly 'dashboard.own': "Личный дашборд";
    readonly 'lead.read': "Просмотр лидов";
    readonly 'lead.create': "Создание лидов";
    readonly 'lead.update': "Редактирование лидов";
    readonly 'lead.assign': "Распределение лидов";
    readonly 'lead.delete': "Удаление лидов";
    readonly 'client.read': "Просмотр клиентов";
    readonly 'client.create': "Создание клиентов";
    readonly 'client.update': "Редактирование клиентов";
    readonly 'deal.read': "Просмотр сделок";
    readonly 'deal.create': "Создание сделок";
    readonly 'deal.update': "Редактирование сделок";
    readonly 'deal.change_stage': "Смена этапа сделки";
    readonly 'deal.delete': "Удаление сделок";
    readonly 'meeting.read': "Просмотр встреч";
    readonly 'meeting.create': "Создание встреч";
    readonly 'meeting.update': "Редактирование встреч";
    readonly 'proposal.read': "Просмотр КП";
    readonly 'proposal.create': "Создание КП";
    readonly 'proposal.update': "Редактирование КП";
    readonly 'proposal.send': "Отправка КП";
    readonly 'proposal.approve': "Утверждение КП";
    readonly 'contract.read': "Просмотр договоров";
    readonly 'contract.create': "Создание договоров";
    readonly 'contract.update': "Редактирование договоров";
    readonly 'payment.read': "Просмотр оплат";
    readonly 'payment.create': "Создание оплат";
    readonly 'payment.confirm': "Подтверждение оплат";
    readonly 'payment.refund': "Возвраты";
    readonly 'project.read': "Просмотр проектов";
    readonly 'project.create': "Создание проектов";
    readonly 'project.update': "Редактирование проектов";
    readonly 'project.assign': "Назначение исполнителей";
    readonly 'task.read': "Просмотр задач";
    readonly 'task.create': "Создание задач";
    readonly 'task.update': "Редактирование задач";
    readonly 'finance.read': "Финансы проектов";
    readonly 'finance.company.read': "Финансы компании";
    readonly 'expense.create': "Создание расходов";
    readonly 'expense.update': "Редактирование расходов";
    readonly 'commission.read': "Просмотр комиссий";
    readonly 'commission.approve': "Утверждение и выплата комиссий";
    readonly 'commission_rule.manage': "Правила комиссий";
    readonly 'kpi.read': "Просмотр KPI";
    readonly 'kpi.target.manage': "Управление целями KPI";
    readonly 'attendance.read': "Просмотр посещаемости";
    readonly 'attendance.manage': "Управление посещаемостью";
    readonly 'schedule.manage': "Рабочие графики";
    readonly 'payroll.read': "Просмотр зарплат";
    readonly 'payroll.manage': "Расчёт зарплат";
    readonly 'employee.read': "Просмотр сотрудников";
    readonly 'employee.manage': "Управление сотрудниками";
    readonly 'role.manage': "Роли и права";
    readonly 'settings.manage': "Системные настройки";
    readonly 'reference.manage': "Справочники";
    readonly 'analytics.read': "Аналитика";
    readonly 'export.run': "Экспорт";
    readonly 'audit.read': "Журнал аудита";
};
export type PermissionCode = keyof typeof PERMISSIONS;
export declare const PERMISSION_CODES: PermissionCode[];
/** Права пользователя: код права → область видимости. Отсутствие ключа = нет права. */
export type PermissionMap = Partial<Record<PermissionCode, Scope>>;
/**
 * Права ролей по умолчанию (docs/PERMISSIONS.md). Используются seed'ом;
 * дальше CEO может менять их в «Настройки → Роли и права».
 */
export declare const DEFAULT_ROLE_PERMISSIONS: Record<RoleCode, PermissionMap>;
/** Права, которые нельзя снять с роли CEO (иначе можно потерять управление системой). */
export declare const CEO_LOCKED_PERMISSIONS: PermissionCode[];
/** Есть ли у пользователя право, опционально — не ниже указанной области. */
export declare function hasPermission(permissions: PermissionMap, code: PermissionCode, minScope?: Scope): boolean;
