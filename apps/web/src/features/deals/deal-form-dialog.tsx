'use client';

import { CURRENCIES, type Currency, type DealDto } from '@fluggi/contracts';
import { useTranslations } from 'next-intl';
import { useRouter } from 'next/navigation';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter } from '@/components/ui/dialog';
import { Input, NativeSelect } from '@/components/ui/input';
import { Field } from '@/components/ui/label';
import { useClient, useClients, useCrmMutation, useReferences } from '@/features/crm/api';
import { ApiError, api, errorMessage } from '@/lib/api-client';
import { newIdempotencyKey } from '@/lib/format';

interface Values {
  clientId: string;
  contactId: string;
  title: string;
  serviceId: string;
  amount: string;
  currency: Currency;
  expectedCloseDate: string;
  probabilityOverride: string;
}

export function DealFormDialog({
  open,
  onOpenChange,
  deal,
  clientId: fixedClientId,
}: {
  open: boolean;
  onOpenChange: (o: boolean) => void;
  deal?: DealDto | null;
  clientId?: string;
}) {
  const t = useTranslations();
  const router = useRouter();
  const refs = useReferences();
  const [v, setV] = useState<Values>({} as Values);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const key = useMemo(() => (open ? newIdempotencyKey() : ''), [open]);
  const clients = useClients({ pageSize: 100 }, open && !deal && !fixedClientId);
  const client = useClient(open ? (deal?.client.id ?? fixedClientId ?? v.clientId) : null);

  useEffect(() => {
    if (!open) return;
    setErrors({});
    setV({
      clientId: deal?.client.id ?? fixedClientId ?? '',
      contactId: deal?.contact?.id ?? '',
      title: deal?.title ?? '',
      serviceId: deal?.service?.id ?? '',
      amount: deal ? String(Number(deal.amount)) : '',
      currency: deal?.currency ?? 'UZS',
      expectedCloseDate: deal?.expectedCloseDate ?? '',
      probabilityOverride:
        deal?.probabilityOverride != null ? String(deal.probabilityOverride) : '',
    });
  }, [open, deal, fixedClientId]);

  const set = (k: keyof Values) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) =>
    setV((s) => ({ ...s, [k]: e.target.value }));

  const save = useCrmMutation(() =>
    deal
      ? api<DealDto>(`/deals/${deal.id}`, {
          method: 'PATCH',
          body: {
            title: v.title,
            contactId: v.contactId || null,
            serviceId: v.serviceId || null,
            amount: v.amount,
            currency: v.currency,
            expectedCloseDate: v.expectedCloseDate || null,
            probabilityOverride:
              v.probabilityOverride === '' ? null : Number(v.probabilityOverride),
          },
        })
      : api<DealDto>('/deals', {
          method: 'POST',
          idempotencyKey: key,
          body: {
            clientId: v.clientId,
            contactId: v.contactId || undefined,
            title: v.title,
            serviceId: v.serviceId || undefined,
            amount: v.amount,
            currency: v.currency,
            expectedCloseDate: v.expectedCloseDate || undefined,
          },
        }),
  );

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        title={deal ? t('deals.editTitle') : t('deals.createTitle')}
        description={deal ? undefined : t('deals.createHint')}
      >
        <form
          className="grid gap-4"
          noValidate
          onSubmit={async (e) => {
            e.preventDefault();
            setErrors({});
            try {
              const saved = await save.mutateAsync(undefined);
              toast.success(deal ? t('crm.common.saved') : t('crm.common.created'));
              onOpenChange(false);
              if (!deal) router.push(`/sales/deals/${saved.id}`);
            } catch (err) {
              if (err instanceof ApiError) setErrors(err.fieldErrors());
              toast.error(errorMessage(err));
            }
          }}
        >
          {!deal && !fixedClientId ? (
            <Field label={t('crm.fields.client')} htmlFor="d-client" error={errors.clientId}>
              <NativeSelect id="d-client" value={v.clientId} onChange={set('clientId')}>
                <option value="">{t('crm.common.select')}</option>
                {clients.data?.items.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.name}
                  </option>
                ))}
              </NativeSelect>
            </Field>
          ) : null}
          <Field label={t('crm.fields.title')} htmlFor="d-title" error={errors.title}>
            <Input id="d-title" value={v.title ?? ''} onChange={set('title')} autoFocus />
          </Field>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label={t('crm.fields.service')} htmlFor="d-service">
              <NativeSelect id="d-service" value={v.serviceId ?? ''} onChange={set('serviceId')}>
                <option value="">{t('crm.common.none')}</option>
                {refs.data?.services
                  .filter((s) => s.isActive || s.id === v.serviceId)
                  .map((s) => (
                    <option key={s.id} value={s.id}>
                      {s.name}
                    </option>
                  ))}
              </NativeSelect>
            </Field>
            <Field label={t('crm.fields.contact')} htmlFor="d-contact">
              <NativeSelect id="d-contact" value={v.contactId ?? ''} onChange={set('contactId')}>
                <option value="">{t('crm.common.none')}</option>
                {client.data?.contacts?.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.fullName}
                  </option>
                ))}
              </NativeSelect>
            </Field>
            <div className="grid grid-cols-[1fr_6rem] gap-2">
              <Field label={t('crm.fields.amount')} htmlFor="d-amount" error={errors.amount}>
                <Input
                  id="d-amount"
                  inputMode="decimal"
                  value={v.amount ?? ''}
                  onChange={set('amount')}
                />
              </Field>
              <Field label={t('crm.fields.currency')} htmlFor="d-cur">
                <NativeSelect id="d-cur" value={v.currency ?? 'UZS'} onChange={set('currency')}>
                  {CURRENCIES.map((c) => (
                    <option key={c} value={c}>
                      {c}
                    </option>
                  ))}
                </NativeSelect>
              </Field>
            </div>
            <Field label={t('crm.fields.expectedCloseDate')} htmlFor="d-close">
              <Input
                id="d-close"
                type="date"
                value={v.expectedCloseDate ?? ''}
                onChange={set('expectedCloseDate')}
              />
            </Field>
            {deal ? (
              <Field
                label={`${t('crm.fields.probability')}, %`}
                htmlFor="d-prob"
                hint="Пусто — по этапу воронки"
              >
                <Input
                  id="d-prob"
                  type="number"
                  min={0}
                  max={100}
                  value={v.probabilityOverride ?? ''}
                  onChange={set('probabilityOverride')}
                />
              </Field>
            ) : null}
          </div>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Отмена
            </Button>
            <Button
              type="submit"
              loading={save.isPending}
              loadingText={deal ? 'Сохранение...' : 'Создание...'}
            >
              {deal ? 'Сохранить' : 'Создать сделку'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
