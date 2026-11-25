'use client';

import { CLOSE_STATUSES, REASON_REQUIRED_STATUSES, type CloseStatus } from '@fluggi/contracts';
import { useTranslations } from 'next-intl';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter } from '@/components/ui/dialog';
import { NativeSelect } from '@/components/ui/input';
import { Field } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { api, errorMessage } from '@/lib/api-client';
import { useCrmMutation, useReferences } from './api';

/** Закрытие лида/сделки: Потеряно / Отказ (с причиной) / Пауза / Не отвечает (ТЗ §7, §39). */
export function CloseDialog({
  open,
  onOpenChange,
  path,
}: {
  open: boolean;
  onOpenChange: (o: boolean) => void;
  /** /leads/:id или /deals/:id */
  path: string;
}) {
  const t = useTranslations('crm');
  const refs = useReferences();
  const [status, setStatus] = useState<CloseStatus>('LOST');
  const [reasonId, setReasonId] = useState('');
  const [comment, setComment] = useState('');
  const close = useCrmMutation(() =>
    api(`${path}/close`, {
      method: 'POST',
      body: { status, lossReasonId: reasonId || undefined, comment: comment || undefined },
    }),
  );

  useEffect(() => {
    if (open) {
      setStatus('LOST');
      setReasonId('');
      setComment('');
    }
  }, [open]);

  const needReason = REASON_REQUIRED_STATUSES.includes(status);
  const reason = refs.data?.lossReasons.find((r) => r.id === reasonId);
  const invalid = (needReason && !reasonId) || (reason?.requiresComment && !comment.trim());

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent title={t('common.closeTitle')} description={t('common.closeHint')}>
        <div className="grid gap-4">
          <Field label={t('fields.status')} htmlFor="close-status">
            <NativeSelect
              id="close-status"
              value={status}
              onChange={(e) => setStatus(e.target.value as CloseStatus)}
            >
              {CLOSE_STATUSES.map((s) => (
                <option key={s} value={s}>
                  {t(`closeStatus.${s}`)}
                </option>
              ))}
            </NativeSelect>
          </Field>
          <Field label={t('fields.lossReason')} htmlFor="close-reason">
            <NativeSelect
              id="close-reason"
              value={reasonId}
              onChange={(e) => setReasonId(e.target.value)}
              aria-invalid={needReason && !reasonId}
            >
              <option value="">{needReason ? t('common.select') : t('common.none')}</option>
              {refs.data?.lossReasons
                .filter((r) => r.isActive)
                .map((r) => (
                  <option key={r.id} value={r.id}>
                    {r.name}
                  </option>
                ))}
            </NativeSelect>
          </Field>
          <Field label={t('fields.comment')} htmlFor="close-comment">
            <Textarea
              id="close-comment"
              value={comment}
              onChange={(e) => setComment(e.target.value)}
              aria-invalid={Boolean(reason?.requiresComment && !comment.trim())}
            />
          </Field>
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Отмена
          </Button>
          <Button
            variant="destructive"
            disabled={Boolean(invalid)}
            loading={close.isPending}
            loadingText="Сохранение..."
            onClick={async () => {
              try {
                await close.mutateAsync(undefined);
                toast.success(t('common.closed'));
                onOpenChange(false);
              } catch (err) {
                toast.error(errorMessage(err));
              }
            }}
          >
            {t('common.close')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
