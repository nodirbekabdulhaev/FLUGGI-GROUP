'use client';

import { DEAL_STAGE_CODES, type DealStageCode } from '@fluggi/contracts';
import {
  ArrowLeft,
  MoreHorizontal,
  Pencil,
  RotateCcw,
  Trash2,
  UserRound,
  XCircle,
} from 'lucide-react';
import { useTranslations } from 'next-intl';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { toast } from 'sonner';
import { StageBadge, StatusBadge } from '@/components/crm/badges';
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
import { useCrmMutation, useDeal, useReferences } from '@/features/crm/api';
import { AssignDialog } from '@/features/crm/assign-dialog';
import { CloseDialog } from '@/features/crm/close-dialog';
import { CommentsPanel, HistoryPanel, MeetingsPanel, TimelinePanel } from '@/features/crm/panels';
import { api, errorMessage } from '@/lib/api-client';
import { date, dateTime, money } from '@/lib/format';
import { useCan } from '@/lib/me-context';
import { DealFormDialog } from './deal-form-dialog';

export function DealCard({ id }: { id: string }) {
  const t = useTranslations();
  const can = useCan();
  const router = useRouter();
  const deal = useDeal(id);
  const refs = useReferences();
  const [tab, setTab] = useState('overview');
  const [dialog, setDialog] = useState<'edit' | 'close' | 'assign' | null>(null);
  const stage = useCrmMutation((code: DealStageCode) =>
    api(`/deals/${id}/stage`, { method: 'POST', body: { stageCode: code } }),
  );
  const reopen = useCrmMutation(() => api(`/deals/${id}/reopen`, { method: 'POST' }));
  const remove = useCrmMutation(() => api(`/deals/${id}`, { method: 'DELETE' }));

  if (deal.isPending)
    return (
      <Card>
        <TableSkeleton rows={6} cols={2} />
      </Card>
    );
  if (deal.isError)
    return (
      <Card>
        <ErrorState error={deal.error} onRetry={() => deal.refetch()} />
      </Card>
    );
  const d = deal.data;
  const open = d.status === 'OPEN';
  const canStage = open && can('deal.change_stage');
  const stages = refs.data?.stages.filter((s) => s.entity === 'DEAL') ?? [];
  const idx = DEAL_STAGE_CODES.indexOf(d.stage.code as DealStageCode);
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
        href="/sales/deals"
        className="mb-4 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
      >
        <ArrowLeft className="size-4" /> {t('deals.title')}
      </Link>
      <div className="mb-6 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div className="min-w-0">
          <p className="text-xs text-muted-foreground">
            {d.number}
            {d.isRepeat ? ` · ${t('deals.repeat')}` : ''}
          </p>
          <h1 className="text-xl font-semibold tracking-tight sm:text-2xl">{d.title}</h1>
          <div className="mt-2 flex flex-wrap items-center gap-2 text-sm">
            {open ? <StageBadge stage={d.stage} /> : <StatusBadge status={d.status} kind="deal" />}
            <Link href={`/clients/${d.client.id}`} className="text-accent hover:underline">
              {d.client.name}
            </Link>
            <span className="text-muted-foreground">·</span>
            <span className="flex items-center gap-1 text-muted-foreground">
              <UserRound className="size-3.5" /> {d.owner.name}
            </span>
          </div>
          {d.lossReason ? (
            <p className="mt-2 text-sm text-danger">
              {d.lossReason.name}
              {d.lossComment ? ` — ${d.lossComment}` : ''}
            </p>
          ) : null}
        </div>
        <div className="flex items-start gap-3">
          <div className="text-right">
            <p className="text-2xl font-semibold">{money(d.amount, d.currency)}</p>
            <p className="text-xs text-muted-foreground">
              {d.currency === 'USD' ? `≈ ${money(d.amountUzs, 'UZS')} · ` : ''}
              {t('crm.fields.probability')} {d.probability}% · {t('deals.weighted')}{' '}
              {money((Number(d.amountUzs) * d.probability) / 100, 'UZS')}
            </p>
          </div>
          {can('deal.update') ? (
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
                ) : d.status !== 'WON' ? (
                  <DropdownMenuItem
                    onSelect={() => run(reopen.mutateAsync(undefined), t('crm.common.reopened'))}
                  >
                    <RotateCcw /> {t('crm.common.reopen')}
                  </DropdownMenuItem>
                ) : null}
                {can('deal.delete') && d.status !== 'WON' ? (
                  <>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                      destructive
                      onSelect={async () => {
                        if (!window.confirm(t('crm.common.deleteConfirm', { name: d.title })))
                          return;
                        await run(remove.mutateAsync(undefined), t('crm.common.deleted'));
                        router.push('/sales/deals');
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

      {canStage ? (
        <div className="mb-6">
          <div className="hidden gap-1 sm:flex">
            {stages.map((s, i) => (
              <button
                key={s.id}
                type="button"
                disabled={stage.isPending || s.code === d.stage.code || s.code === 'PAID'}
                title={
                  s.code === 'PAID' ? 'Ставится автоматически при подтверждении оплаты' : undefined
                }
                onClick={() =>
                  run(stage.mutateAsync(s.code as DealStageCode), t('crm.common.stageChanged'))
                }
                className="flex-1 rounded-md px-2 py-2 text-xs font-medium transition-colors hover:opacity-90 disabled:cursor-default"
                style={{
                  backgroundColor: i <= idx ? s.color : 'var(--color-muted)',
                  color: i <= idx ? '#fff' : 'var(--color-muted-foreground)',
                }}
              >
                {s.name}
              </button>
            ))}
          </div>
          <NativeSelect
            className="sm:hidden"
            aria-label={t('crm.common.changeStage')}
            value={d.stage.code}
            onChange={(e) =>
              run(stage.mutateAsync(e.target.value as DealStageCode), t('crm.common.stageChanged'))
            }
          >
            {stages
              .filter((s) => s.code !== 'PAID')
              .map((s) => (
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
          { key: 'proposals', label: t('leads.tabs.proposals'), plannedPhase: 3 },
          { key: 'contracts', label: t('leads.tabs.contracts'), plannedPhase: 3 },
          { key: 'payments', label: t('leads.tabs.payments'), plannedPhase: 3 },
          { key: 'projects', label: t('leads.tabs.projects'), plannedPhase: 4 },
          { key: 'tasks', label: t('leads.tabs.tasks'), plannedPhase: 4 },
          { key: 'files', label: t('leads.tabs.files'), plannedPhase: 3 },
        ]}
      />
      <Card>
        <CardContent className="pt-5">
          {tab === 'overview' ? (
            <DetailList
              rows={[
                [
                  t('crm.fields.client'),
                  <Link
                    key="c"
                    href={`/clients/${d.client.id}`}
                    className="text-accent hover:underline"
                  >
                    {d.client.name}
                  </Link>,
                ],
                [t('crm.fields.contact'), d.contact?.name],
                [t('crm.fields.service'), d.service?.name],
                [
                  t('crm.fields.amount'),
                  `${money(d.amount, d.currency)}${d.currency === 'USD' ? ` (курс ${d.exchangeRate})` : ''}`,
                ],
                [
                  t('crm.fields.probability'),
                  `${d.probability}%${d.probabilityOverride != null ? ' (вручную)' : ''}`,
                ],
                [t('crm.fields.expectedCloseDate'), date(d.expectedCloseDate)],
                [t('crm.fields.owner'), d.owner.name],
                [t('crm.fields.team'), d.team?.name],
                [
                  t('deals.fromLead'),
                  d.leadId ? (
                    <Link
                      key="l"
                      href={`/sales/leads/${d.leadId}`}
                      className="text-accent hover:underline"
                    >
                      Открыть лид
                    </Link>
                  ) : null,
                ],
                [t('crm.fields.createdAt'), dateTime(d.createdAt)],
              ]}
            />
          ) : tab === 'activity' ? (
            <TimelinePanel target={{ dealId: id }} />
          ) : tab === 'meetings' ? (
            <MeetingsPanel target={{ dealId: id }} canCreate={open} />
          ) : tab === 'comments' ? (
            <CommentsPanel target={{ dealId: id }} />
          ) : (
            <HistoryPanel target={{ dealId: id }} />
          )}
        </CardContent>
      </Card>
      <DealFormDialog
        open={dialog === 'edit'}
        onOpenChange={(o) => setDialog(o ? 'edit' : null)}
        deal={d}
      />
      <CloseDialog
        open={dialog === 'close'}
        onOpenChange={(o) => setDialog(o ? 'close' : null)}
        path={`/deals/${id}`}
      />
      <AssignDialog
        open={dialog === 'assign'}
        onOpenChange={(o) => setDialog(o ? 'assign' : null)}
        path={`/deals/${id}`}
        currentOwnerId={d.owner.id}
      />
    </>
  );
}
