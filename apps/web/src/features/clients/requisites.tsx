'use client';

import type { ClientDetailDto, ClientRequisites } from '@fluggi/contracts';
import { Pencil } from 'lucide-react';
import { useTranslations } from 'next-intl';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { DetailList } from '@/components/shared/detail-list';
import { EmptyState } from '@/components/shared/states';
import { Button } from '@/components/ui/button';
import { CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogFooter } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Field } from '@/components/ui/label';
import { useCrmMutation } from '@/features/crm/api';
import { ApiError, api, errorMessage } from '@/lib/api-client';

const EMPTY: ClientRequisites = {
  legalName: '',
  inn: '',
  director: '',
  directorPosition: 'Директор',
  signerGenitive: '',
  basis: 'Устава',
  address: '',
  phone: '',
  bank: '',
  mfo: '',
  account: '',
  oked: '',
  vatCode: '',
};

type Key = keyof ClientRequisites;
const FIELDS: { key: Key; numeric?: number; wide?: boolean }[] = [
  { key: 'legalName', wide: true },
  { key: 'inn', numeric: 14 },
  { key: 'oked' },
  { key: 'director' },
  { key: 'directorPosition' },
  { key: 'signerGenitive', wide: true },
  { key: 'basis' },
  { key: 'vatCode' },
  { key: 'address', wide: true },
  { key: 'phone' },
  { key: 'bank' },
  { key: 'mfo', numeric: 5 },
  { key: 'account', numeric: 20 },
];

function RequisitesDialog({
  client,
  open,
  onOpenChange,
}: {
  client: ClientDetailDto;
  open: boolean;
  onOpenChange: (o: boolean) => void;
}) {
  const t = useTranslations('requisites');
  const [v, setV] = useState<ClientRequisites>(EMPTY);
  const [errors, setErrors] = useState<Record<string, string>>({});
  useEffect(() => {
    if (!open) return;
    setErrors({});
    setV({
      ...EMPTY,
      legalName: client.name,
      phone: client.phone ?? '',
      ...(client.requisites ?? {}),
    });
  }, [open, client]);
  const save = useCrmMutation(() =>
    api(`/clients/${client.id}/requisites`, { method: 'PUT', body: v }),
  );
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent title={t('title')} className="sm:max-w-2xl">
        <form
          className="grid gap-4 sm:grid-cols-2"
          onSubmit={async (e) => {
            e.preventDefault();
            try {
              await save.mutateAsync(undefined);
              toast.success(t('saved'));
              onOpenChange(false);
            } catch (err) {
              if (err instanceof ApiError) setErrors(err.fieldErrors());
              toast.error(errorMessage(err));
            }
          }}
        >
          {FIELDS.map((f) => (
            <div key={f.key} className={f.wide ? 'sm:col-span-2' : undefined}>
              <Field
                label={t(f.key)}
                htmlFor={`rq-${f.key}`}
                error={errors[f.key]}
                hint={
                  f.key === 'signerGenitive' || f.key === 'basis' ? t(`${f.key}Hint`) : undefined
                }
              >
                <Input
                  id={`rq-${f.key}`}
                  inputMode={f.numeric ? 'numeric' : undefined}
                  maxLength={f.numeric}
                  value={v[f.key]}
                  onChange={(e) => setV((s) => ({ ...s, [f.key]: e.target.value }))}
                />
              </Field>
            </div>
          ))}
          <DialogFooter className="sm:col-span-2">
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              {t('cancel')}
            </Button>
            <Button type="submit" loading={save.isPending}>
              {t('save')}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

/** Вкладка «Реквизиты» в карточке клиента — подставляются в договор. */
export function RequisitesPanel({
  client,
  canEdit,
}: {
  client: ClientDetailDto;
  canEdit: boolean;
}) {
  const t = useTranslations('requisites');
  const [open, setOpen] = useState(false);
  const r = client.requisites;
  return (
    <CardContent className="grid gap-4 pt-5">
      {r ? (
        <DetailList
          rows={FIELDS.map((f) => [t(f.key), r[f.key] || null] as [string, string | null])}
        />
      ) : (
        <EmptyState title={t('empty')} text={t('emptyText')} />
      )}
      {canEdit ? (
        <div>
          <Button size="sm" variant="outline" onClick={() => setOpen(true)}>
            <Pencil /> {t(r ? 'edit' : 'fill')}
          </Button>
        </div>
      ) : null}
      <RequisitesDialog client={client} open={open} onOpenChange={setOpen} />
    </CardContent>
  );
}
