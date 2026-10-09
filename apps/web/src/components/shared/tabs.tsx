'use client';

import { cn } from '@/lib/utils';

export interface TabItem {
  key: string;
  label: string;
  /** Фаза, в которой вкладка заработает — вкладка видна, но неактивна. */
  plannedPhase?: number;
  count?: number;
}

export function Tabs({
  items,
  value,
  onChange,
}: {
  items: TabItem[];
  value: string;
  onChange: (key: string) => void;
}) {
  return (
    <div
      className="-mx-4 mb-5 flex gap-1 overflow-x-auto border-b px-4 sm:mx-0 sm:px-0"
      role="tablist"
    >
      {items.map((tab) => {
        const active = tab.key === value;
        return (
          <button
            key={tab.key}
            type="button"
            role="tab"
            aria-selected={active}
            disabled={Boolean(tab.plannedPhase)}
            title={tab.plannedPhase ? `Phase ${tab.plannedPhase}` : undefined}
            onClick={() => onChange(tab.key)}
            className={cn(
              '-mb-px flex items-center gap-1.5 whitespace-nowrap border-b-2 px-3 py-2.5 text-sm transition-colors disabled:cursor-not-allowed',
              active
                ? 'border-foreground font-medium'
                : 'border-transparent text-muted-foreground hover:text-foreground',
              tab.plannedPhase && 'opacity-50 hover:text-muted-foreground',
            )}
          >
            {tab.label}
            {tab.count ? (
              <span className="rounded-full bg-muted px-1.5 text-xs">{tab.count}</span>
            ) : null}
            {tab.plannedPhase ? (
              <span className="text-[10px] uppercase">P{tab.plannedPhase}</span>
            ) : null}
          </button>
        );
      })}
    </div>
  );
}
