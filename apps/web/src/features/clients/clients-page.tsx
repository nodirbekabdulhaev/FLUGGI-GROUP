'use client';

import { Plus, Search } from 'lucide-react';
import { useTranslations } from 'next-intl';
import Link from 'next/link';
import { useEffect, useState } from 'react';
import { PageHeader } from '@/components/shared/page-header';
import { Pagination } from '@/components/shared/pagination';
import { EmptyState, ErrorState, TableSkeleton } from '@/components/shared/states';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { TBody, TD, TH, THead, TR, Table } from '@/components/ui/table';
import { useClients } from '@/features/crm/api';
import { date } from '@/lib/format';
import { useCan } from '@/lib/me-context';
import { ClientFormDialog } from './client-form-dialog';

export function ClientsPage() {
  const t = useTranslations();
  const can = useCan();
  const [q, setQ] = useState('');
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [open, setOpen] = useState(false);
  useEffect(() => {
    const id = setTimeout(() => setSearch(q), 300);
    return () => clearTimeout(id);
  }, [q]);
  useEffect(() => setPage(1), [search]);
  const clients = useClients({ q: search || undefined, page, pageSize: 25 });
  const add = can('client.create') ? (
    <Button onClick={() => setOpen(true)}>
      <Plus /> {t('clients.add')}
    </Button>
  ) : null;

  return (
    <>
      <PageHeader title={t('clients.title')} description={t('clients.subtitle')} actions={add} />
      <Card>
        <div className="border-b p-4">
          <div className="relative">
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              value={q}
              onChange={(e) => setQ(e.target.value)}
              placeholder={t('clients.search')}
              aria-label={t('common.search')}
              className="pl-9"
            />
          </div>
        </div>
        {clients.isPending ? (
          <TableSkeleton />
        ) : clients.isError ? (
          <ErrorState error={clients.error} onRetry={() => clients.refetch()} />
        ) : clients.data.items.length === 0 ? (
          search ? (
            <EmptyState title={t('clients.emptyFiltered')} />
          ) : (
            <EmptyState
              title={t('clients.emptyTitle')}
              text={t('clients.emptyText')}
              action={add}
            />
          )
        ) : (
          <>
            <div className="hidden md:block">
              <Table>
                <THead>
                  <tr>
                    <TH>{t('crm.fields.client')}</TH>
                    <TH>{t('crm.fields.phone')}</TH>
                    <TH>{t('clients.deals')}</TH>
                    <TH>{t('crm.fields.owner')}</TH>
                    <TH>{t('crm.fields.createdAt')}</TH>
                  </tr>
                </THead>
                <TBody>
                  {clients.data.items.map((c) => (
                    <TR key={c.id}>
                      <TD>
                        <Link href={`/clients/${c.id}`} className="block">
                          <span className="font-medium hover:underline">{c.name}</span>
                          <span className="block text-xs text-muted-foreground">
                            {c.number} · {t(`crm.clientType.${c.type}`)}
                            {c.city ? ` · ${c.city}` : ''}
                          </span>
                        </Link>
                      </TD>
                      <TD className="text-muted-foreground">{c.phone ?? '—'}</TD>
                      <TD>
                        {t('clients.dealsCount', { count: c.dealsCount, open: c.openDealsCount })}
                      </TD>
                      <TD className="text-muted-foreground">{c.owner.name}</TD>
                      <TD className="text-muted-foreground">{date(c.createdAt)}</TD>
                    </TR>
                  ))}
                </TBody>
              </Table>
            </div>
            <ul className="divide-y md:hidden">
              {clients.data.items.map((c) => (
                <li key={c.id}>
                  <Link href={`/clients/${c.id}`} className="grid gap-1 p-4 active:bg-muted/50">
                    <p className="font-medium">{c.name}</p>
                    <p className="text-sm text-muted-foreground">
                      {t('clients.dealsCount', { count: c.dealsCount, open: c.openDealsCount })} ·{' '}
                      {c.owner.name}
                    </p>
                  </Link>
                </li>
              ))}
            </ul>
            <Pagination
              page={clients.data.page}
              pageSize={clients.data.pageSize}
              total={clients.data.total}
              onPage={setPage}
            />
          </>
        )}
      </Card>
      <ClientFormDialog open={open} onOpenChange={setOpen} />
    </>
  );
}
