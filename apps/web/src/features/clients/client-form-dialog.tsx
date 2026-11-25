'use client';

import { CLIENT_TYPES, type ClientDetailDto, type ClientType } from '@fluggi/contracts';
import { useTranslations } from 'next-intl';
import { useRouter } from 'next/navigation';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter } from '@/components/ui/dialog';
import { Input, NativeSelect } from '@/components/ui/input';
import { Field } from '@/components/ui/label';
import { useCrmMutation, useReferences } from '@/features/crm/api';
import { ApiError, api, errorMessage } from '@/lib/api-client';
import { newIdempotencyKey } from '@/lib/format';

const TEXT = [
  'name',
  'industry',
  'phone',
  'email',
  'telegram',
  'website',
  'city',
  'country',
] as const;

export function ClientFormDialog({
  open,
  onOpenChange,
  client,
}: {
  open: boolean;
  onOpenChange: (o: boolean) => void;
  client?: ClientDetailDto | null;
}) {
  const t = useTranslations();
  const router = useRouter();
  const refs = useReferences();
  const [v, setV] = useState<Record<string, string>>({});
  const [errors, setErrors] = useState<Record<string, string>>({});
  const key = useMemo(() => (open ? newIdempotencyKey() : ''), [open]);

  useEffect(() => {
    if (!open) return;
    setErrors({});
    setV({
      name: client?.name ?? '',
      type: client?.type ?? 'COMPANY',
      industry: client?.industry ?? '',
      phone: client?.phone ?? '',
      email: client?.email ?? '',
      telegram: client?.telegram ?? '',
      website: client?.website ?? '',
      city: client?.city ?? '',
      country: client?.country ?? '',
      sourceId: client?.source?.id ?? '',
      contactName: '',
      contactPhone: '',
    });
  }, [open, client]);
  const set = (k: string) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) =>
    setV((s) => ({ ...s, [k]: e.target.value }));

  const save = useCrmMutation(() => {
    const body: Record<string, unknown> = { type: v.type, sourceId: v.sourceId || undefined };
    for (const k of TEXT) body[k] = v[k]?.trim() || undefined;
    if (client) return api<ClientDetailDto>(`/clients/${client.id}`, { method: 'PATCH', body });
    if (v.contactName?.trim())
      body.contact = { fullName: v.contactName.trim(), phone: v.contactPhone?.trim() || undefined };
    return api<ClientDetailDto>('/clients', { method: 'POST', body, idempotencyKey: key });
  });

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        title={client ? t('clients.editTitle') : t('clients.createTitle')}
        className="sm:max-w-2xl"
      >
        <form
          className="grid gap-4"
          noValidate
          onSubmit={async (e) => {
            e.preventDefault();
            try {
              const saved = await save.mutateAsync(undefined);
              toast.success(client ? t('crm.common.saved') : t('crm.common.created'));
              onOpenChange(false);
              if (!client) router.push(`/clients/${saved.id}`);
            } catch (err) {
              if (err instanceof ApiError) setErrors(err.fieldErrors());
              toast.error(errorMessage(err));
            }
          }}
        >
          <div className="grid gap-4 sm:grid-cols-[1fr_10rem]">
            <Field label={t('crm.fields.title')} htmlFor="cl-name" error={errors.name}>
              <Input id="cl-name" value={v.name ?? ''} onChange={set('name')} autoFocus />
            </Field>
            <Field label={t('crm.fields.type')} htmlFor="cl-type">
              <NativeSelect id="cl-type" value={v.type} onChange={set('type')}>
                {CLIENT_TYPES.map((c: ClientType) => (
                  <option key={c} value={c}>
                    {t(`crm.clientType.${c}`)}
                  </option>
                ))}
              </NativeSelect>
            </Field>
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            {(
              ['phone', 'email', 'telegram', 'website', 'industry', 'city', 'country'] as const
            ).map((k) => (
              <Field key={k} label={t(`crm.fields.${k}`)} htmlFor={`cl-${k}`} error={errors[k]}>
                <Input id={`cl-${k}`} value={v[k] ?? ''} onChange={set(k)} />
              </Field>
            ))}
            <Field label={t('crm.fields.source')} htmlFor="cl-source">
              <NativeSelect id="cl-source" value={v.sourceId ?? ''} onChange={set('sourceId')}>
                <option value="">{t('crm.common.none')}</option>
                {refs.data?.sources.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.name}
                  </option>
                ))}
              </NativeSelect>
            </Field>
          </div>
          {!client ? (
            <div className="grid gap-4 rounded-md border border-dashed p-3 sm:grid-cols-2">
              <Field
                label={`${t('crm.fields.contact')}: ${t('crm.fields.contactName').toLowerCase()}`}
                htmlFor="cl-cn"
              >
                <Input id="cl-cn" value={v.contactName ?? ''} onChange={set('contactName')} />
              </Field>
              <Field
                label={`${t('crm.fields.contact')}: ${t('crm.fields.phone').toLowerCase()}`}
                htmlFor="cl-cp"
                error={errors['contact.phone']}
              >
                <Input id="cl-cp" value={v.contactPhone ?? ''} onChange={set('contactPhone')} />
              </Field>
            </div>
          ) : null}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Отмена
            </Button>
            <Button
              type="submit"
              loading={save.isPending}
              loadingText={client ? 'Сохранение...' : 'Создание...'}
            >
              {client ? 'Сохранить' : 'Создать клиента'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
