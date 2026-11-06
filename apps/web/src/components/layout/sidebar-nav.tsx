'use client';

import { ChevronDown } from 'lucide-react';
import { useTranslations } from 'next-intl';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useState } from 'react';
import { useMe } from '@/lib/me-context';
import { visibleNavigation, type NavSection } from '@/lib/navigation';
import { cn } from '@/lib/utils';

function isActive(pathname: string, href: string) {
  return pathname === href || pathname.startsWith(`${href}/`);
}

function Section({ section, onNavigate }: { section: NavSection; onNavigate?: () => void }) {
  const t = useTranslations('nav');
  const pathname = usePathname();
  const active = isActive(pathname, section.href);
  const [open, setOpen] = useState(active);
  const Icon = section.icon;

  const itemClass = (on: boolean) =>
    cn(
      'flex h-9 w-full items-center gap-3 rounded-md px-3 text-sm transition-colors',
      on
        ? 'bg-muted font-medium text-foreground'
        : 'text-muted-foreground hover:bg-muted/60 hover:text-foreground',
    );

  if (!section.children) {
    return (
      <Link
        href={section.href}
        className={itemClass(active)}
        onClick={onNavigate}
        aria-current={active ? 'page' : undefined}
      >
        <Icon className="size-4 shrink-0" />
        <span className="truncate">{t(section.key)}</span>
        {section.plannedPhase ? (
          <span className="ml-auto size-1.5 rounded-full bg-border" aria-hidden />
        ) : null}
      </Link>
    );
  }

  return (
    <div>
      <button
        type="button"
        className={itemClass(false)}
        onClick={() => setOpen((v) => !v)}
        aria-expanded={open}
      >
        <Icon className={cn('size-4 shrink-0', active && 'text-foreground')} />
        <span className={cn('truncate', active && 'font-medium text-foreground')}>
          {t(section.key)}
        </span>
        <ChevronDown className={cn('ml-auto size-4 transition-transform', open && 'rotate-180')} />
      </button>
      {open ? (
        <div className="ml-5 mt-0.5 grid gap-0.5 border-l pl-2.5">
          {section.children.map((child) => {
            const on = isActive(pathname, child.href);
            return (
              <Link
                key={child.key}
                href={child.href}
                onClick={onNavigate}
                aria-current={on ? 'page' : undefined}
                className={cn(
                  'flex h-8 items-center rounded-md px-2.5 text-sm transition-colors',
                  on
                    ? 'bg-muted font-medium text-foreground'
                    : 'text-muted-foreground hover:text-foreground',
                )}
              >
                <span className="truncate">{t(child.key)}</span>
                {child.plannedPhase ? (
                  <span className="ml-auto size-1.5 rounded-full bg-border" aria-hidden />
                ) : null}
              </Link>
            );
          })}
        </div>
      ) : null}
    </div>
  );
}

export function SidebarNav({ onNavigate }: { onNavigate?: () => void }) {
  const me = useMe();
  const t = useTranslations('nav');
  return (
    <nav aria-label={t('mainMenu')} className="grid gap-0.5">
      {visibleNavigation(me.permissions).map((section) => (
        <Section key={section.key} section={section} onNavigate={onNavigate} />
      ))}
    </nav>
  );
}

export function Brand() {
  return (
    <Link href="/dashboard" className="flex items-center gap-2.5 px-3">
      <div className="flex size-8 items-center justify-center rounded-lg bg-primary text-sm font-bold text-primary-foreground">
        F
      </div>
      <span className="font-semibold">Fluggi</span>
    </Link>
  );
}
