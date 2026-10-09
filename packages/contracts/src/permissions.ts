import type { RoleCode, Scope } from './enums';

export const PERMISSIONS = {
  'dashboard.ceo': 'Дашборд CEO',
  'dashboard.team': 'Дашборд отдела',
  'dashboard.own': 'Личный дашборд',

  'lead.read': 'Просмотр лидов',
  'lead.create': 'Создание лидов',
  'lead.update': 'Редактирование лидов',
  'lead.assign': 'Распределение лидов',
  'lead.delete': 'Удаление лидов',

  'client.read': 'Просмотр клиентов',
  'client.create': 'Создание клиентов',
  'client.update': 'Редактирование клиентов',

  'deal.read': 'Просмотр сделок',
  'deal.create': 'Создание сделок',
  'deal.update': 'Редактирование сделок',
  'deal.change_stage': 'Смена этапа сделки',
  'deal.delete': 'Удаление сделок',

  'meeting.read': 'Просмотр встреч',
  'meeting.create': 'Создание встреч',
  'meeting.update': 'Редактирование встреч',

  'proposal.read': 'Просмотр КП',
  'proposal.create': 'Создание КП',
  'proposal.update': 'Редактирование КП',
  'proposal.send': 'Отправка КП',
  'proposal.approve': 'Утверждение КП',

  'contract.read': 'Просмотр договоров',
  'contract.create': 'Создание договоров',
  'contract.update': 'Редактирование договоров',

  'payment.read': 'Просмотр оплат',
  'payment.create': 'Создание оплат',
  'payment.confirm': 'Подтверждение оплат',
  'payment.refund': 'Возвраты',

  'project.read': 'Просмотр проектов',
  'project.create': 'Создание проектов',
  'project.update': 'Редактирование проектов',
  'project.assign': 'Назначение исполнителей',

  'task.read': 'Просмотр задач',
  'task.create': 'Создание задач',
  'task.update': 'Редактирование задач',

  'finance.read': 'Финансы проектов',
  'finance.company.read': 'Финансы компании',
  'expense.create': 'Создание расходов',
  'expense.update': 'Редактирование расходов',

  'commission.read': 'Просмотр комиссий',
  'commission.approve': 'Утверждение и выплата комиссий',
  'commission_rule.manage': 'Правила комиссий',

  'kpi.read': 'Просмотр KPI',
  'kpi.target.manage': 'Управление целями KPI',

  'attendance.read': 'Просмотр посещаемости',
  'attendance.manage': 'Управление посещаемостью',
  'schedule.manage': 'Рабочие графики',

  'payroll.read': 'Просмотр зарплат',
  'payroll.manage': 'Расчёт зарплат',

  'employee.read': 'Просмотр сотрудников',
  'employee.manage': 'Управление сотрудниками',
  'role.manage': 'Роли и права',

  'settings.manage': 'Системные настройки',
  'reference.manage': 'Справочники',

  'analytics.read': 'Аналитика',
  'export.run': 'Экспорт',
  'audit.read': 'Журнал аудита',
} as const;

export type PermissionCode = keyof typeof PERMISSIONS;
export const PERMISSION_CODES = Object.keys(PERMISSIONS) as PermissionCode[];

/** Права пользователя: код права → область видимости. Отсутствие ключа = нет права. */
export type PermissionMap = Partial<Record<PermissionCode, Scope>>;

const all = (codes: PermissionCode[]): PermissionMap =>
  Object.fromEntries(codes.map((c) => [c, 'ALL'])) as PermissionMap;
const team = (codes: PermissionCode[]): PermissionMap =>
  Object.fromEntries(codes.map((c) => [c, 'TEAM'])) as PermissionMap;
const own = (codes: PermissionCode[]): PermissionMap =>
  Object.fromEntries(codes.map((c) => [c, 'OWN'])) as PermissionMap;

/**
 * Права ролей по умолчанию (docs/PERMISSIONS.md). Используются seed'ом;
 * дальше CEO может менять их в «Настройки → Роли и права».
 */
export const DEFAULT_ROLE_PERMISSIONS: Record<RoleCode, PermissionMap> = {
  CEO: all(PERMISSION_CODES),

  // Финансы проектов (прибыль, расходы) — только CEO; РОП назначает исполнителей
  ROP: {
    ...team([
      'dashboard.team',
      'lead.read',
      'lead.create',
      'lead.update',
      'lead.assign',
      'lead.delete',
      'client.read',
      'client.create',
      'client.update',
      'deal.read',
      'deal.create',
      'deal.update',
      'deal.change_stage',
      'deal.delete',
      'meeting.read',
      'meeting.create',
      'meeting.update',
      'proposal.read',
      'proposal.create',
      'proposal.update',
      'proposal.send',
      'proposal.approve',
      'contract.read',
      'contract.create',
      'contract.update',
      'payment.read',
      'payment.create',
      'payment.confirm',
      'payment.refund',
      'project.read',
      'project.create',
      'project.update',
      'project.assign',
      'task.read',
      'task.create',
      'task.update',
      'commission.read',
      'kpi.read',
      'kpi.target.manage',
      'attendance.read',
      'employee.read',
      'analytics.read',
      'export.run',
    ]),
    ...own(['payroll.read']),
  },

  MANAGER: own([
    'dashboard.own',
    'lead.read',
    'lead.create',
    'lead.update',
    'client.read',
    'client.create',
    'client.update',
    'deal.read',
    'deal.create',
    'deal.update',
    'deal.change_stage',
    'meeting.read',
    'meeting.create',
    'meeting.update',
    'proposal.read',
    'proposal.create',
    'proposal.update',
    'proposal.send',
    'contract.read',
    'contract.create',
    'contract.update',
    'payment.read',
    'payment.create',
    'project.read',
    'task.read',
    'task.create',
    'task.update',
    'commission.read',
    'kpi.read',
    'attendance.read',
    'payroll.read',
    'analytics.read',
  ]),

  EXECUTOR: own([
    'dashboard.own',
    'project.read',
    'task.read',
    'task.update',
    'kpi.read',
    'payroll.read',
  ]),

  HR_ADMIN: {
    ...all([
      'kpi.read',
      'kpi.target.manage',
      'attendance.read',
      'attendance.manage',
      'schedule.manage',
      'employee.read',
      'employee.manage',
      'settings.manage',
      'reference.manage',
      'export.run',
      'audit.read',
    ]),
    ...own(['payroll.read']),
  },

  /**
   * Проект-менеджер направления (например, Медиа): ведёт все проекты своих направлений.
   * Область «Отдел» для проектов означает «проекты моих направлений» (Команда → направления сотрудника).
   */
  PROJECT_MANAGER: {
    ...team([
      'project.read',
      'project.update',
      'project.assign',
      'task.read',
      'task.create',
      'task.update',
    ]),
    ...all(['employee.read']),
    ...own(['dashboard.own', 'kpi.read', 'payroll.read']),
  },
};

/** Права, которые нельзя снять с роли CEO (иначе можно потерять управление системой). */
export const CEO_LOCKED_PERMISSIONS: PermissionCode[] = ['role.manage', 'employee.manage'];

const SCOPE_RANK: Record<Scope, number> = { OWN: 1, TEAM: 2, ALL: 3 };

/** Есть ли у пользователя право, опционально — не ниже указанной области. */
export function hasPermission(
  permissions: PermissionMap,
  code: PermissionCode,
  minScope: Scope = 'OWN',
): boolean {
  const scope = permissions[code];
  return scope !== undefined && SCOPE_RANK[scope] >= SCOPE_RANK[minScope];
}
