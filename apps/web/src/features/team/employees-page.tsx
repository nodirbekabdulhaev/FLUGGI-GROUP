'use client';

import {
  ROLE_CODES,
  USER_STATUSES,
  type RoleCode,
  type UserDto,
  type UserStatus,
} from '@fluggi/contracts';
import {
  Coins,
  KeyRound,
  Lock,
  MoreHorizontal,
  Pencil,
  Plus,
  Search,
  Send,
  Unlock,
} from 'lucide-react';
import { useFormatter, useNow, useTranslations } from 'next-intl';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { PageHeader } from '@/components/shared/page-header';
import { Pagination } from '@/components/shared/pagination';
import { EmptyState, ErrorState, TableSkeleton } from '@/components/shared/states';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input, NativeSelect } from '@/components/ui/input';
import { TBody, TD, TH, THead, TR, Table } from '@/components/ui/table';
import { errorMessage } from '@/lib/api-client';
import { useCan, useMe } from '@/lib/me-context';
import { initials } from '@/lib/utils';
import { useResetPassword, useSetBlocked, useTeams, useUsers } from './api';
import { EmployeeFormDialog } from './employee-form-dialog';
import { RatesDialog } from './rates-dialog';
import { TempPasswordDialog } from './temp-password-dialog';

const TITLE_KEY: Record<string, string> = {
  MANAGER: 'titleManagers',
  ROP: 'titleRops',
  EXECUTOR: 'titleExecutors',
};

const PAGE_SIZE = 25;

function useDebounced<T>(value: T, ms = 300) {
  const [v, setV] = useState(value);
  useEffect(() => {
    const id = setTimeout(() => setV(value), ms);
    return () => clearTimeout(id);
  }, [value, ms]);
  return v;
}

export function EmployeesPage({
  fixedRole,
  initialQuery,
}: {
  fixedRole?: RoleCode;
  initialQuery?: string;
}) {
  const t = useTranslations();
  const format = useFormatter();
  // Явное «сейчас» с обновлением раз в минуту — «5 минут назад» не устаревает.
  const now = useNow({ updateInterval: 60_000 });
  const can = useCan();
  const me = useMe();
  const canManage = can('employee.manage', 'ALL');
  const canRates = can('payroll.manage', 'ALL');

  const [q, setQ] = useState(initialQuery ?? '');
  const [role, setRole] = useState<RoleCode | ''>(fixedRole ?? '');
  const [teamId, setTeamId] = useState('');
  const [status, setStatus] = useState<UserStatus | ''>('');
  const [page, setPage] = useState(1);
  const search = useDebounced(q);

  useEffect(() => setPage(1), [search, role, teamId, status]);

  const users = useUsers({
    q: search || undefined,
    roleCode: role || undefined,
    teamId: teamId || undefined,
    status: status || undefined,
    page,
    pageSize: PAGE_SIZE,
  });
  const teams = useTeams();
  const setBlocked = useSetBlocked();
  const resetPassword = useResetPassword();

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<UserDto | null>(null);
  const [tempPassword, setTempPassword] = useState<{ name: string; password: string } | null>(null);
  const [ratesFor, setRatesFor] = useState<UserDto | null>(null);

  const filtered = Boolean(search || (!fixedRole && role) || teamId || status);
  const title = t(`employees.${fixedRole ? TITLE_KEY[fixedRole] : 'title'}`);

  async function toggleBlock(user: UserDto) {
    const blocking = user.status === 'ACTIVE';
    if (blocking && !window.confirm(t('employees.blockConfirm', { name: user.fullName }))) return;
    try {
      await setBlocked.mutateAsync({ id: user.id, blocked: blocking });
      toast.success(t(blocking ? 'employees.blocked' : 'employees.unblocked'));
    } catch (err) {
      toast.error(errorMessage(err));
    }
  }

  async function doReset(user: UserDto) {
    if (!window.confirm(t('employees.resetConfirm', { name: user.fullName }))) return;
    try {
      const res = await resetPassword.mutateAsync(user.id);
      setTempPassword({ name: user.fullName, password: res.temporaryPassword });
    } catch (err) {
      toast.error(errorMessage(err));
    }
  }

  const rowActions = (user: UserDto) => {
    const self = user.id === me.id;
    if (!canManage && !canRates) return null;
    if (user.role.code === 'CEO' && me.role.code !== 'CEO') return null;
    return (
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button variant="ghost" size="icon-sm" aria-label={t('common.actions')}>
            <MoreHorizontal />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent>
          {canManage ? (
            <DropdownMenuItem onSelect={() => (setEditing(user), setFormOpen(true))}>
              <Pencil /> {t('common.edit')}
            </DropdownMenuItem>
          ) : null}
          {canRates ? (
            <DropdownMenuItem onSelect={() => setRatesFor(user)}>
              <Coins /> {t('employees.rates')}
            </DropdownMenuItem>
          ) : null}
          {!self && canManage ? (
            <>
              <DropdownMenuItem onSelect={() => doReset(user)}>
                <KeyRound /> {t('employees.resetPassword')}
              </DropdownMenuItem>
              <DropdownMenuItem
                onSelect={() => toggleBlock(user)}
                destructive={user.status === 'ACTIVE'}
              >
                {user.status === 'ACTIVE' ? <Lock /> : <Unlock />}
                {t(user.status === 'ACTIVE' ? 'employees.block' : 'employees.unblock')}
              </DropdownMenuItem>
            </>
          ) : null}
        </DropdownMenuContent>
      </DropdownMenu>
    );
  };

  const statusBadge = (user: UserDto) => (
    <Badge tone={user.status === 'ACTIVE' ? 'success' : 'danger'}>
      {t(`userStatus.${user.status}`)}
    </Badge>
  );

  /** Специализация или должность — только если она уточняет роль. */
  const subtitleOf = (user: UserDto) => {
    const sub = user.specialty ? t(`specialties.${user.specialty}`) : user.position;
    return sub && sub !== t(`roles.${user.role.code}`) ? sub : null;
  };

  const data = users.data;
  return (
    <>
      <PageHeader
        title={title}
        description={t('employees.subtitle')}
        actions={
          canManage ? (
            <Button onClick={() => (setEditing(null), setFormOpen(true))}>
              <Plus /> {t('employees.add')}
            </Button>
          ) : null
        }
      />

      <Card>
        <div className="flex flex-col gap-3 border-b p-4 md:flex-row md:items-center">
          <div className="relative flex-1">
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              value={q}
              onChange={(e) => setQ(e.target.value)}
              placeholder={t('employees.searchPlaceholder')}
              aria-label={t('common.search')}
              className="pl-9"
            />
          </div>
          <div className="grid grid-cols-2 gap-3 sm:flex">
            {!fixedRole ? (
              <NativeSelect
                aria-label={t('employees.filterRole')}
                value={role}
                onChange={(e) => setRole(e.target.value as RoleCode | '')}
                className="sm:w-40"
              >
                <option value="">
                  {t('employees.filterRole')}: {t('common.all')}
                </option>
                {ROLE_CODES.map((r) => (
                  <option key={r} value={r}>
                    {t(`roles.${r}`)}
                  </option>
                ))}
              </NativeSelect>
            ) : null}
            {fixedRole !== 'EXECUTOR' ? (
              <NativeSelect
                aria-label={t('employees.filterTeam')}
                value={teamId}
                onChange={(e) => setTeamId(e.target.value)}
                className="sm:w-44"
              >
                <option value="">
                  {t('employees.filterTeam')}: {t('common.all')}
                </option>
                {teams.data?.map((team) => (
                  <option key={team.id} value={team.id}>
                    {team.name}
                  </option>
                ))}
              </NativeSelect>
            ) : null}
            <NativeSelect
              aria-label={t('employees.filterStatus')}
              value={status}
              onChange={(e) => setStatus(e.target.value as UserStatus | '')}
              className="sm:w-40"
            >
              <option value="">
                {t('employees.filterStatus')}: {t('common.all')}
              </option>
              {USER_STATUSES.map((s) => (
                <option key={s} value={s}>
                  {t(`userStatus.${s}`)}
                </option>
              ))}
            </NativeSelect>
          </div>
        </div>

        {users.isPending ? (
          <TableSkeleton />
        ) : users.isError ? (
          <ErrorState error={users.error} onRetry={() => users.refetch()} />
        ) : data && data.items.length === 0 ? (
          filtered ? (
            <EmptyState title={t('employees.emptyFiltered')} />
          ) : (
            <EmptyState
              title={t('employees.emptyTitle')}
              text={t('employees.emptyText')}
              action={
                canManage ? (
                  <Button onClick={() => (setEditing(null), setFormOpen(true))}>
                    <Plus /> {t('employees.add')}
                  </Button>
                ) : null
              }
            />
          )
        ) : data ? (
          <>
            {/* Desktop: таблица */}
            <div className="hidden md:block">
              <Table>
                <THead>
                  <tr>
                    <TH>{t('employees.colName')}</TH>
                    <TH>{t('employees.colRole')}</TH>
                    <TH>{t('employees.colTeam')}</TH>
                    <TH>{t('employees.colStatus')}</TH>
                    <TH>{t('employees.colLastLogin')}</TH>
                    <TH className="w-12" />
                  </tr>
                </THead>
                <TBody>
                  {data.items.map((user) => (
                    <TR key={user.id}>
                      <TD>
                        <div className="flex items-center gap-3">
                          <span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-semibold">
                            {initials(user.fullName)}
                          </span>
                          <div className="min-w-0">
                            <p className="flex items-center gap-1.5 truncate font-medium">
                              {user.fullName}
                              {user.telegramLinked ? (
                                <Send
                                  className="size-3 text-accent"
                                  aria-label={t('employees.telegram')}
                                />
                              ) : null}
                            </p>
                            <p className="truncate text-xs text-muted-foreground">{user.email}</p>
                          </div>
                        </div>
                      </TD>
                      <TD>
                        <p>{t(`roles.${user.role.code}`)}</p>
                        {subtitleOf(user) ? (
                          <p className="text-xs text-muted-foreground">{subtitleOf(user)}</p>
                        ) : null}
                      </TD>
                      <TD className="text-muted-foreground">
                        {user.team?.name ?? t('common.notSet')}
                      </TD>
                      <TD>{statusBadge(user)}</TD>
                      <TD className="whitespace-nowrap text-muted-foreground">
                        {user.lastLoginAt
                          ? format.relativeTime(new Date(user.lastLoginAt), now)
                          : t('common.never')}
                      </TD>
                      <TD className="text-right">{rowActions(user)}</TD>
                    </TR>
                  ))}
                </TBody>
              </Table>
            </div>

            {/* Mobile: карточки */}
            <ul className="divide-y md:hidden">
              {data.items.map((user) => (
                <li key={user.id} className="flex items-start gap-3 p-4">
                  <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-muted text-sm font-semibold">
                    {initials(user.fullName)}
                  </span>
                  <div className="min-w-0 flex-1">
                    <p className="truncate font-medium">{user.fullName}</p>
                    <p className="truncate text-sm text-muted-foreground">
                      {t(`roles.${user.role.code}`)}
                      {user.team ? ` · ${user.team.name}` : ''}
                    </p>
                    <div className="mt-2">{statusBadge(user)}</div>
                  </div>
                  {rowActions(user)}
                </li>
              ))}
            </ul>

            <Pagination
              page={data.page}
              pageSize={data.pageSize}
              total={data.total}
              onPage={setPage}
            />
          </>
        ) : null}
      </Card>

      <EmployeeFormDialog
        open={formOpen}
        onOpenChange={setFormOpen}
        user={editing}
        defaultRole={fixedRole}
        onCreated={(name, password) => password && setTempPassword({ name, password })}
      />
      <TempPasswordDialog value={tempPassword} onClose={() => setTempPassword(null)} />
      <RatesDialog user={ratesFor} editable={canRates} onClose={() => setRatesFor(null)} />
    </>
  );
}
