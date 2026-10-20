'use client';

import { useTranslations } from 'next-intl';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter } from '@/components/ui/dialog';
import { Field } from '@/components/ui/label';
import { api, errorMessage } from '@/lib/api-client';
import { useCrmMutation } from './api';
import { OwnerSelect } from './owner-select';

export function AssignDialog({
  open,
  onOpenChange,
  path,
  currentOwnerId,
}: {
  open: boolean;
  onOpenChange: (o: boolean) => void;
  path: string;
  currentOwnerId: string;
}) {
  const t = useTranslations('crm');
  const [ownerId, setOwnerId] = useState(currentOwnerId);
  useEffect(() => {
    if (open) setOwnerId(currentOwnerId);
  }, [open, currentOwnerId]);
  const assign = useCrmMutation(() => api(`${path}/assign`, { method: 'POST', body: { ownerId } }));
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent title={t('common.assign')}>
        <Field label={t('fields.owner')} htmlFor="assign-owner">
          <OwnerSelect
            id="assign-owner"
            value={ownerId}
            onChange={(e) => setOwnerId(e.target.value)}
          />
        </Field>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Отмена
          </Button>
          <Button
            disabled={ownerId === currentOwnerId}
            loading={assign.isPending}
            loadingText="Сохранение..."
            onClick={async () => {
              try {
                await assign.mutateAsync(undefined);
                toast.success(t('common.assigned'));
                onOpenChange(false);
              } catch (err) {
                toast.error(errorMessage(err));
              }
            }}
          >
            Сохранить
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
