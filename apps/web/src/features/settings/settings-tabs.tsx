'use client';

import type { PermissionCode } from '@fluggi/contracts';
import { useTranslations } from 'next-intl';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useCan } from '@/lib/me-context';
import { cn } from '@/lib/utils';

export const SETTINGS_TABS: { href: string; key: string; permission: PermissionCode }[] = [
  { href: '/settings/teams', key: 'tabTeams', permission: 'employee.manage' },
  { href: '/settings/references', key: 'tabReferences', permission: 'reference.manage' },
  { href: '/settings/roles', key: 'tabRoles', permission: 'role.manage' },
  { href: '/settings/audit', key: 'tabAudit', permission: 'audit.read' },
];

export function SettingsTabs() {
  const t = useTranslations('settings');
  const pathname = usePathname();
  const can = useCan();
  return (
    <div className="mb-6 flex gap-1 overflow-x-auto border-b">
      {SETTINGS_TABS.filter((tab) => can(tab.permission)).map((tab) => {
        const active = pathname.startsWith(tab.href);
        return (
          <Link
            key={tab.href}
            href={tab.href}
            aria-current={active ? 'page' : undefined}
            className={cn(
              '-mb-px whitespace-nowrap border-b-2 px-3 py-2.5 text-sm transition-colors',
              active
                ? 'border-foreground font-medium'
                : 'border-transparent text-muted-foreground hover:text-foreground',
            )}
          >
            {t(tab.key)}
          </Link>
        );
      })}
    </div>
  );
}
