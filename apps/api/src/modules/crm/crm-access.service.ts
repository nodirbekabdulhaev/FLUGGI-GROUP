import { Injectable } from '@nestjs/common';
import type { PermissionCode } from '@fluggi/contracts';
import type { Prisma } from '@fluggi/db';
import type { AuthContext } from '../../core/auth/auth-context';
import { businessRule, notFound } from '../../core/http/app.exception';
import { PrismaService, type Tx } from '../../core/prisma/prisma.service';
import { scopeWhere } from '../../core/rbac/scope';

/** Записи CRM принадлежат менеджеру (ownerId) и отделу (teamId). */
export const ownership = {
  own: (userId: string) => ({ ownerId: userId }),
  team: (teamIds: string[], userId: string) => ({
    OR: [{ teamId: { in: teamIds } }, { ownerId: userId }],
  }),
};

/**
 * Единая точка разграничения данных CRM (ТЗ §65): каждая выборка лидов, сделок,
 * клиентов и встреч проходит через эти фильтры.
 */
@Injectable()
export class CrmAccessService {
  constructor(private readonly prisma: PrismaService) {}

  leadWhere(auth: AuthContext, code: PermissionCode = 'lead.read'): Prisma.LeadWhereInput {
    return { deletedAt: null, ...scopeWhere<Prisma.LeadWhereInput>(auth, code, ownership) };
  }

  dealWhere(auth: AuthContext, code: PermissionCode = 'deal.read'): Prisma.DealWhereInput {
    return { deletedAt: null, ...scopeWhere<Prisma.DealWhereInput>(auth, code, ownership) };
  }

  clientWhere(auth: AuthContext, code: PermissionCode = 'client.read'): Prisma.ClientWhereInput {
    return { deletedAt: null, ...scopeWhere<Prisma.ClientWhereInput>(auth, code, ownership) };
  }

  meetingWhere(auth: AuthContext, code: PermissionCode = 'meeting.read'): Prisma.MeetingWhereInput {
    return scopeWhere<Prisma.MeetingWhereInput>(auth, code, {
      own: (userId) => ({ managerId: userId }),
      team: (teamIds, userId) => ({
        OR: [{ teamId: { in: teamIds } }, { managerId: userId }, { ropId: userId }],
      }),
    });
  }

  async lead(
    auth: AuthContext,
    id: string,
    code: PermissionCode = 'lead.read',
    tx: Tx = this.prisma,
  ) {
    const lead = await tx.lead.findFirst({ where: { AND: [this.leadWhere(auth, code), { id }] } });
    if (!lead) throw notFound('Лид');
    return lead;
  }

  async deal(
    auth: AuthContext,
    id: string,
    code: PermissionCode = 'deal.read',
    tx: Tx = this.prisma,
  ) {
    const deal = await tx.deal.findFirst({ where: { AND: [this.dealWhere(auth, code), { id }] } });
    if (!deal) throw notFound('Сделка');
    return deal;
  }

  async client(
    auth: AuthContext,
    id: string,
    code: PermissionCode = 'client.read',
    tx: Tx = this.prisma,
  ) {
    const client = await tx.client.findFirst({
      where: { AND: [this.clientWhere(auth, code), { id }] },
    });
    if (!client) throw notFound('Клиент');
    return client;
  }

  /**
   * Новый ответственный должен быть в зоне видимости назначающего:
   * менеджер — только себя, РОП — свой отдел, CEO — любого активного менеджера/РОП.
   */
  /**
   * Ответственный за лид, сделку, клиента — только менеджер или РОП (CEO получает уведомления).
   * Если ответственный не указан, а создаёт не менеджер/РОП (CEO, HR) — назначается менеджер
   * с наименьшим числом открытых лидов.
   */
  async assignableOwner(auth: AuthContext, ownerId: string | undefined, code: PermissionCode) {
    const targetId =
      ownerId ??
      (OWNER_ROLES.includes(auth.roleCode) ? auth.userId : await this.leastLoadedOwner());
    const scope = auth.permissions[code];
    const user = await this.prisma.user.findFirst({
      where: { id: targetId, deletedAt: null, status: 'ACTIVE' },
      include: { role: true },
    });
    if (user && !OWNER_ROLES.includes(user.role.code))
      throw businessRule('Ответственным может быть только менеджер или РОП', [
        { path: 'ownerId', message: 'Выберите менеджера или РОП' },
      ]);
    const allowedRole = Boolean(user);
    const inScope =
      scope === 'ALL' ||
      targetId === auth.userId ||
      (scope === 'TEAM' &&
        !!user?.teamId &&
        [...auth.headedTeamIds, auth.teamId].includes(user.teamId));
    if (!user || !allowedRole || !inScope) {
      throw notFound('Ответственный');
    }
    return user;
  }

  /** Менеджер с наименьшим числом открытых лидов; если менеджеров нет — РОП. */
  async leastLoadedOwner(teamId?: string | null): Promise<string> {
    for (const role of ['MANAGER', 'ROP'] as const) {
      const users = await this.prisma.user.findMany({
        where: {
          status: 'ACTIVE',
          deletedAt: null,
          role: { code: role },
          ...(teamId ? { teamId } : {}),
        },
        select: {
          id: true,
          _count: { select: { ownedLeads: { where: { status: 'OPEN', deletedAt: null } } } },
        },
      });
      if (users.length) {
        users.sort((a, b) => a._count.ownedLeads - b._count.ownedLeads || a.id.localeCompare(b.id));
        return users[0]!.id;
      }
    }
    if (teamId) return this.leastLoadedOwner(null);
    throw businessRule('Нет менеджеров и РОП — добавьте сотрудника, чтобы назначать лиды');
  }
}

const OWNER_ROLES: string[] = ['MANAGER', 'ROP'];
