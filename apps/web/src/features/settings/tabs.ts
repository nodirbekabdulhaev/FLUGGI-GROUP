import type { PermissionCode } from '@fluggi/contracts';

/** Вкладки настроек. Отдельный модуль (не 'use client'), чтобы его мог импортировать серверный код. */
export const SETTINGS_TABS: { href: string; key: string; permission: PermissionCode }[] = [
  { href: '/settings/teams', key: 'tabTeams', permission: 'employee.manage' },
  { href: '/settings/references', key: 'tabReferences', permission: 'reference.manage' },
  { href: '/settings/tariffs', key: 'tabTariffs', permission: 'reference.manage' },
  { href: '/settings/finance', key: 'tabFinance', permission: 'reference.manage' },
  { href: '/settings/templates', key: 'tabTemplates', permission: 'reference.manage' },
  {
    href: '/settings/commission-rules',
    key: 'tabCommissionRules',
    permission: 'commission_rule.manage',
  },
  { href: '/settings/schedules', key: 'tabSchedules', permission: 'schedule.manage' },
  { href: '/settings/documents', key: 'tabDocuments', permission: 'settings.manage' },
  { href: '/settings/integrations', key: 'tabIntegrations', permission: 'settings.manage' },
  { href: '/settings/automation', key: 'tabAutomation', permission: 'settings.manage' },
  { href: '/settings/roles', key: 'tabRoles', permission: 'role.manage' },
  { href: '/settings/audit', key: 'tabAudit', permission: 'audit.read' },
];
