import { Injectable, OnModuleInit } from '@nestjs/common';
import {
  formatNumber,
  type ProjectCostLineDto,
  type updateCostLineSchema,
} from '@fluggi/contracts';
import { companyDate } from '@fluggi/domain';
import { Prisma } from '@fluggi/db';
import type { z } from 'zod';
import type { AuthContext, RequestMeta } from '../../core/auth/auth-context';
import { businessRule, notFound } from '../../core/http/app.exception';
import { OutboxDispatcher } from '../../core/outbox/outbox.dispatcher';
import { PrismaService, type Tx } from '../../core/prisma/prisma.service';
import { ProjectAccessService } from '../projects/project-access.service';
import { ExpensesService } from './expenses.service';

const include = {
  assignee: { select: { id: true, fullName: true } },
  expense: { select: { id: true, number: true, deletedAt: true } },
  workItem: { select: { defaultRate: true, currency: true } },
} satisfies Prisma.ProjectCostLineInclude;
type Row = Prisma.ProjectCostLineGetPayload<{ include: typeof include }>;

/**
 * Плановая себестоимость проекта по тарифу: из позиций тарифа (8 рилсов, 8 обложек…)
 * получаются строки «что и кому заплатить». Ставка — личная ставка назначенного исполнителя
 * (карточка сотрудника), иначе базовая. «Начислить» превращает строку в расход проекта.
 */
@Injectable()
export class CostLinesService implements OnModuleInit {
  constructor(
    private readonly prisma: PrismaService,
    private readonly projects: ProjectAccessService,
    private readonly expenses: ExpensesService,
    private readonly dispatcher: OutboxDispatcher,
  ) {}

  onModuleInit() {
    this.dispatcher.on('project.created', async (e) => {
      await this.prisma.$transaction((tx) => this.generate(tx, e.projectId));
    });
    this.dispatcher.on('project.member_added', async (e) => {
      await this.prisma.$transaction((tx) => this.assign(tx, e.projectId, e.userId));
    });
  }

  private toDto(l: Row): ProjectCostLineDto {
    const accrued = l.status === 'ACCRUED' && l.expense && !l.expense.deletedAt;
    return {
      id: l.id,
      kind: l.kind,
      label: l.label,
      specialty: l.specialty,
      quantity: l.quantity.toString(),
      rate: l.rate.toFixed(2),
      currency: l.currency,
      amount: l.quantity.mul(l.rate).toFixed(2),
      personalRate:
        l.kind === 'PIECE' && Boolean(l.workItem) && !l.rate.equals(l.workItem!.defaultRate),
      assignee: l.assignee ? { id: l.assignee.id, name: l.assignee.fullName } : null,
      // Расход удалили — строку можно начислить снова
      status: l.status === 'ACCRUED' && !accrued ? 'PLANNED' : l.status,
      expense: accrued
        ? { id: l.expense!.id, number: formatNumber('EXP', l.expense!.number) }
        : null,
    };
  }

  /** Строки из тарифов принятого КП сделки проекта. Повторный вызов ничего не делает. */
  async generate(tx: Tx, projectId: string): Promise<number> {
    if (await tx.projectCostLine.count({ where: { projectId } })) return 0;
    const project = await tx.project.findUnique({ where: { id: projectId } });
    if (!project) return 0;
    const proposal = await tx.proposal.findFirst({
      where: { dealId: project.dealId, status: 'ACCEPTED' },
      orderBy: { acceptedAt: 'desc' },
      include: {
        items: {
          where: { tariffId: { not: null } },
          include: {
            tariff: {
              include: { items: { include: { workItem: true }, orderBy: { sort: 'asc' } } },
            },
          },
          orderBy: { sort: 'asc' },
        },
      },
    });
    if (!proposal) return 0;
    const data: Prisma.ProjectCostLineCreateManyInput[] = [];
    for (const pi of proposal.items) {
      for (const ti of pi.tariff!.items) {
        const qty = ti.quantity.mul(pi.quantity);
        if (ti.kind === 'PIECE' && ti.workItem)
          data.push({
            projectId,
            tariffId: pi.tariffId,
            tariffItemId: ti.id,
            kind: 'PIECE',
            workItemId: ti.workItemId,
            specialty: ti.workItem.specialty,
            label: ti.label ?? ti.workItem.name,
            quantity: qty,
            rate: ti.workItem.defaultRate,
            currency: ti.workItem.currency,
          });
        else if (ti.kind === 'FIXED' && ti.amount)
          data.push({
            projectId,
            tariffId: pi.tariffId,
            tariffItemId: ti.id,
            kind: 'FIXED',
            specialty: ti.specialty,
            label: ti.label ?? 'Оплата исполнителю',
            quantity: pi.quantity,
            rate: ti.amount,
            currency: ti.currency,
          });
      }
    }
    // Порядок строк = порядок в тарифе: у пакетной вставки одинаковое время создания
    const base = Date.now();
    if (data.length)
      await tx.projectCostLine.createMany({
        data: data.map((d, i) => ({ ...d, createdAt: new Date(base + i) })),
      });
    // Исполнители, уже назначенные в команду
    const members = await tx.projectMember.findMany({ where: { projectId, status: 'ACTIVE' } });
    for (const m of members) await this.assign(tx, projectId, m.userId);
    return data.length;
  }

  /** Новый исполнитель в команде: ему — плановые строки его роли, с его личными ставками. */
  async assign(tx: Tx, projectId: string, userId: string) {
    const member = await tx.projectMember.findUnique({
      where: { projectId_userId: { projectId, userId } },
    });
    if (!member?.role || member.status !== 'ACTIVE') return;
    const lines = await tx.projectCostLine.findMany({
      where: { projectId, status: 'PLANNED', specialty: member.role, assigneeId: null },
    });
    for (const l of lines) {
      const personal =
        l.kind === 'PIECE' && l.workItemId
          ? await tx.employeeRate.findUnique({
              where: { userId_workItemId: { userId, workItemId: l.workItemId } },
            })
          : null;
      await tx.projectCostLine.update({
        where: { id: l.id },
        data: {
          assigneeId: userId,
          ...(personal ? { rate: personal.rate, currency: personal.currency } : {}),
        },
      });
    }
  }

  async list(auth: AuthContext, projectId: string): Promise<ProjectCostLineDto[]> {
    await this.projects.project(auth, projectId, 'finance.read');
    const rows = await this.prisma.projectCostLine.findMany({
      where: { projectId, status: { not: 'CANCELLED' } },
      include,
      orderBy: { createdAt: 'asc' },
    });
    return rows.map((r) => this.toDto(r));
  }

  private async line(auth: AuthContext, id: string) {
    const l = await this.prisma.projectCostLine.findUnique({ where: { id }, include });
    if (!l) throw notFound('Строка себестоимости');
    await this.projects.project(auth, l.projectId, 'expense.update');
    return l;
  }

  async update(
    auth: AuthContext,
    id: string,
    input: z.output<typeof updateCostLineSchema>,
  ): Promise<ProjectCostLineDto> {
    const l = await this.line(auth, id);
    if (this.toDto(l).status !== 'PLANNED') throw businessRule('Строка уже начислена');
    if (input.assigneeId) {
      const m = await this.prisma.projectMember.findUnique({
        where: { projectId_userId: { projectId: l.projectId, userId: input.assigneeId } },
      });
      if (!m || m.status !== 'ACTIVE')
        throw businessRule('Исполнитель должен быть в команде проекта');
    }
    const r = await this.prisma.projectCostLine.update({
      where: { id },
      data: {
        quantity: input.quantity,
        rate: input.rate,
        assigneeId: input.assigneeId,
        status: 'PLANNED',
        expenseId: null,
      },
      include,
    });
    return this.toDto(r);
  }

  /** Начислить исполнителю: строка становится расходом проекта (категория «Исполнитель»). */
  async accrue(auth: AuthContext, id: string, meta: RequestMeta): Promise<ProjectCostLineDto> {
    const l = await this.line(auth, id);
    if (this.toDto(l).status !== 'PLANNED') throw businessRule('Строка уже начислена');
    if (!l.assigneeId) throw businessRule('Сначала назначьте исполнителя');
    const expense = await this.expenses.create(
      auth,
      {
        scope: 'PROJECT',
        projectId: l.projectId,
        category: 'EXECUTOR',
        amount: l.quantity.mul(l.rate).toFixed(2),
        currency: l.currency,
        expenseDate: companyDate(new Date()),
        payeeUserId: l.assigneeId,
        description: `${l.label}: ${l.quantity.toString()} × ${l.rate.toFixed(2)} ${l.currency}`,
      },
      meta,
    );
    const r = await this.prisma.projectCostLine.update({
      where: { id },
      data: { status: 'ACCRUED', expenseId: expense.id },
      include,
    });
    return this.toDto(r);
  }

  async cancel(auth: AuthContext, id: string) {
    const l = await this.line(auth, id);
    if (this.toDto(l).status !== 'PLANNED')
      throw businessRule('Начисленную строку отменить нельзя — удалите расход');
    await this.prisma.projectCostLine.update({ where: { id }, data: { status: 'CANCELLED' } });
  }
}
