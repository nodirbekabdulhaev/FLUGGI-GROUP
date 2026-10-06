import { Injectable } from '@nestjs/common';
import type { PermissionCode } from '@fluggi/contracts';
import type { Prisma } from '@fluggi/db';
import type { AuthContext } from '../../core/auth/auth-context';
import { notFound } from '../../core/http/app.exception';
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
  async assignableOwner(auth: AuthContext, ownerId: string | undefined, code: PermissionCode) {
    const targetId = ownerId ?? auth.userId;
    const scope = auth.permissions[code];
    const user = await this.prisma.user.findFirst({
      where: { id: targetId, deletedAt: null, status: 'ACTIVE' },
      include: { role: true },
    });
    const allowedRole = user && ['MANAGER', 'ROP', 'CEO'].includes(user.role.code);
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
}
