'use client';

import { LEAD_STAGE_CODES, type LeadStageCode } from '@fluggi/contracts';
import {
  ArrowLeft,
  CalendarClock,
  CheckCircle2,
  MoreHorizontal,
  Pencil,
  Phone,
  RotateCcw,
  Send,
  Trash2,
  UserRound,
  XCircle,
} from 'lucide-react';
import { useTranslations } from 'next-intl';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { toast } from 'sonner';
import { PriorityText, ScoreBadge, StageBadge, StatusBadge } from '@/components/crm/badges';
import { DetailList } from '@/components/shared/detail-list';
import { ErrorState, TableSkeleton } from '@/components/shared/states';
import { Tabs } from '@/components/shared/tabs';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { NativeSelect } from '@/components/ui/input';
import { useCrmMutation, useLead, useReferences } from '@/features/crm/api';
import { AssignDialog } from '@/features/crm/assign-dialog';
import { CloseDialog } from '@/features/crm/close-dialog';
import { CommentsPanel, HistoryPanel, MeetingsPanel, TimelinePanel } from '@/features/crm/panels';
import { api, errorMessage } from '@/lib/api-client';
import { date, dateTime, money } from '@/lib/format';
import { useCan } from '@/lib/me-context';
import { ConvertDialog } from './convert-dialog';
import { LeadFormDialog } from './lead-form-dialog';

export function LeadCard({ id }: { id: string }) {
  const t = useTranslations();
  const can = useCan();
  const router = useRouter();
  const lead = useLead(id);
  const refs = useReferences();
  const [tab, setTab] = useState('overview');
  const [dialog, setDialog] = useState<'edit' | 'close' | 'assign' | 'convert' | null>(null);

  const stage = useCrmMutation((code: LeadStageCode) =>
    api(`/leads/${id}/stage`, { method: 'POST', body: { stageCode: code } }),
  );
  const reopen = useCrmMutation(() => api(`/leads/${id}/reopen`, { method: 'POST' }));
  const remove = useCrmMutation(() => api(`/leads/${id}`, { method: 'DELETE' }));

  if (lead.isPending)
    return (
      <Card>
        <TableSkeleton rows={6} cols={2} />
      </Card>
    );
  if (lead.isError)
    return (
      <Card>
        <ErrorState error={lead.error} onRetry={() => lead.refetch()} />
      </Card>
    );
  const l = lead.data;
  const open = l.status === 'OPEN';
  const canEdit = can('lead.update');
  const leadStages = refs.data?.stages.filter((s) => s.entity === 'LEAD') ?? [];
  const currentIdx = LEAD_STAGE_CODES.indexOf(l.stage.code as LeadStageCode);

  const run = async (p: Promise<unknown>, ok: string) => {
    try {
      await p;
      toast.success(ok);
    } catch (err) {
      toast.error(errorMessage(err));
    }
  };

  return (
    <>
      <Link
        href="/sales/leads"
        className="mb-4 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
      >
        <ArrowLeft className="size-4" /> {t('leads.title')}
      </Link>

      {/* Header (ТЗ §11): название, статус, ответственный, приоритет, сумма */}
      <div className="mb-6 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div className="min-w-0">
          <p className="text-xs text-muted-foreground">{l.number}</p>
          <h1 className="text-xl font-semibold tracking-tight sm:text-2xl">{l.title}</h1>
          <div className="mt-2 flex flex-wrap items-center gap-2 text-sm">
            {open ? <StageBadge stage={l.stage} /> : <StatusBadge status={l.status} kind="lead" />}
            <ScoreBadge level={l.scoreLevel} score={l.score} />
            <span className="text-muted-foreground">·</span>
            <span className="flex items-center gap-1 text-muted-foreground">
              <UserRound className="size-3.5" /> {l.owner.name}
            </span>
            <span className="text-muted-foreground">·</span>
            <PriorityText priority={l.priority} />
            <span className="text-muted-foreground">·</span>
            <span className="font-medium">{money(l.budget, l.currency)}</span>
          </div>
          {l.lossReason ? (
            <p className="mt-2 text-sm text-danger">
              {l.lossReason.name}
              {l.lossComment ? ` — ${l.lossComment}` : ''}
            </p>
          ) : null}
        </div>
        <div className="flex flex-wrap gap-2">
          {l.phone ? (
            <Button asChild variant="outline" size="sm">
              <a href={`tel:${l.phone}`}>
                <Phone /> {t('crm.common.byPhone')}
              </a>
            </Button>
          ) : null}
          {l.telegram ? (
            <Button asChild variant="outline" size="sm">
              <a
                href={`https://t.me/${l.telegram.replace(/^@/, '')}`}
                target="_blank"
                rel="noopener noreferrer"
              >
                <Send /> Telegram
              </a>
            </Button>
          ) : null}
          {open && can('deal.create') ? (
            <Button size="sm" variant="accent" onClick={() => setDialog('convert')}>
              <CheckCircle2 /> {t('leads.convert')}
            </Button>
          ) : null}
          {l.dealId ? (
            <Button asChild size="sm">
              <Link href={`/sales/deals/${l.dealId}`}>{t('leads.openDeal')}</Link>
            </Button>
          ) : null}
          {canEdit ? (
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="outline" size="icon-sm" aria-label={t('common.actions')}>
                  <MoreHorizontal />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent>
                <DropdownMenuItem onSelect={() => setDialog('edit')}>
                  <Pencil /> {t('crm.common.edit')}
                </DropdownMenuItem>
                {can('lead.assign') && open ? (
                  <DropdownMenuItem onSelect={() => setDialog('assign')}>
                    <UserRound /> {t('crm.common.assign')}
                  </DropdownMenuItem>
                ) : null}
                {open ? (
                  <DropdownMenuItem onSelect={() => setDialog('close')} destructive>
                    <XCircle /> {t('crm.common.close')}
                  </DropdownMenuItem>
                ) : l.status !== 'CONVERTED' ? (
                  <DropdownMenuItem
                    onSelect={() => run(reopen.mutateAsync(undefined), t('crm.common.reopened'))}
                  >
                    <RotateCcw /> {t('crm.common.reopen')}
                  </DropdownMenuItem>
                ) : null}
                {can('lead.delete') && l.status !== 'CONVERTED' ? (
                  <>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                      destructive
                      onSelect={async () => {
                        if (!window.confirm(t('crm.common.deleteConfirm', { name: l.title })))
                          return;
                        await run(remove.mutateAsync(undefined), t('crm.common.deleted'));
                        router.push('/sales/leads');
                      }}
                    >
                      <Trash2 /> {t('crm.common.delete')}
                    </DropdownMenuItem>
                  </>
                ) : null}
              </DropdownMenuContent>
            </DropdownMenu>
          ) : null}
        </div>
      </div>

      {/* Этапы лида — кликабельный прогресс */}
      {open && canEdit ? (
        <div className="mb-6">
          <div className="hidden gap-1 sm:flex">
            {leadStages.map((s, i) => (
              <button
                key={s.id}
                type="button"
                disabled={stage.isPending || s.code === l.stage.code}
                onClick={() =>
                  run(stage.mutateAsync(s.code as LeadStageCode), t('crm.common.stageChanged'))
                }
                className="flex-1 rounded-md px-2 py-2 text-xs font-medium transition-colors hover:opacity-90 disabled:cursor-default"
                style={{
                  backgroundColor: i <= currentIdx ? s.color : 'var(--color-muted)',
                  color: i <= currentIdx ? '#fff' : 'var(--color-muted-foreground)',
                }}
              >
                {s.name}
              </button>
            ))}
          </div>
          <NativeSelect
            className="sm:hidden"
            aria-label={t('crm.common.changeStage')}
            value={l.stage.code}
            onChange={(e) =>
              run(stage.mutateAsync(e.target.value as LeadStageCode), t('crm.common.stageChanged'))
            }
          >
            {leadStages.map((s) => (
              <option key={s.id} value={s.code}>
                {s.name}
              </option>
            ))}
          </NativeSelect>
        </div>
      ) : null}

      <Tabs
        value={tab}
        onChange={setTab}
        items={[
          { key: 'overview', label: t('leads.tabs.overview') },
          { key: 'activity', label: t('leads.tabs.activity') },
          { key: 'meetings', label: t('leads.tabs.meetings') },
          { key: 'comments', label: t('leads.tabs.comments') },
          { key: 'history', label: t('leads.tabs.history') },
          { key: 'tasks', label: t('leads.tabs.tasks'), plannedPhase: 4 },
          { key: 'proposals', label: t('leads.tabs.proposals'), plannedPhase: 3 },
          { key: 'contracts', label: t('leads.tabs.contracts'), plannedPhase: 3 },
          { key: 'payments', label: t('leads.tabs.payments'), plannedPhase: 3 },
          { key: 'projects', label: t('leads.tabs.projects'), plannedPhase: 4 },
          { key: 'files', label: t('leads.tabs.files'), plannedPhase: 3 },
        ]}
      />

      <Card>
        <CardContent className="pt-5">
          {tab === 'overview' ? (
            <DetailList
              rows={[
                [t('crm.fields.contactName'), l.contactName],
                [t('crm.fields.companyName'), l.companyName],
                [
                  t('crm.fields.phone'),
                  l.phone ? (
                    <a className="text-accent" href={`tel:${l.phone}`}>
                      {l.phone}
                    </a>
                  ) : null,
                ],
                [t('crm.fields.telegram'), l.telegram],
                [t('crm.fields.whatsapp'), l.whatsapp],
                [t('crm.fields.instagram'), l.instagram],
                [t('crm.fields.email'), l.email],
                [t('crm.fields.website'), l.website],
                [t('crm.fields.city'), [l.city, l.country].filter(Boolean).join(', ')],
                [t('crm.fields.source'), l.source.name],
                [t('crm.fields.service'), l.service?.name],
                [
                  t('crm.fields.budget'),
                  l.budget
                    ? `${money(l.budget, l.currency)}${l.currency === 'USD' ? ` ≈ ${money(l.budgetUzs, 'UZS')}` : ''}`
                    : null,
                ],
                [t('crm.fields.desiredDate'), l.desiredDate ? date(l.desiredDate) : null],
                [
                  t('crm.fields.companySize'),
                  l.companySize ? t(`crm.companySize.${l.companySize}`) : null,
                ],
                [t('crm.fields.interest'), l.interest],
                [t('crm.fields.team'), l.team?.name],
                [t('crm.fields.nextContactAt'), l.nextContactAt ? dateTime(l.nextContactAt) : null],
                [t('crm.fields.lastContactAt'), l.lastContactAt ? dateTime(l.lastContactAt) : null],
                [t('crm.fields.createdAt'), dateTime(l.createdAt)],
                [
                  t('crm.fields.comment'),
                  l.comment ? <span className="whitespace-pre-wrap">{l.comment}</span> : null,
                ],
              ]}
            />
          ) : tab === 'activity' ? (
            <TimelinePanel target={{ leadId: id }} />
          ) : tab === 'meetings' ? (
            <MeetingsPanel target={{ leadId: id }} canCreate={open} />
          ) : tab === 'comments' ? (
            <CommentsPanel target={{ leadId: id }} />
          ) : (
            <HistoryPanel target={{ leadId: id }} />
          )}
        </CardContent>
      </Card>

      {open && can('meeting.create') && tab === 'overview' ? (
        <p className="mt-3 text-sm text-muted-foreground">
          <button
            type="button"
            className="inline-flex items-center gap-1 text-accent hover:underline"
            onClick={() => setTab('meetings')}
          >
            <CalendarClock className="size-4" /> {t('crm.common.newMeeting')}
          </button>
        </p>
      ) : null}

      <LeadFormDialog
        open={dialog === 'edit'}
        onOpenChange={(o) => setDialog(o ? 'edit' : null)}
        lead={l}
      />
      <CloseDialog
        open={dialog === 'close'}
        onOpenChange={(o) => setDialog(o ? 'close' : null)}
        path={`/leads/${id}`}
      />
      <AssignDialog
        open={dialog === 'assign'}
        onOpenChange={(o) => setDialog(o ? 'assign' : null)}
        path={`/leads/${id}`}
        currentOwnerId={l.owner.id}
      />
      <ConvertDialog
        lead={l}
        open={dialog === 'convert'}
        onOpenChange={(o) => setDialog(o ? 'convert' : null)}
      />
    </>
  );
}
