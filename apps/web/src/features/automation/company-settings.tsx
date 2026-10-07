'use client';

import { TAX_REGIMES, type CompanySettings } from '@fluggi/contracts';
import { useQuery } from '@tanstack/react-query';
import { useTranslations } from 'next-intl';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { ErrorState, TableSkeleton } from '@/components/shared/states';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input, NativeSelect } from '@/components/ui/input';
import { Field } from '@/components/ui/label';
import { ApiError, api, errorMessage } from '@/lib/api-client';
import { useInvalidating } from './api';

/** Реквизиты компании — попадают в пакет для бухгалтера. */
export function CompanySettingsCard() {
  const t = useTranslations('company');
  const q = useQuery({
    queryKey: ['settings', 'company'],
    queryFn: () => api<CompanySettings>('/settings/company'),
  });
  const [v, setV] = useState<CompanySettings | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  useEffect(() => {
    if (q.data) setV(q.data);
  }, [q.data]);
  const save = useInvalidating([['settings', 'company']], () =>
    api<CompanySettings>('/settings/company', { method: 'PUT', body: v }),
  );
  const set =
    (k: keyof CompanySettings) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) =>
      setV((s) => (s ? { ...s, [k]: e.target.value } : s));

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('title')}</CardTitle>
        <CardDescription>{t('text')}</CardDescription>
      </CardHeader>
      <CardContent>
        {q.isPending || !v ? (
          q.isError ? (
            <ErrorState error={q.error} onRetry={() => q.refetch()} />
          ) : (
            <TableSkeleton rows={3} cols={1} />
          )
        ) : (
          <form
            className="grid gap-4 sm:grid-cols-2"
            onSubmit={async (e) => {
              e.preventDefault();
              setErrors({});
              try {
                await save.mutateAsync(undefined);
                toast.success(t('saved'));
              } catch (err) {
                if (err instanceof ApiError) setErrors(err.fieldErrors());
                toast.error(errorMessage(err));
              }
            }}
          >
            <Field label={t('name')} htmlFor="co-name">
              <Input id="co-name" value={v.name} onChange={set('name')} />
            </Field>
            <Field label={t('inn')} htmlFor="co-inn" error={errors.inn}>
              <Input
                id="co-inn"
                inputMode="numeric"
                maxLength={9}
                value={v.inn}
                onChange={set('inn')}
              />
            </Field>
            <Field label={t('regime')} htmlFor="co-regime">
              <NativeSelect id="co-regime" value={v.taxRegime} onChange={set('taxRegime')}>
                {TAX_REGIMES.map((r) => (
                  <option key={r} value={r}>
                    {t(`regimes.${r}`)}
                  </option>
                ))}
              </NativeSelect>
            </Field>
            <Field label={t('director')} htmlFor="co-director">
              <Input id="co-director" value={v.director} onChange={set('director')} />
            </Field>
            <Field label={t('accountant')} htmlFor="co-accountant">
              <Input id="co-accountant" value={v.accountant} onChange={set('accountant')} />
            </Field>
            <div className="flex items-end">
              <Button type="submit" loading={save.isPending}>
                {t('save')}
              </Button>
            </div>
          </form>
        )}
      </CardContent>
    </Card>
  );
}
