'use client';

import {
  COMMISSION_STATUSES,
  CONTRACT_STATUSES,
  PAYMENT_STATUSES,
  PROPOSAL_STATUSES,
} from '@fluggi/contracts';
import { useTranslations } from 'next-intl';
import Link from 'next/link';
import { useState } from 'react';
import { PageHeader } from '@/components/shared/page-header';
import { Pagination } from '@/components/shared/pagination';
import { EmptyState, ErrorState, TableSkeleton } from '@/components/shared/states';
import { Card } from '@/components/ui/card';
import { Input, NativeSelect } from '@/components/ui/input';
import { TBody, TD, TH, THead, TR, Table } from '@/components/ui/table';
import { useCrmMutation } from '@/features/crm/api';
import { useCommissions, useContracts, usePayments, useProposals } from '@/features/crm/sales-api';
import { api, errorMessage } from '@/lib/api-client';
import { useCan } from '@/lib/me-context';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { date, dateTime, money } from '@/lib/format';
import { SalesBadge } from './status';

function useStatusFilter<T extends string>() {
  const [status, setStatus] = useState<T | ''>('');
  const [page, setPage] = useState(1);
  return { status, setStatus: (s: T | '') => (setStatus(s), setPage(1)), page, setPage };
}

/** Мобильный список: карточки вместо таблицы. */
function MobileList({
  rows,
}: {
  rows: { id: string; href: string; title: string; sub: string; right: React.ReactNode }[];
}) {
  return (
    <ul className="divide-y md:hidden">
      {rows.map((r) => (
        <li key={r.id}>
          <Link href={r.href} className="flex items-start gap-3 p-4 active:bg-muted/50">
            <div className="min-w-0 flex-1">
              <p className="font-medium">{r.title}</p>
              <p className="text-sm text-muted-foreground">{r.sub}</p>
            </div>
            {r.right}
          </Link>
        </li>
      ))}
    </ul>
  );
}

export function ProposalsPage() {
  const t = useTranslations('sales');
  const f = useStatusFilter<(typeof PROPOSAL_STATUSES)[number]>();
  const list = useProposals({ status: f.status || undefined, page: f.page, pageSize: 25 });
  return (
    <>
      <PageHeader title={t('proposals.title')} description={t('proposals.subtitle')} />
      <Card>
        <div className="border-b p-4">
          <NativeSelect
            aria-label="Статус"
            className="sm:w-56"
            value={f.status}
            onChange={(e) => f.setStatus(e.target.value as never)}
          >
            <option value="">Все статусы</option>
            {PROPOSAL_STATUSES.map((s) => (
              <option key={s} value={s}>
                {t(`proposalStatus.${s}`)}
              </option>
            ))}
          </NativeSelect>
        </div>
        {list.isPending ? (
          <TableSkeleton />
        ) : list.isError ? (
          <ErrorState error={list.error} onRetry={() => list.refetch()} />
        ) : list.data.items.length === 0 ? (
          <EmptyState title={t('proposals.empty')} text={t('proposals.emptyText')} />
        ) : (
          <>
            <div className="hidden md:block">
              <Table>
                <THead>
                  <tr>
                    <TH>КП</TH>
                    <TH>Клиент / сделка</TH>
                    <TH>Статус</TH>
                    <TH className="text-right">Итого</TH>
                    <TH>Менеджер</TH>
                    <TH>Обновлено</TH>
                  </tr>
                </THead>
                <TBody>
                  {list.data.items.map((p) => (
                    <TR key={p.id}>
                      <TD>
                        <Link
                          href={`/sales/deals/${p.deal.id}`}
                          className="font-medium hover:underline"
                        >
                          {p.number} · {p.title}
                        </Link>
                        <span className="block text-xs text-muted-foreground">
                          v{p.currentVersion}
                        </span>
                      </TD>
                      <TD>
                        {p.client.name}
                        <span className="block text-xs text-muted-foreground">{p.deal.number}</span>
                      </TD>
                      <TD>
                        <SalesBadge kind="proposalStatus" status={p.status} />
                      </TD>
                      <TD className="whitespace-nowrap text-right font-medium">
                        {money(p.total, p.currency)}
                      </TD>
                      <TD className="text-muted-foreground">{p.manager.name}</TD>
                      <TD className="whitespace-nowrap text-muted-foreground">
                        {dateTime(p.updatedAt)}
                      </TD>
                    </TR>
                  ))}
                </TBody>
              </Table>
            </div>
            <MobileList
              rows={list.data.items.map((p) => ({
                id: p.id,
                href: `/sales/deals/${p.deal.id}`,
                title: `${p.number} · ${p.title}`,
                sub: `${p.client.name} · ${money(p.total, p.currency)}`,
                right: <SalesBadge kind="proposalStatus" status={p.status} />,
              }))}
            />
            <Pagination
              page={list.data.page}
              pageSize={list.data.pageSize}
              total={list.data.total}
              onPage={f.setPage}
            />
          </>
        )}
      </Card>
    </>
  );
}

export function ContractsPage() {
  const t = useTranslations('sales');
  const f = useStatusFilter<(typeof CONTRACT_STATUSES)[number]>();
  const list = useContracts({ status: f.status || undefined, page: f.page, pageSize: 25 });
  return (
    <>
      <PageHeader title={t('contracts.title')} description={t('contracts.subtitle')} />
      <Card>
        <div className="border-b p-4">
          <NativeSelect
            aria-label="Статус"
            className="sm:w-56"
            value={f.status}
            onChange={(e) => f.setStatus(e.target.value as never)}
          >
            <option value="">Все статусы</option>
            {CONTRACT_STATUSES.map((s) => (
              <option key={s} value={s}>
                {t(`contractStatus.${s}`)}
              </option>
            ))}
          </NativeSelect>
        </div>
        {list.isPending ? (
          <TableSkeleton />
        ) : list.isError ? (
          <ErrorState error={list.error} onRetry={() => list.refetch()} />
        ) : list.data.items.length === 0 ? (
          <EmptyState title={t('contracts.empty')} text={t('contracts.emptyText')} />
        ) : (
          <>
            <div className="hidden md:block">
              <Table>
                <THead>
                  <tr>
                    <TH>Договор</TH>
                    <TH>Клиент / сделка</TH>
                    <TH>Статус</TH>
                    <TH className="text-right">Сумма</TH>
                    <TH className="text-right">Оплачено</TH>
                    <TH>Подписан</TH>
                  </tr>
                </THead>
                <TBody>
                  {list.data.items.map((c) => (
                    <TR key={c.id}>
                      <TD>
                        <Link
                          href={`/sales/deals/${c.deal.id}`}
                          className="font-medium hover:underline"
                        >
                          {c.number}
                        </Link>
                        <span className="block text-xs text-muted-foreground">
                          от {date(c.contractDate)}
                        </span>
                      </TD>
                      <TD>
                        {c.client.name}
                        <span className="block text-xs text-muted-foreground">{c.deal.number}</span>
                      </TD>
                      <TD>
                        <SalesBadge kind="contractStatus" status={c.status} />
                      </TD>
                      <TD className="whitespace-nowrap text-right font-medium">
                        {money(c.amount, c.currency)}
                      </TD>
                      <TD className="whitespace-nowrap text-right">{money(c.paidUzs, 'UZS')}</TD>
                      <TD className="text-muted-foreground">{date(c.signedAt)}</TD>
                    </TR>
                  ))}
                </TBody>
              </Table>
            </div>
            <MobileList
              rows={list.data.items.map((c) => ({
                id: c.id,
                href: `/sales/deals/${c.deal.id}`,
                title: `${c.number} · ${c.client.name}`,
                sub: money(c.amount, c.currency),
                right: <SalesBadge kind="contractStatus" status={c.status} />,
              }))}
            />
            <Pagination
              page={list.data.page}
              pageSize={list.data.pageSize}
              total={list.data.total}
              onPage={f.setPage}
            />
          </>
        )}
      </Card>
    </>
  );
}

export function PaymentsPage() {
  const t = useTranslations('sales');
  const f = useStatusFilter<(typeof PAYMENT_STATUSES)[number]>();
  const list = usePayments({ status: f.status || undefined, page: f.page, pageSize: 25 });
  return (
    <>
      <PageHeader title={t('payments.title')} description={t('payments.subtitle')} />
      <Card>
        <div className="border-b p-4">
          <NativeSelect
            aria-label="Статус"
            className="sm:w-56"
            value={f.status}
            onChange={(e) => f.setStatus(e.target.value as never)}
          >
            <option value="">Все статусы</option>
            {PAYMENT_STATUSES.map((s) => (
              <option key={s} value={s}>
                {t(`paymentStatus.${s}`)}
              </option>
            ))}
          </NativeSelect>
        </div>
        {list.isPending ? (
          <TableSkeleton />
        ) : list.isError ? (
          <ErrorState error={list.error} onRetry={() => list.refetch()} />
        ) : list.data.items.length === 0 ? (
          <EmptyState title={t('payments.empty')} text={t('payments.emptyText')} />
        ) : (
          <>
            <div className="hidden md:block">
              <Table>
                <THead>
                  <tr>
                    <TH>Оплата</TH>
                    <TH>Клиент / сделка</TH>
                    <TH>Тип</TH>
                    <TH>Статус</TH>
                    <TH className="text-right">Сумма</TH>
                    <TH>Дата</TH>
                  </tr>
                </THead>
                <TBody>
                  {list.data.items.map((p) => (
                    <TR key={p.id}>
                      <TD>
                        <Link
                          href={`/sales/deals/${p.deal.id}`}
                          className="font-medium hover:underline"
                        >
                          {p.number}
                        </Link>
                        <span className="block text-xs text-muted-foreground">
                          {t(`paymentMethod.${p.method}`)}
                        </span>
                      </TD>
                      <TD>
                        {p.client.name}
                        <span className="block text-xs text-muted-foreground">
                          {p.deal.number}
                          {p.project ? ` · ${p.project.number}` : ''}
                        </span>
                      </TD>
                      <TD>{t(`paymentType.${p.type}`)}</TD>
                      <TD>
                        <SalesBadge kind="paymentStatus" status={p.status} />
                      </TD>
                      <TD
                        className={`whitespace-nowrap text-right font-medium ${p.type === 'REFUND' ? 'text-danger' : ''}`}
                      >
                        {p.type === 'REFUND' ? '− ' : ''}
                        {money(p.amount, p.currency)}
                      </TD>
                      <TD className="whitespace-nowrap text-muted-foreground">
                        {dateTime(p.paidAt ?? p.createdAt)}
                      </TD>
                    </TR>
                  ))}
                </TBody>
              </Table>
            </div>
            <MobileList
              rows={list.data.items.map((p) => ({
                id: p.id,
                href: `/sales/deals/${p.deal.id}`,
                title: `${p.number} · ${p.client.name}`,
                sub: `${t(`paymentType.${p.type}`)} · ${money(p.amount, p.currency)}`,
                right: <SalesBadge kind="paymentStatus" status={p.status} />,
              }))}
            />
            <Pagination
              page={list.data.page}
              pageSize={list.data.pageSize}
              total={list.data.total}
              onPage={f.setPage}
            />
          </>
        )}
      </Card>
    </>
  );
}

export function CommissionsPage() {
  const t = useTranslations('sales.commissions');
  const ts = useTranslations('sales');
  const can = useCan();
  const approver = can('commission.approve', 'ALL');
  const [period, setPeriod] = useState(
    new Date(Date.now() + 5 * 3600_000).toISOString().slice(0, 7),
  );
  const [status, setStatus] = useState<(typeof COMMISSION_STATUSES)[number] | ''>('');
  const [page, setPage] = useState(1);
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const list = useCommissions({
    period: period || undefined,
    status: status || undefined,
    page,
    pageSize: 25,
  });
  const act = useCrmMutation(({ to, ids }: { to: 'approve' | 'pay'; ids: string[] }) =>
    api<{ updated: number }>(`/commissions/${to}`, { method: 'POST', body: { ids } }),
  );
  const items = list.data?.items ?? [];
  const chosen = items.filter((c) => selected.has(c.id));
  const allAccrued = chosen.length > 0 && chosen.every((c) => c.status === 'ACCRUED');
  const allApproved = chosen.length > 0 && chosen.every((c) => c.status === 'APPROVED');
  const reset = () => (setSelected(new Set()), setPage(1));
  const run = async (to: 'approve' | 'pay') => {
    try {
      const r = await act.mutateAsync({ to, ids: chosen.map((c) => c.id) });
      toast.success(t(to === 'approve' ? 'approved' : 'paid', { count: r.updated }));
      setSelected(new Set());
    } catch (err) {
      toast.error(errorMessage(err));
    }
  };
  const toggle = (id: string) =>
    setSelected((s) => {
      const n = new Set(s);
      if (n.has(id)) n.delete(id);
      else n.add(id);
      return n;
    });
  return (
    <>
      <PageHeader title={t('title')} description={t('subtitle')} />
      <Card>
        <div className="flex flex-wrap items-end justify-between gap-3 border-b p-4">
          <div className="flex flex-wrap items-end gap-3">
            <label className="grid gap-1 text-sm">
              <span className="text-muted-foreground">{t('period')}</span>
              <Input
                type="month"
                value={period}
                onChange={(e) => (setPeriod(e.target.value), reset())}
                className="w-44"
              />
            </label>
            <NativeSelect
              aria-label="Статус"
              className="w-48"
              value={status}
              onChange={(e) => (setStatus(e.target.value as never), reset())}
            >
              <option value="">{t('allStatuses')}</option>
              {COMMISSION_STATUSES.map((x) => (
                <option key={x} value={x}>
                  {ts(`commissionStatus.${x}`)}
                </option>
              ))}
            </NativeSelect>
          </div>
          {list.data ? (
            <p className="text-right">
              <span className="block text-xs text-muted-foreground">{t('total')}</span>
              <span className="text-xl font-semibold">{money(list.data.totalUzs, 'UZS')}</span>
            </p>
          ) : null}
        </div>
        {approver && chosen.length > 0 ? (
          <div className="flex flex-wrap items-center gap-3 border-b bg-muted/40 px-4 py-2 text-sm">
            <span>
              {t('selected', {
                count: chosen.length,
                amount: money(
                  chosen.reduce((s, c) => s + Number(c.amountUzs), 0),
                  'UZS',
                ),
              })}
            </span>
            <Button
              size="sm"
              disabled={!allAccrued}
              loading={act.isPending}
              onClick={() => run('approve')}
            >
              {t('approve')}
            </Button>
            <Button
              size="sm"
              variant="outline"
              disabled={!allApproved}
              loading={act.isPending}
              onClick={() => run('pay')}
            >
              {t('pay')}
            </Button>
          </div>
        ) : null}
        {list.isPending ? (
          <TableSkeleton />
        ) : list.isError ? (
          <ErrorState error={list.error} onRetry={() => list.refetch()} />
        ) : items.length === 0 ? (
          <EmptyState title={t('empty')} text={t('emptyText')} />
        ) : (
          <>
            <div className="overflow-x-auto">
              <Table>
                <THead>
                  <tr>
                    {approver ? (
                      <TH className="w-10">
                        <input
                          type="checkbox"
                          aria-label="Выбрать все"
                          checked={chosen.length === items.length}
                          onChange={(e) =>
                            setSelected(
                              e.target.checked ? new Set(items.map((c) => c.id)) : new Set(),
                            )
                          }
                        />
                      </TH>
                    ) : null}
                    <TH>Сотрудник</TH>
                    <TH>Сделка / оплата</TH>
                    <TH>{t('rule')}</TH>
                    <TH className="text-right">{t('base')}</TH>
                    <TH className="text-right">{t('rate')}</TH>
                    <TH className="text-right">Сумма</TH>
                    <TH>Статус</TH>
                  </tr>
                </THead>
                <TBody>
                  {items.map((c) => (
                    <TR key={c.id}>
                      {approver ? (
                        <TD>
                          <input
                            type="checkbox"
                            aria-label={`Выбрать ${c.user.name} ${c.payment.number}`}
                            checked={selected.has(c.id)}
                            onChange={() => toggle(c.id)}
                          />
                        </TD>
                      ) : null}
                      <TD>
                        {c.user.name}
                        <span className="block text-xs text-muted-foreground">
                          {t(`role.${c.role}`)}
                        </span>
                      </TD>
                      <TD>
                        <Link href={`/sales/deals/${c.deal.id}`} className="hover:underline">
                          {c.deal.number}
                        </Link>
                        <span className="block text-xs text-muted-foreground">
                          {c.payment.number}
                        </span>
                      </TD>
                      <TD className="max-w-64 text-muted-foreground">{c.rule.name}</TD>
                      <TD className="whitespace-nowrap text-right">
                        {money(c.baseAmountUzs, 'UZS')}
                      </TD>
                      <TD className="text-right">{Number(c.rate)}%</TD>
                      <TD
                        className={`whitespace-nowrap text-right font-semibold ${Number(c.amountUzs) < 0 ? 'text-danger' : ''}`}
                      >
                        {money(c.amountUzs, 'UZS')}
                      </TD>
                      <TD>
                        <SalesBadge kind="commissionStatus" status={c.status} />
                        {c.approvedBy ? (
                          <span className="block text-xs text-muted-foreground">
                            {c.approvedBy.name}
                          </span>
                        ) : null}
                      </TD>
                    </TR>
                  ))}
                </TBody>
              </Table>
            </div>
            <Pagination
              page={list.data.page}
              pageSize={list.data.pageSize}
              total={list.data.total}
              onPage={(p) => (setPage(p), setSelected(new Set()))}
            />
          </>
        )}
      </Card>
      <p className="mt-3 text-xs text-muted-foreground">
        {t('rulesHint')}{' '}
        {can('commission_rule.manage', 'ALL') ? (
          <Link href="/settings/commission-rules" className="text-accent hover:underline">
            {t('rulesLink')}
          </Link>
        ) : null}
      </p>
    </>
  );
}
