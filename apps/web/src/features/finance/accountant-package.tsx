'use client';

import { FileSpreadsheet } from 'lucide-react';
import Link from 'next/link';
import { useTranslations } from 'next-intl';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter } from '@/components/ui/dialog';
import { NativeSelect } from '@/components/ui/input';
import { Field } from '@/components/ui/label';
import { useCan } from '@/lib/me-context';

/** Годовой пакет для бухгалтера: один Excel со всеми данными года (только CEO). */
export function AccountantPackageButton() {
  const t = useTranslations('accountant');
  const can = useCan();
  const thisYear = new Date(Date.now() + 5 * 3_600_000).getUTCFullYear();
  const [open, setOpen] = useState(false);
  const [year, setYear] = useState(String(thisYear - (new Date().getUTCMonth() < 3 ? 1 : 0)));
  if (!can('finance.company.read', 'ALL') || !can('export.run')) return null;
  const years = Array.from({ length: 5 }, (_, i) => thisYear - i);
  return (
    <>
      <Button variant="outline" onClick={() => setOpen(true)}>
        <FileSpreadsheet className="size-4" /> {t('button')}
      </Button>
      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent title={t('title')} description={t('text')}>
          <Field label={t('year')} htmlFor="acc-year">
            <NativeSelect id="acc-year" value={year} onChange={(e) => setYear(e.target.value)}>
              {years.map((y) => (
                <option key={y} value={y}>
                  {y}
                </option>
              ))}
            </NativeSelect>
          </Field>
          <ul className="grid gap-1 text-sm text-muted-foreground">
            {['c1', 'c2', 'c3', 'c4', 'c5', 'c6'].map((k) => (
              <li key={k}>• {t(`contents.${k}`)}</li>
            ))}
          </ul>
          <p className="text-xs text-muted-foreground">
            {t('companyHint')}{' '}
            <Link href="/settings/automation" className="underline underline-offset-2">
              {t('companyLink')}
            </Link>
          </p>
          <DialogFooter>
            <Button asChild>
              <a
                href={`/api/v1/exports/accountant-package?year=${year}`}
                onClick={() => setOpen(false)}
              >
                <FileSpreadsheet className="size-4" /> {t('download')}
              </a>
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
