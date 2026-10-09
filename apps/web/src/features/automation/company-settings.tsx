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

const Section = ({ title }: { title: string }) => (
  <h3 className="border-b pb-1 pt-2 text-sm font-semibold sm:col-span-2">{title}</h3>
);

/** Реквизиты компании — в КП, договорах и пакете для бухгалтера. */
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
            <Section title={t('groupCompany')} />
            <Field label={t('name')} htmlFor="co-name" hint={t('nameHint')}>
              <Input id="co-name" value={v.name} onChange={set('name')} />
            </Field>
            <Field label={t('legalName')} htmlFor="co-legal" hint={t('legalNameHint')}>
              <Input id="co-legal" value={v.legalName} onChange={set('legalName')} />
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
            <Field label={t('oked')} htmlFor="co-oked">
              <Input id="co-oked" value={v.oked} onChange={set('oked')} />
            </Field>
            <Field label={t('vatCode')} htmlFor="co-vat">
              <Input id="co-vat" value={v.vatCode} onChange={set('vatCode')} />
            </Field>

            <Section title={t('groupSigner')} />
            <Field label={t('director')} htmlFor="co-director" hint={t('directorHint')}>
              <Input id="co-director" value={v.director} onChange={set('director')} />
            </Field>
            <Field label={t('directorPosition')} htmlFor="co-position">
              <Input
                id="co-position"
                value={v.directorPosition}
                onChange={set('directorPosition')}
              />
            </Field>
            <Field label={t('signerGenitive')} htmlFor="co-signer" hint={t('signerGenitiveHint')}>
              <Input id="co-signer" value={v.signerGenitive} onChange={set('signerGenitive')} />
            </Field>
            <Field label={t('basis')} htmlFor="co-basis" hint={t('basisHint')}>
              <Input id="co-basis" value={v.basis} onChange={set('basis')} />
            </Field>
            <Field label={t('accountant')} htmlFor="co-accountant">
              <Input id="co-accountant" value={v.accountant} onChange={set('accountant')} />
            </Field>

            <Section title={t('groupBank')} />
            <Field label={t('bank')} htmlFor="co-bank">
              <Input id="co-bank" value={v.bank} onChange={set('bank')} />
            </Field>
            <Field label={t('mfo')} htmlFor="co-mfo" error={errors.mfo}>
              <Input
                id="co-mfo"
                inputMode="numeric"
                maxLength={5}
                value={v.mfo}
                onChange={set('mfo')}
              />
            </Field>
            <Field label={t('account')} htmlFor="co-account" error={errors.account}>
              <Input
                id="co-account"
                inputMode="numeric"
                maxLength={20}
                value={v.account}
                onChange={set('account')}
              />
            </Field>

            <Section title={t('groupContacts')} />
            <Field label={t('address')} htmlFor="co-address">
              <Input id="co-address" value={v.address} onChange={set('address')} />
            </Field>
            <Field label={t('phone')} htmlFor="co-phone">
              <Input id="co-phone" value={v.phone} onChange={set('phone')} />
            </Field>
            <Field label={t('email')} htmlFor="co-email" error={errors.email}>
              <Input id="co-email" value={v.email} onChange={set('email')} />
            </Field>
            <Field label={t('website')} htmlFor="co-website">
              <Input id="co-website" value={v.website} onChange={set('website')} />
            </Field>
            <div className="flex items-end">
              <Button type="submit" loading={save.isPending}>
                {t('saveRequisites')}
              </Button>
            </div>
          </form>
        )}
      </CardContent>
    </Card>
  );
}
