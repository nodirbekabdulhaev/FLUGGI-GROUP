import { Injectable } from '@nestjs/common';
import {
  formatNumber,
  type CommissionDto,
  type CommissionListQuery,
  type CommissionRuleDto,
  type Paginated,
  type upsertCommissionRuleSchema,
} from '@fluggi/contracts';
import type { Prisma } from '@fluggi/db';
import type { z } from 'zod';
import type { AuthContext, RequestMeta } from '../../core/auth/auth-context';
import { AuditService } from '../../core/audit/audit.service';
import { businessRule, notFound } from '../../core/http/app.exception';
import { PrismaService } from '../../core/prisma/prisma.service';
import { scopeWhere } from '../../core/rbac/scope';

export const commissionInclude = {
  user: { select: { id: true, fullName: true } },
  payment: { select: { id: true, number: true } },
  deal: { select: { id: true, number: true, title: true } },
  rule: { select: { id: true, name: true } },
} satisfies Prisma.CommissionInclude;

export const toCommissionDto = (
  c: Prisma.CommissionGetPayload<{ include: typeof commissionInclude }>,
): CommissionDto => ({
  id: c.id,
  user: { id: c.user.id, name: c.user.fullName },
  role: c.role,
  payment: {
    id: c.payment.id,
    name: formatNumber('PAY', c.payment.number),
    number: formatNumber('PAY', c.payment.number),
  },
  deal: { id: c.deal.id, name: c.deal.title, number: formatNumber('D', c.deal.number) },
  rule: { id: c.rule.id, name: c.rule.name },
  period: c.period,
  baseAmountUzs: c.baseAmountUzs.toFixed(2),
  rate: c.rate.toString(),
  amountUzs: c.amountUzs.toFixed(2),
  status: c.status,
  createdAt: c.createdAt.toISOString(),
});

@Injectable()
export class CommissionsService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly audit: AuditService,
  ) {}

  /** Свои комиссии видит каждый; РОП — отдела; CEO — все (ТЗ §3, Rule 10). */
  async list(
    auth: AuthContext,
    q: CommissionListQuery & { page: number; pageSize: number },
  ): Promise<Paginated<CommissionDto> & { totalUzs: string }> {
    const where: Prisma.CommissionWhereInput = {
      AND: [
        scopeWhere<Prisma.CommissionWhereInput>(auth, 'commission.read', {
          own: (userId) => ({ userId }),
          team: (teamIds, userId) => ({ OR: [{ userId }, { deal: { teamId: { in: teamIds } } }] }),
        }),
        q.period ? { period: q.period } : {},
        q.userId ? { userId: q.userId } : {},
      ],
    };
    const [items, total, agg] = await Promise.all([
      this.prisma.commission.findMany({
        where,
        include: commissionInclude,
        orderBy: { createdAt: 'desc' },
        skip: (q.page - 1) * q.pageSize,
        take: q.pageSize,
      }),
      this.prisma.commission.count({ where }),
      this.prisma.commission.aggregate({
        where: { AND: [where, { status: { not: 'CANCELLED' } }] },
        _sum: { amountUzs: true },
      }),
    ]);
    return {
      items: items.map(toCommissionDto),
      total,
      page: q.page,
      pageSize: q.pageSize,
      totalUzs: (agg._sum.amountUzs ?? 0).toString(),
    };
  }

  async rules(): Promise<CommissionRuleDto[]> {
    const rows = await this.prisma.commissionRule.findMany({
      include: { commissions: { take: 0 } },
      orderBy: [{ appliesTo: 'asc' }, { priority: 'desc' }],
    });
    const users = new Map(
      (
        await this.prisma.user.findMany({
          where: { id: { in: rows.map((r) => r.userId).filter((x): x is string => !!x) } },
        })
      ).map((u) => [u.id, u]),
    );
    return rows.map((r) => ({
      id: r.id,
      name: r.name,
      appliesTo: r.appliesTo,
      user:
        r.userId && users.get(r.userId)
          ? { id: r.userId, name: users.get(r.userId)!.fullName }
          : null,
      calcType: r.calcType,
      value: r.value.toString(),
      conditions: (r.conditions as CommissionRuleDto['conditions']) ?? null,
      priority: r.priority,
      isActive: r.isActive,
    }));
  }

  async upsertRule(
    auth: AuthContext,
    id: string | null,
    input: z.output<typeof upsertCommissionRuleSchema>,
    meta: RequestMeta,
  ) {
    if (input.calcType === 'PERCENT_OF_PROFIT') {
      throw businessRule(
        'Комиссия от прибыли станет доступна в Phase 5 вместе с учётом расходов проекта',
      );
    }
    if (input.calcType !== 'FIXED_PER_DEAL' && input.value > 100)
      throw businessRule('Процент не может быть больше 100');
    const data = {
      name: input.name,
      appliesTo: input.appliesTo,
      userId: input.userId ?? null,
      calcType: input.calcType,
      value: input.value,
      conditions: (input.conditions ?? undefined) as Prisma.InputJsonValue | undefined,
      priority: input.priority,
      isActive: input.isActive,
    };
    return this.prisma.$transaction(async (tx) => {
      const before = id ? await tx.commissionRule.findUnique({ where: { id } }) : null;
      if (id && !before) throw notFound('Правило');
      const saved = id
        ? await tx.commissionRule.update({ where: { id }, data })
        : await tx.commissionRule.create({ data });
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: id ? 'commission_rule.update' : 'commission_rule.create',
        entityType: 'commission_rule',
        entityId: saved.id,
        changes: {
          value: { old: before?.value.toString() ?? null, new: saved.value.toString() },
          conditions: { old: before?.conditions ?? null, new: saved.conditions ?? null },
          isActive: { old: before?.isActive ?? null, new: saved.isActive },
        },
        meta,
      });
      return saved.id;
    });
  }
}
