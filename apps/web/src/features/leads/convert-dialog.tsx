'use client';

import { CLIENT_TYPES, CURRENCIES, type ClientType, type LeadDto } from '@fluggi/contracts';
import { useTranslations } from 'next-intl';
import { useRouter } from 'next/navigation';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter } from '@/components/ui/dialog';
import { Input, NativeSelect } from '@/components/ui/input';
import { Field } from '@/components/ui/label';
import { useClients, useCrmMutation } from '@/features/crm/api';
import { ApiError, api, errorMessage } from '@/lib/api-client';
import { newIdempotencyKey } from '@/lib/format';
import { useCan } from '@/lib/me-context';

/** Квалификация: лид → клиент (новый или существующий) + сделка. */
export function ConvertDialog({
  lead,
  open,
  onOpenChange,
}: {
  lead: LeadDto;
  open: boolean;
  onOpenChange: (o: boolean) => void;
}) {
  const t = useTranslations();
  const can = useCan();
  const router = useRouter();
  const [mode, setMode] = useState<'new' | 'existing'>('new');
  const [clientId, setClientId] = useState('');
  const [clientName, setClientName] = useState('');
  const [clientType, setClientType] = useState<ClientType>('COMPANY');
  const [amount, setAmount] = useState('');
  const [currency, setCurrency] = useState(lead.currency);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const key = useMemo(() => (open ? newIdempotencyKey() : ''), [open]);
  const clients = useClients(
    { q: lead.companyName ?? undefined, pageSize: 20 },
    open && can('client.read'),
  );

  useEffect(() => {
    if (!open) return;
    setMode('new');
    setClientId('');
    setClientName(lead.companyName ?? lead.contactName ?? '');
    setClientType(lead.companyName ? 'COMPANY' : 'PERSON');
    setAmount(lead.budget ? String(Number(lead.budget)) : '');
    setCurrency(lead.currency);
    setErrors({});
  }, [open, lead]);

  const convert = useCrmMutation(() =>
    api<{ dealId: string }>(`/leads/${lead.id}/convert`, {
      method: 'POST',
      idempotencyKey: key,
      body:
        mode === 'existing'
          ? { clientId, amount, currency }
          : { clientName, clientType, amount, currency },
    }),
  );

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent title={t('leads.convertTitle')} description={t('leads.convertHint')}>
        <form
          className="grid gap-4"
          onSubmit={async (e) => {
            e.preventDefault();
            try {
              const res = await convert.mutateAsync(undefined);
              toast.success(t('leads.converted'));
              onOpenChange(false);
              router.push(`/sales/deals/${res.dealId}`);
            } catch (err) {
              if (err instanceof ApiError) setErrors(err.fieldErrors());
              toast.error(errorMessage(err));
            }
          }}
        >
          <div className="flex gap-2">
            {(['new', 'existing'] as const).map((m) => (
              <Button
                key={m}
                type="button"
                size="sm"
                variant={mode === m ? 'default' : 'outline'}
                onClick={() => setMode(m)}
              >
                {m === 'new' ? t('leads.newClient') : t('leads.existingClient')}
              </Button>
            ))}
          </div>
          {mode === 'new' ? (
            <div className="grid gap-4 sm:grid-cols-[1fr_10rem]">
              <Field label={t('leads.clientName')} htmlFor="cv-name" error={errors.clientName}>
                <Input
                  id="cv-name"
                  value={clientName}
                  onChange={(e) => setClientName(e.target.value)}
                />
              </Field>
              <Field label={t('crm.fields.type')} htmlFor="cv-type">
                <NativeSelect
                  id="cv-type"
                  value={clientType}
                  onChange={(e) => setClientType(e.target.value as ClientType)}
                >
                  {CLIENT_TYPES.map((c) => (
                    <option key={c} value={c}>
                      {t(`crm.clientType.${c}`)}
                    </option>
                  ))}
                </NativeSelect>
              </Field>
            </div>
          ) : (
            <Field label={t('crm.fields.client')} htmlFor="cv-client" error={errors.clientName}>
              <NativeSelect
                id="cv-client"
                value={clientId}
                onChange={(e) => setClientId(e.target.value)}
              >
                <option value="">{t('crm.common.select')}</option>
                {clients.data?.items.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.name} ({c.number})
                  </option>
                ))}
              </NativeSelect>
            </Field>
          )}
          <div className="grid grid-cols-[1fr_6rem] gap-2">
            <Field label={t('crm.fields.amount')} htmlFor="cv-amount" error={errors.amount}>
              <Input
                id="cv-amount"
                inputMode="decimal"
                value={amount}
                onChange={(e) => setAmount(e.target.value)}
              />
            </Field>
            <Field label={t('crm.fields.currency')} htmlFor="cv-cur">
              <NativeSelect
                id="cv-cur"
                value={currency}
                onChange={(e) => setCurrency(e.target.value as typeof currency)}
              >
                {CURRENCIES.map((c) => (
                  <option key={c} value={c}>
                    {c}
                  </option>
                ))}
              </NativeSelect>
            </Field>
          </div>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Отмена
            </Button>
            <Button
              type="submit"
              variant="accent"
              disabled={!amount || (mode === 'existing' ? !clientId : !clientName.trim())}
              loading={convert.isPending}
              loadingText="Создание..."
            >
              {t('leads.convert')}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
