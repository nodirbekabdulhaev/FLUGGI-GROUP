'use client';

import type { ContactDto } from '@fluggi/contracts';
import { useTranslations } from 'next-intl';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Field } from '@/components/ui/label';
import { useCrmMutation } from '@/features/crm/api';
import { ApiError, api, errorMessage } from '@/lib/api-client';

const FIELDS = ['fullName', 'position', 'phone', 'telegram', 'whatsapp', 'email'] as const;

export function ContactDialog({
  clientId,
  contact,
  open,
  onOpenChange,
}: {
  clientId: string;
  contact: ContactDto | null;
  open: boolean;
  onOpenChange: (o: boolean) => void;
}) {
  const t = useTranslations();
  const [v, setV] = useState<Record<string, string>>({});
  const [primary, setPrimary] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  useEffect(() => {
    if (!open) return;
    setErrors({});
    setPrimary(contact?.isPrimary ?? false);
    setV(Object.fromEntries(FIELDS.map((f) => [f, (contact?.[f] as string | null) ?? ''])));
  }, [open, contact]);
  const save = useCrmMutation(() => {
    const body = {
      ...Object.fromEntries(FIELDS.map((f) => [f, v[f]?.trim() || undefined])),
      isPrimary: primary,
    };
    return contact
      ? api(`/contacts/${contact.id}`, { method: 'PUT', body })
      : api(`/clients/${clientId}/contacts`, { method: 'POST', body });
  });
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent title={contact ? contact.fullName : t('clients.addContact')}>
        <form
          className="grid gap-4"
          noValidate
          onSubmit={async (e) => {
            e.preventDefault();
            try {
              await save.mutateAsync(undefined);
              toast.success(t('crm.common.saved'));
              onOpenChange(false);
            } catch (err) {
              if (err instanceof ApiError) setErrors(err.fieldErrors());
              toast.error(errorMessage(err));
            }
          }}
        >
          <div className="grid gap-4 sm:grid-cols-2">
            {FIELDS.map((f) => (
              <Field
                key={f}
                label={f === 'fullName' ? t('crm.fields.contactName') : t(`crm.fields.${f}`)}
                htmlFor={`ct-${f}`}
                error={errors[f]}
              >
                <Input
                  id={`ct-${f}`}
                  value={v[f] ?? ''}
                  onChange={(e) => setV((s) => ({ ...s, [f]: e.target.value }))}
                />
              </Field>
            ))}
          </div>
          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={primary}
              onChange={(e) => setPrimary(e.target.checked)}
              className="size-4"
            />
            {t('clients.primary')}
          </label>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Отмена
            </Button>
            <Button
              type="submit"
              disabled={!v.fullName?.trim()}
              loading={save.isPending}
              loadingText="Сохранение..."
            >
              Сохранить
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
