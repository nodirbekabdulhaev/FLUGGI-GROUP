'use client';

import {
  DndContext,
  PointerSensor,
  TouchSensor,
  useDraggable,
  useDroppable,
  useSensor,
  useSensors,
  type DragEndEvent,
} from '@dnd-kit/core';
import type { PipelineCard, PipelineColumn, PipelineDto } from '@fluggi/contracts';
import { useQueryClient } from '@tanstack/react-query';
import { CalendarClock } from 'lucide-react';
import { useTranslations } from 'next-intl';
import Link from 'next/link';
import { useState } from 'react';
import { toast } from 'sonner';
import { ScoreBadge } from '@/components/crm/badges';
import { PageHeader } from '@/components/shared/page-header';
import { ErrorState } from '@/components/shared/states';
import { Card } from '@/components/ui/card';
import { NativeSelect } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { crmKeys, useInvalidateCrm, usePipeline, useReferences } from '@/features/crm/api';
import { OwnerSelect } from '@/features/crm/owner-select';
import { api, errorMessage } from '@/lib/api-client';
import { dateTime, moneyShort } from '@/lib/format';
import { useCan } from '@/lib/me-context';
import { cn } from '@/lib/utils';

function Card_({ card, dragging }: { card: PipelineCard; dragging?: boolean }) {
  const href = card.kind === 'lead' ? `/sales/leads/${card.id}` : `/sales/deals/${card.id}`;
  return (
    <div
      className={cn(
        'rounded-md border bg-surface p-3 text-sm shadow-sm',
        dragging && 'rotate-1 shadow-lg ring-2 ring-accent/40',
      )}
    >
      <div className="mb-1 flex items-start justify-between gap-2">
        <Link
          href={href}
          className="font-medium leading-snug hover:underline"
          onPointerDown={(e) => e.stopPropagation()}
        >
          {card.title}
        </Link>
        {card.scoreLevel ? <ScoreBadge level={card.scoreLevel} /> : null}
      </div>
      {card.subtitle ? (
        <p className="truncate text-xs text-muted-foreground">{card.subtitle}</p>
      ) : null}
      <div className="mt-2 flex items-center justify-between gap-2 text-xs">
        <span className="font-medium">
          {card.amount ? moneyShort(card.amount, card.currency) : '—'}
        </span>
        <span className="truncate text-muted-foreground">{card.owner.name.split(' ')[0]}</span>
      </div>
      {card.nextContactAt ? (
        <p className="mt-1 flex items-center gap-1 text-xs text-muted-foreground">
          <CalendarClock className="size-3" /> {dateTime(card.nextContactAt)}
        </p>
      ) : null}
    </div>
  );
}

function DraggableCard({
  card,
  column,
  disabled,
}: {
  card: PipelineCard;
  column: PipelineColumn;
  disabled: boolean;
}) {
  const { attributes, listeners, setNodeRef, transform, isDragging } = useDraggable({
    id: `${card.kind}:${card.id}`,
    data: { card, from: column.stage },
    disabled,
  });
  const style = transform
    ? { transform: `translate3d(${transform.x}px, ${transform.y}px, 0)`, zIndex: 50 }
    : undefined;
  return (
    <div
      ref={setNodeRef}
      style={style}
      {...listeners}
      {...attributes}
      className={cn(!disabled && 'cursor-grab touch-none active:cursor-grabbing')}
    >
      <Card_ card={card} dragging={isDragging} />
    </div>
  );
}

function Column({
  column,
  canMove,
}: {
  column: PipelineColumn;
  canMove: (c: PipelineCard) => boolean;
}) {
  const t = useTranslations('pipeline');
  const { setNodeRef, isOver } = useDroppable({
    id: column.stage.code,
    data: { stage: column.stage },
  });
  return (
    <div className="flex w-72 shrink-0 flex-col rounded-lg bg-muted/60">
      <div className="border-b border-border/60 p-3">
        <div className="flex items-center gap-2">
          <span
            className="size-2 rounded-full"
            style={{ backgroundColor: column.stage.color }}
            aria-hidden
          />
          <h3 className="flex-1 truncate text-sm font-semibold">{column.stage.name}</h3>
          <span className="rounded-full bg-surface px-2 text-xs">{column.count}</span>
        </div>
        <p className="mt-1 text-xs text-muted-foreground">
          {moneyShort(column.totalUzs)} · {column.stage.probability}%
        </p>
      </div>
      <div
        ref={setNodeRef}
        className={cn(
          'grid min-h-24 flex-1 content-start gap-2 p-2 transition-colors',
          isOver && 'bg-accent-soft',
        )}
      >
        {column.items.length === 0 ? (
          <p className="py-6 text-center text-xs text-muted-foreground">{t('empty')}</p>
        ) : null}
        {column.items.map((card) => (
          <DraggableCard key={card.id} card={card} column={column} disabled={!canMove(card)} />
        ))}
        {column.count > column.items.length ? (
          <p className="text-center text-xs text-muted-foreground">
            {t('more', { count: column.count - column.items.length })}
          </p>
        ) : null}
      </div>
    </div>
  );
}

export function PipelineBoard() {
  const t = useTranslations();
  const can = useCan();
  const qc = useQueryClient();
  const invalidate = useInvalidateCrm();
  const refs = useReferences();
  const [ownerId, setOwnerId] = useState('');
  const [serviceId, setServiceId] = useState('');
  const query = { ownerId: ownerId || undefined, serviceId: serviceId || undefined };
  const pipeline = usePipeline(query);
  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
    useSensor(TouchSensor, { activationConstraint: { delay: 200, tolerance: 6 } }),
  );

  const canMove = (c: PipelineCard) =>
    c.kind === 'lead' ? can('lead.update') : can('deal.change_stage');

  async function onDragEnd(e: DragEndEvent) {
    const card = e.active.data.current?.card as PipelineCard | undefined;
    const from = e.active.data.current?.from as { code: string; entity: string } | undefined;
    const to = e.over?.data.current?.stage as
      { code: string; entity: string; name: string } | undefined;
    if (!card || !from || !to || from.code === to.code) return;
    if (card.kind === 'lead' && to.entity === 'DEAL') return toast.info(t('pipeline.convertFirst'));
    if (card.kind === 'deal' && to.entity === 'LEAD') return toast.error(t('pipeline.wrongBoard'));

    // Оптимистично переносим карточку, при ошибке — откат.
    const key = crmKeys.pipeline(query);
    const prev = qc.getQueryData<PipelineDto>(key);
    if (prev) {
      qc.setQueryData<PipelineDto>(key, {
        ...prev,
        columns: prev.columns.map((col) =>
          col.stage.code === from.code
            ? { ...col, items: col.items.filter((i) => i.id !== card.id), count: col.count - 1 }
            : col.stage.code === to.code
              ? { ...col, items: [card, ...col.items], count: col.count + 1 }
              : col,
        ),
      });
    }
    try {
      await api(`/${card.kind === 'lead' ? 'leads' : 'deals'}/${card.id}/stage`, {
        method: 'POST',
        body: { stageCode: to.code },
      });
      toast.success(`${card.title} → ${to.name}`);
    } catch (err) {
      if (prev) qc.setQueryData(key, prev);
      toast.error(errorMessage(err));
    } finally {
      void invalidate();
    }
  }

  const data = pipeline.data;
  const leadCols = data?.columns.filter((c) => c.stage.entity === 'LEAD') ?? [];
  const dealCols = data?.columns.filter((c) => c.stage.entity === 'DEAL') ?? [];

  return (
    <>
      <PageHeader
        title={t('pipeline.title')}
        description={t('pipeline.subtitle')}
        actions={
          <div className="grid w-full grid-cols-2 gap-2 sm:flex">
            <NativeSelect
              aria-label={t('crm.fields.service')}
              value={serviceId}
              onChange={(e) => setServiceId(e.target.value)}
              className="h-9 sm:w-44"
            >
              <option value="">
                {t('crm.fields.service')}: {t('crm.common.all')}
              </option>
              {refs.data?.services.map((s) => (
                <option key={s.id} value={s.id}>
                  {s.name}
                </option>
              ))}
            </NativeSelect>
            {can('deal.read', 'TEAM') && can('employee.read') ? (
              <OwnerSelect
                filter
                aria-label={t('crm.fields.owner')}
                value={ownerId}
                onChange={(e) => setOwnerId(e.target.value)}
                emptyLabel={`${t('crm.fields.owner')}: ${t('crm.common.all')}`}
                className="h-9 sm:w-48"
              />
            ) : null}
          </div>
        }
      />
      {data ? (
        <div className="mb-4 grid grid-cols-2 gap-3 sm:max-w-md">
          <Card className="p-4">
            <p className="text-xs text-muted-foreground">{t('pipeline.total')}</p>
            <p className="text-lg font-semibold">{moneyShort(data.pipelineUzs)}</p>
          </Card>
          <Card className="p-4">
            <p className="text-xs text-muted-foreground">{t('pipeline.weighted')}</p>
            <p className="text-lg font-semibold">{moneyShort(data.weightedUzs)}</p>
          </Card>
        </div>
      ) : null}
      {pipeline.isPending ? (
        <div className="flex gap-3 overflow-hidden">
          {Array.from({ length: 4 }, (_, i) => (
            <Skeleton key={i} className="h-96 w-72 shrink-0" />
          ))}
        </div>
      ) : pipeline.isError ? (
        <Card>
          <ErrorState error={pipeline.error} onRetry={() => pipeline.refetch()} />
        </Card>
      ) : (
        <DndContext sensors={sensors} onDragEnd={onDragEnd}>
          <div className="-mx-4 overflow-x-auto px-4 pb-4 sm:-mx-6 sm:px-6">
            <div className="flex gap-3">
              {leadCols.length ? (
                <div>
                  <p className="mb-2 text-xs font-medium uppercase tracking-wide text-muted-foreground">
                    {t('pipeline.leadsPart')}
                  </p>
                  <div className="flex gap-3">
                    {leadCols.map((c) => (
                      <Column key={c.stage.code} column={c} canMove={canMove} />
                    ))}
                  </div>
                </div>
              ) : null}
              {dealCols.length ? (
                <div>
                  <p className="mb-2 text-xs font-medium uppercase tracking-wide text-muted-foreground">
                    {t('pipeline.dealsPart')}
                  </p>
                  <div className="flex gap-3">
                    {dealCols.map((c) => (
                      <Column key={c.stage.code} column={c} canMove={canMove} />
                    ))}
                  </div>
                </div>
              ) : null}
            </div>
          </div>
        </DndContext>
      )}
    </>
  );
}
