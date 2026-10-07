import { Injectable } from '@nestjs/common';
import { formatNumber, type SearchHitDto } from '@fluggi/contracts';
import type { Prisma } from '@fluggi/db';
import type { AuthContext } from '../../core/auth/auth-context';
import { scopeWhere } from '../../core/rbac/scope';
import { PrismaService } from '../../core/prisma/prisma.service';
import { CrmAccessService } from '../crm/crm-access.service';
import { ProjectAccessService } from '../projects/project-access.service';

const LIMIT = 5;
const ci = (q: string) => ({ contains: q, mode: 'insensitive' as const });

/** «D-00012», «ДГ-12», «12» → 12; иначе null. */
export function parseNumber(q: string): number | null {
  const m = /^(?:[a-zа-я]{1,3}\s*-?\s*)?0*(\d{1,9})$/i.exec(q.trim());
  return m ? Number(m[1]) : null;
}

/**
 * Глобальный поиск (ТЗ §44): клиенты, лиды, сделки, проекты, договоры, задачи, сотрудники.
 * Каждая сущность ищется только в пределах прав пользователя; без права — пропускается.
 */
@Injectable()
export class SearchService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly crm: CrmAccessService,
    private readonly projects: ProjectAccessService,
  ) {}

  async search(auth: AuthContext, q: string): Promise<SearchHitDto[]> {
    const n = parseNumber(q);
    const num = n !== null ? [{ number: n }] : [];
    const can = (code: keyof AuthContext['permissions']) => Boolean(auth.permissions[code]);
    const phone = q.replace(/[^\d+]/g, '');
    const phoneOr = phone.length >= 5 ? [{ phone: { contains: phone } }] : [];

    const tasks: Promise<SearchHitDto[]>[] = [];
    if (can('client.read'))
      tasks.push(
        this.prisma.client
          .findMany({
            where: {
              AND: [
                this.crm.clientWhere(auth),
                {
                  OR: [{ name: ci(q) }, { email: ci(q) }, { telegram: ci(q) }, ...phoneOr, ...num],
                },
              ],
            },
            select: { id: true, number: true, name: true, phone: true },
            take: LIMIT,
            orderBy: { updatedAt: 'desc' },
          })
          .then((rows) =>
            rows.map((r) => ({
              kind: 'client' as const,
              id: r.id,
              title: r.name,
              subtitle: [formatNumber('C', r.number), r.phone].filter(Boolean).join(' · '),
              link: `/clients/${r.id}`,
            })),
          ),
      );
    if (can('lead.read'))
      tasks.push(
        this.prisma.lead
          .findMany({
            where: {
              AND: [
                this.crm.leadWhere(auth),
                {
                  OR: [
                    { title: ci(q) },
                    { contactName: ci(q) },
                    { companyName: ci(q) },
                    { email: ci(q) },
                    { telegram: ci(q) },
                    ...phoneOr,
                    ...num,
                  ],
                },
              ],
            },
            select: { id: true, number: true, title: true, contactName: true, phone: true },
            take: LIMIT,
            orderBy: { updatedAt: 'desc' },
          })
          .then((rows) =>
            rows.map((r) => ({
              kind: 'lead' as const,
              id: r.id,
              title: r.title,
              subtitle: [formatNumber('L', r.number), r.contactName, r.phone]
                .filter(Boolean)
                .join(' · '),
              link: `/sales/leads/${r.id}`,
            })),
          ),
      );
    if (can('deal.read'))
      tasks.push(
        this.prisma.deal
          .findMany({
            where: {
              AND: [
                this.crm.dealWhere(auth),
                { OR: [{ title: ci(q) }, { client: { name: ci(q) } }, ...num] },
              ],
            },
            select: { id: true, number: true, title: true, client: { select: { name: true } } },
            take: LIMIT,
            orderBy: { updatedAt: 'desc' },
          })
          .then((rows) =>
            rows.map((r) => ({
              kind: 'deal' as const,
              id: r.id,
              title: r.title,
              subtitle: `${formatNumber('D', r.number)} · ${r.client.name}`,
              link: `/sales/deals/${r.id}`,
            })),
          ),
      );
    if (can('project.read'))
      tasks.push(
        this.prisma.project
          .findMany({
            where: {
              AND: [
                this.projects.projectWhere(auth),
                { OR: [{ name: ci(q) }, { client: { name: ci(q) } }, ...num] },
              ],
            },
            select: { id: true, number: true, name: true, client: { select: { name: true } } },
            take: LIMIT,
            orderBy: { updatedAt: 'desc' },
          })
          .then((rows) =>
            rows.map((r) => ({
              kind: 'project' as const,
              id: r.id,
              title: r.name,
              subtitle: `${formatNumber('P', r.number)} · ${r.client.name}`,
              link: `/projects/${r.id}`,
            })),
          ),
      );
    if (can('contract.read'))
      tasks.push(
        this.prisma.contract
          .findMany({
            where: {
              AND: [
                { deal: this.crm.dealWhere(auth, 'contract.read') },
                { OR: [...num, { deal: { client: { name: ci(q) } } }] },
              ],
            },
            select: {
              id: true,
              number: true,
              dealId: true,
              status: true,
              deal: { select: { client: { select: { name: true } } } },
            },
            take: LIMIT,
            orderBy: { createdAt: 'desc' },
          })
          .then((rows) =>
            rows.map((r) => ({
              kind: 'contract' as const,
              id: r.id,
              title: `Договор ${formatNumber('C', r.number).replace(/^C-/, 'ДГ-')}`,
              subtitle: r.deal.client.name,
              link: `/sales/deals/${r.dealId}`,
            })),
          ),
      );
    if (can('task.read'))
      tasks.push(
        this.prisma.task
          .findMany({
            where: { AND: [this.projects.taskWhere(auth), { OR: [{ title: ci(q) }, ...num] }] },
            select: {
              id: true,
              number: true,
              title: true,
              project: { select: { id: true, name: true } },
            },
            take: LIMIT,
            orderBy: { updatedAt: 'desc' },
          })
          .then((rows) =>
            rows.map((r) => ({
              kind: 'task' as const,
              id: r.id,
              title: r.title,
              subtitle: `${formatNumber('T', r.number)} · ${r.project.name}`,
              link: `/projects/${r.project.id}?task=${r.id}`,
            })),
          ),
      );
    if (can('employee.read'))
      tasks.push(
        this.prisma.user
          .findMany({
            where: {
              AND: [
                { deletedAt: null },
                scopeWhere<Prisma.UserWhereInput>(auth, 'employee.read', {
                  own: (userId) => ({ id: userId }),
                  team: (teamIds, userId) => ({
                    OR: [{ teamId: { in: teamIds } }, { id: userId }],
                  }),
                }),
                { OR: [{ fullName: ci(q) }, { email: ci(q) }] },
              ],
            },
            select: { id: true, fullName: true, email: true, role: { select: { name: true } } },
            take: LIMIT,
          })
          .then((rows) =>
            rows.map((r) => ({
              kind: 'user' as const,
              id: r.id,
              title: r.fullName,
              subtitle: `${r.role.name} · ${r.email}`,
              link: `/team/employees?q=${encodeURIComponent(r.email)}`,
            })),
          ),
      );
    return (await Promise.all(tasks)).flat();
  }
}
