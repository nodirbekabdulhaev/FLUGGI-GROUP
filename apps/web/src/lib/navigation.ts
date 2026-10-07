import { hasPermission, type PermissionCode, type PermissionMap } from '@fluggi/contracts';
import {
  BarChart3,
  Bell,
  Briefcase,
  CalendarCheck,
  CheckSquare,
  Gauge,
  LayoutDashboard,
  Settings,
  Target,
  TrendingUp,
  Users,
  Wallet,
  type LucideIcon,
} from 'lucide-react';

export interface NavLeaf {
  key: string;
  href: string;
  /** Видно, если есть хотя бы одно из прав. Пусто — любому вошедшему. */
  anyOf?: PermissionCode[];
  /** Фаза, в которой раздел будет реализован (если ещё не реализован). */
  plannedPhase?: number;
}

export interface NavSection extends NavLeaf {
  icon: LucideIcon;
  children?: NavLeaf[];
}

/** Левое меню (ТЗ §4). Ключи — в messages/*.json → nav.* */
export const NAVIGATION: NavSection[] = [
  {
    key: 'dashboard',
    href: '/dashboard',
    icon: LayoutDashboard,
  },
  {
    key: 'sales',
    href: '/sales',
    icon: TrendingUp,
    children: [
      { key: 'leads', href: '/sales/leads', anyOf: ['lead.read'] },
      { key: 'deals', href: '/sales/deals', anyOf: ['deal.read'] },
      { key: 'pipeline', href: '/sales/pipeline', anyOf: ['lead.read', 'deal.read'] },
      { key: 'meetings', href: '/sales/meetings', anyOf: ['meeting.read'] },
      { key: 'proposals', href: '/sales/proposals', anyOf: ['proposal.read'] },
      { key: 'contracts', href: '/sales/contracts', anyOf: ['contract.read'] },
    ],
  },
  { key: 'clients', href: '/clients', icon: Briefcase, anyOf: ['client.read'] },
  {
    key: 'projects',
    href: '/projects',
    icon: Gauge,
    children: [
      { key: 'projectsAll', href: '/projects/all', anyOf: ['project.read'] },
      { key: 'projectsActive', href: '/projects/active', anyOf: ['project.read'] },
      { key: 'projectsOverdue', href: '/projects/overdue', anyOf: ['project.read'] },
      { key: 'projectsCompleted', href: '/projects/completed', anyOf: ['project.read'] },
    ],
  },
  { key: 'tasks', href: '/tasks', icon: CheckSquare, anyOf: ['task.read'] },
  {
    key: 'team',
    href: '/team',
    icon: Users,
    children: [
      { key: 'employees', href: '/team/employees', anyOf: ['employee.read'] },
      { key: 'managers', href: '/team/managers', anyOf: ['employee.read'] },
      { key: 'rops', href: '/team/rop', anyOf: ['employee.read'] },
      { key: 'executors', href: '/team/executors', anyOf: ['employee.read'] },
    ],
  },
  {
    key: 'finance',
    href: '/finance',
    icon: Wallet,
    children: [
      { key: 'revenue', href: '/finance/revenue', anyOf: ['finance.read'] },
      { key: 'payments', href: '/finance/payments', anyOf: ['payment.read'] },
      { key: 'expenses', href: '/finance/expenses', anyOf: ['finance.read'] },
      { key: 'profit', href: '/finance/profit', anyOf: ['finance.read'] },
      { key: 'commissions', href: '/finance/commissions', anyOf: ['commission.read'] },
    ],
  },
  { key: 'kpi', href: '/kpi', icon: Target, anyOf: ['kpi.read'], plannedPhase: 6 },
  {
    key: 'attendance',
    href: '/attendance',
    icon: CalendarCheck,
    anyOf: ['attendance.read'],
    plannedPhase: 6,
  },
  {
    key: 'analytics',
    href: '/analytics',
    icon: BarChart3,
    anyOf: ['analytics.read'],
    plannedPhase: 8,
  },
  { key: 'notifications', href: '/notifications', icon: Bell },
  {
    key: 'settings',
    href: '/settings',
    icon: Settings,
    anyOf: [
      'settings.manage',
      'role.manage',
      'employee.manage',
      'audit.read',
      'reference.manage',
      'commission_rule.manage',
    ],
  },
];

export function canSee(item: NavLeaf, permissions: PermissionMap): boolean {
  return !item.anyOf || item.anyOf.some((code) => hasPermission(permissions, code));
}

/** Меню, отфильтрованное по правам. Раздел с подпунктами виден, если виден хоть один подпункт. */
export function visibleNavigation(permissions: PermissionMap): NavSection[] {
  return NAVIGATION.flatMap((section) => {
    if (!section.children) return canSee(section, permissions) ? [section] : [];
    const children = section.children.filter((c) => canSee(c, permissions));
    return children.length > 0 ? [{ ...section, children }] : [];
  });
}

/** Поиск пункта меню по адресу (для страниц разделов в разработке). */
export function findNavItem(pathname: string): { section: NavSection; item: NavLeaf } | null {
  for (const section of NAVIGATION) {
    if (section.href === pathname) return { section, item: section };
    const child = section.children?.find((c) => c.href === pathname);
    if (child) return { section, item: child };
  }
  return null;
}
