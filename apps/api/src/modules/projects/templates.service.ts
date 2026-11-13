import { Injectable } from '@nestjs/common';
import type { ProjectTemplateDto, upsertProjectTemplateSchema } from '@fluggi/contracts';
import { companyDate, templateTaskDates } from '@fluggi/domain';
import type { Prisma, Project } from '@fluggi/db';
import type { z } from 'zod';
import type { AuthContext, RequestMeta } from '../../core/auth/auth-context';
import { AuditService } from '../../core/audit/audit.service';
import { businessRule, notFound } from '../../core/http/app.exception';
import { dateOnly, parseDate } from '../../core/http/serialize';
import { PrismaService, type Tx } from '../../core/prisma/prisma.service';
import { ActivityService } from '../crm/activity.service';

const include = {
  service: { select: { id: true, nameRu: true } },
  tasks: { orderBy: { sort: 'asc' } },
} satisfies Prisma.ProjectTemplateInclude;

type Row = Prisma.ProjectTemplateGetPayload<{ include: typeof include }>;

const toDto = (t: Row): ProjectTemplateDto => ({
  id: t.id,
  name: t.name,
  service: t.service ? { id: t.service.id, name: t.service.nameRu } : null,
  description: t.description,
  isActive: t.isActive,
  tasks: t.tasks.map((x) => ({
    id: x.id,
    title: x.title,
    description: x.description,
    role: x.role,
    startOffsetDays: x.startOffsetDays,
    durationDays: x.durationDays,
    priority: x.priority,
  })),
});

/** Шаблоны проектов и задач (ТЗ §62). */
@Injectable()
export class TemplatesService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly audit: AuditService,
    private readonly activity: ActivityService,
  ) {}

  async list(activeOnly: boolean): Promise<ProjectTemplateDto[]> {
    const rows = await this.prisma.projectTemplate.findMany({
      where: activeOnly ? { isActive: true } : {},
      include,
      orderBy: { name: 'asc' },
    });
    return rows.map(toDto);
  }

  private taskRows(input: z.output<typeof upsertProjectTemplateSchema>) {
    return input.tasks.map((t, sort) => ({
      title: t.title,
      description: t.description ?? null,
      role: t.role ?? null,
      startOffsetDays: t.startOffsetDays,
      durationDays: t.durationDays,
      priority: t.priority,
      sort,
    }));
  }

  async create(
    auth: AuthContext,
    input: z.output<typeof upsertProjectTemplateSchema>,
    meta: RequestMeta,
  ): Promise<ProjectTemplateDto> {
    return this.prisma.$transaction(async (tx) => {
      const t = await tx.projectTemplate.create({
        data: {
          name: input.name,
          serviceId: input.serviceId ?? null,
          description: input.description ?? null,
          isActive: input.isActive,
          tasks: { create: this.taskRows(input) },
        },
        include,
      });
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: 'project_template.create',
        entityType: 'project_template',
        entityId: t.id,
        changes: { name: { old: null, new: t.name } },
        meta,
      });
      return toDto(t);
    });
  }

  /** Изменение шаблона не затрагивает уже созданные задачи проектов. */
  async update(
    auth: AuthContext,
    id: string,
    input: z.output<typeof upsertProjectTemplateSchema>,
    meta: RequestMeta,
  ): Promise<ProjectTemplateDto> {
    const before = await this.prisma.projectTemplate.findUnique({ where: { id } });
    if (!before) throw notFound('Шаблон');
    return this.prisma.$transaction(async (tx) => {
      await tx.taskTemplate.deleteMany({ where: { projectTemplateId: id } });
      const t = await tx.projectTemplate.update({
        where: { id },
        data: {
          name: input.name,
          serviceId: input.serviceId ?? null,
          description: input.description ?? null,
          isActive: input.isActive,
          tasks: { create: this.taskRows(input) },
        },
        include,
      });
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: 'project_template.update',
        entityType: 'project_template',
        entityId: id,
        changes: {
          name: { old: before.name, new: t.name },
          isActive: { old: before.isActive, new: t.isActive },
          tasks: { old: null, new: t.tasks.length },
        },
        meta,
      });
      return toDto(t);
    });
  }

  /** Активный шаблон для услуги сделки (первый по названию). */
  async forService(tx: Tx, serviceId: string | null) {
    if (!serviceId) return null;
    return tx.projectTemplate.findFirst({
      where: { serviceId, isActive: true },
      orderBy: { name: 'asc' },
    });
  }

  /**
   * Создаёт задачи проекта из шаблона. Сроки — от даты начала проекта (или сегодня),
   * исполнитель — активный участник команды с ролью задачи, иначе РОП проекта
   * (ТЗ §77, Rule 5): задача перейдёт к исполнителю, когда его добавят в команду.
   */
  async apply(
    tx: Tx,
    project: Project,
    templateId: string,
    actorId: string,
  ): Promise<{ created: number }> {
    const template = await tx.projectTemplate.findUnique({
      where: { id: templateId },
      include: { tasks: { orderBy: { sort: 'asc' } } },
    });
    if (!template || !template.isActive) throw notFound('Шаблон');
    if (template.tasks.length === 0) throw businessRule('В шаблоне нет задач');

    const start = dateOnly(project.startDate) ?? companyDate(new Date());
    const members = await tx.projectMember.findMany({
      where: { projectId: project.id, status: 'ACTIVE', role: { not: null } },
      orderBy: { assignedAt: 'asc' },
    });
    const byRole = new Map(members.map((m) => [m.role, m.userId]));
    const last = await tx.task.aggregate({
      where: { projectId: project.id, status: 'TODO' },
      _max: { sortOrder: true },
    });
    let sortOrder = last._max.sortOrder ?? 0;

    for (const t of template.tasks) {
      const dates = templateTaskDates(start, t.startOffsetDays, t.durationDays);
      sortOrder += 1000;
      const task = await tx.task.create({
        data: {
          projectId: project.id,
          title: t.title,
          description: t.description,
          templateRole: t.role,
          assigneeId: (t.role ? byRole.get(t.role) : undefined) ?? project.ropId,
          creatorId: actorId,
          priority: t.priority,
          startDate: parseDate(dates.startDate),
          deadline: dates.deadline,
          sortOrder,
        },
      });
      await tx.taskStatusHistory.create({
        data: { taskId: task.id, toStatus: 'TODO', changedById: actorId },
      });
    }
    await tx.project.update({
      where: { id: project.id },
      data: {
        templateId: project.templateId ?? template.id,
        startDate: project.startDate ?? parseDate(start),
      },
    });
    await this.activity.log(tx, {
      type: 'project.template_applied',
      actorId,
      projectId: project.id,
      payload: { template: template.name, tasks: template.tasks.length },
    });
    return { created: template.tasks.length };
  }
}
