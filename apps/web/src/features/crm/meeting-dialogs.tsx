'use client';

import { MEETING_TYPES, type MeetingType } from '@fluggi/contracts';
import { useTranslations } from 'next-intl';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter } from '@/components/ui/dialog';
import { Input, NativeSelect } from '@/components/ui/input';
import { Field } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { ApiError, api, errorMessage } from '@/lib/api-client';
import { fromLocalInput, newIdempotencyKey, toLocalInput } from '@/lib/format';
import { useCrmMutation } from './api';

function defaultStart() {
  const d = new Date(Date.now() + 24 * 3600_000);
  d.setUTCMinutes(0, 0, 0);
  return toLocalInput(d.toISOString());
}

export function CreateMeetingDialog({
  open,
  onOpenChange,
  target,
}: {
  open: boolean;
  onOpenChange: (o: boolean) => void;
  target: { leadId?: string; dealId?: string };
}) {
  const t = useTranslations('crm');
  const [form, setForm] = useState({
    startsAt: defaultStart(),
    durationMin: '60',
    type: 'OFFLINE' as MeetingType,
    link: '',
    comment: '',
  });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const key = useMemo(() => (open ? newIdempotencyKey() : ''), [open]);
  useEffect(() => {
    if (open) {
      setForm({
        startsAt: defaultStart(),
        durationMin: '60',
        type: 'OFFLINE',
        link: '',
        comment: '',
      });
      setErrors({});
    }
  }, [open]);
  const create = useCrmMutation(() =>
    api('/meetings', {
      method: 'POST',
      idempotencyKey: key,
      body: {
        ...target,
        startsAt: fromLocalInput(form.startsAt),
        durationMin: Number(form.durationMin),
        type: form.type,
        link: form.link || undefined,
        comment: form.comment || undefined,
      },
    }),
  );
  const set =
    (k: keyof typeof form) =>
    (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) =>
      setForm((f) => ({ ...f, [k]: e.target.value }));

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent title={t('common.newMeeting')}>
        <form
          className="grid gap-4"
          onSubmit={async (e) => {
            e.preventDefault();
            try {
              await create.mutateAsync(undefined);
              toast.success(t('common.meetingCreated'));
              onOpenChange(false);
            } catch (err) {
              if (err instanceof ApiError) setErrors(err.fieldErrors());
              toast.error(errorMessage(err));
            }
          }}
        >
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label={t('fields.startsAt')} htmlFor="m-start" error={errors.startsAt}>
              <Input
                id="m-start"
                type="datetime-local"
                required
                value={form.startsAt}
                onChange={set('startsAt')}
              />
            </Field>
            <Field label={t('fields.durationMin')} htmlFor="m-dur">
              <Input
                id="m-dur"
                type="number"
                min={5}
                max={600}
                step={5}
                value={form.durationMin}
                onChange={set('durationMin')}
              />
            </Field>
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label={t('fields.type')} htmlFor="m-type">
              <NativeSelect id="m-type" value={form.type} onChange={set('type')}>
                {MEETING_TYPES.map((mt) => (
                  <option key={mt} value={mt}>
                    {t(`meetingType.${mt}`)}
                  </option>
                ))}
              </NativeSelect>
            </Field>
            <Field label={t('fields.link')} htmlFor="m-link">
              <Input id="m-link" value={form.link} onChange={set('link')} />
            </Field>
          </div>
          <Field label={t('fields.comment')} htmlFor="m-comment">
            <Textarea id="m-comment" value={form.comment} onChange={set('comment')} />
          </Field>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Отмена
            </Button>
            <Button type="submit" loading={create.isPending} loadingText="Создание...">
              {t('common.newMeeting')}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

export function CompleteMeetingDialog({
  meetingId,
  onOpenChange,
}: {
  meetingId: string | null;
  onOpenChange: (o: boolean) => void;
}) {
  const t = useTranslations('crm');
  const tm = useTranslations('meetings');
  const [result, setResult] = useState('');
  useEffect(() => {
    if (meetingId) setResult('');
  }, [meetingId]);
  const complete = useCrmMutation(() =>
    api(`/meetings/${meetingId}/complete`, { method: 'POST', body: { result } }),
  );
  return (
    <Dialog open={meetingId !== null} onOpenChange={onOpenChange}>
      <DialogContent title={tm('completeTitle')}>
        <Field label={t('fields.result')} htmlFor="m-result" hint={tm('resultHint')}>
          <Textarea
            id="m-result"
            autoFocus
            rows={4}
            value={result}
            onChange={(e) => setResult(e.target.value)}
          />
        </Field>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Отмена
          </Button>
          <Button
            disabled={!result.trim()}
            loading={complete.isPending}
            loadingText="Сохранение..."
            onClick={async () => {
              try {
                await complete.mutateAsync(undefined);
                toast.success(t('common.meetingDone'));
                onOpenChange(false);
              } catch (err) {
                toast.error(errorMessage(err));
              }
            }}
          >
            {t('common.completeMeeting')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
