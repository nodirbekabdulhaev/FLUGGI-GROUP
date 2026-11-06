'use client';

import { PERIOD_PRESETS, type PeriodPreset } from '@fluggi/contracts';
import { useTranslations } from 'next-intl';
import { usePathname, useRouter, useSearchParams } from 'next/navigation';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input, NativeSelect } from '@/components/ui/input';

export function usePeriod(): { preset: PeriodPreset; from?: string; to?: string } {
  const params = useSearchParams();
  const raw = params.get('period');
  const preset = (PERIOD_PRESETS as readonly string[]).includes(raw ?? '')
    ? (raw as PeriodPreset)
    : 'month';
  return { preset, from: params.get('from') ?? undefined, to: params.get('to') ?? undefined };
}

/**
 * Глобальный период CEO (ТЗ §4). Хранится в URL (?period=…), поэтому
 * сохраняется при обновлении страницы и им можно поделиться ссылкой.
 */
export function PeriodSelect() {
  const t = useTranslations('period');
  const router = useRouter();
  const pathname = usePathname();
  const params = useSearchParams();
  const { preset, from, to } = usePeriod();
  const [custom, setCustom] = useState({ from: from ?? '', to: to ?? '' });

  function apply(next: PeriodPreset, range?: { from: string; to: string }) {
    const q = new URLSearchParams(params.toString());
    q.set('period', next);
    q.delete('from');
    q.delete('to');
    if (next === 'custom' && range) {
      q.set('from', range.from);
      q.set('to', range.to);
    }
    router.replace(`${pathname}?${q.toString()}`, { scroll: false });
  }

  return (
    <div className="flex flex-wrap items-center gap-2">
      <NativeSelect
        aria-label={t('label')}
        className="h-9 w-auto min-w-40"
        value={preset}
        onChange={(e) => {
          const value = e.target.value as PeriodPreset;
          if (value !== 'custom') apply(value);
          else apply('custom', custom.from && custom.to ? custom : undefined);
        }}
      >
        {PERIOD_PRESETS.map((p) => (
          <option key={p} value={p}>
            {t(p)}
          </option>
        ))}
      </NativeSelect>
      {preset === 'custom' ? (
        <form
          className="flex items-center gap-2"
          onSubmit={(e) => {
            e.preventDefault();
            if (custom.from && custom.to) apply('custom', custom);
          }}
        >
          <Input
            type="date"
            aria-label={t('from')}
            className="h-9 w-auto"
            value={custom.from}
            onChange={(e) => setCustom((c) => ({ ...c, from: e.target.value }))}
          />
          <Input
            type="date"
            aria-label={t('to')}
            className="h-9 w-auto"
            value={custom.to}
            onChange={(e) => setCustom((c) => ({ ...c, to: e.target.value }))}
          />
          <Button
            type="submit"
            size="sm"
            variant="outline"
            disabled={!custom.from || !custom.to || custom.from > custom.to}
          >
            {t('apply')}
          </Button>
        </form>
      ) : null}
    </div>
  );
}
