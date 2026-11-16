import { Injectable } from '@nestjs/common';
import type { TeamDto, createTeamSchema, updateTeamSchema } from '@fluggi/contracts';
import type { Prisma } from '@fluggi/db';
import type { z } from 'zod';
import type { AuthContext, RequestMeta } from '../../core/auth/auth-context';
import { AuditService, diffFields } from '../../core/audit/audit.service';
import { businessRule, conflict, notFound } from '../../core/http/app.exception';
import { PrismaService, type Tx } from '../../core/prisma/prisma.service';

const teamInclude = {
  head: { select: { id: true, fullName: true } },
  _count: { select: { members: { where: { deletedAt: null } } } },
} satisfies Prisma.TeamInclude;

type TeamRow = Prisma.TeamGetPayload<{ include: typeof teamInclude }>;

const toDto = (t: TeamRow): TeamDto => ({
  id: t.id,
  name: t.name,
  head: t.head,
  membersCount: t._count.members,
  createdAt: t.createdAt.toISOString(),
});

@Injectable()
export class TeamsService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly audit: AuditService,
  ) {}

  async list(): Promise<TeamDto[]> {
    const teams = await this.prisma.team.findMany({
      where: { deletedAt: null },
      include: teamInclude,
      orderBy: { name: 'asc' },
    });
    return teams.map(toDto);
  }

  async create(
    auth: AuthContext,
    input: z.output<typeof createTeamSchema>,
    meta: RequestMeta,
  ): Promise<TeamDto> {
    await this.assertNameFree(input.name);
    return this.prisma.$transaction(async (tx) => {
      if (input.headId) await this.assertRop(tx, input.headId);
      const team = await tx.team.create({
        data: { name: input.name, headId: input.headId ?? null },
      });
      if (input.headId)
        await tx.user.update({ where: { id: input.headId }, data: { teamId: team.id } });
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: 'team.create',
        entityType: 'team',
        entityId: team.id,
        changes: { name: { old: null, new: team.name }, headId: { old: null, new: team.headId } },
        meta,
      });
      return toDto(
        await tx.team.findUniqueOrThrow({ where: { id: team.id }, include: teamInclude }),
      );
    });
  }

  async update(
    auth: AuthContext,
    id: string,
    input: z.output<typeof updateTeamSchema>,
    meta: RequestMeta,
  ): Promise<TeamDto> {
    const before = await this.prisma.team.findFirst({ where: { id, deletedAt: null } });
    if (!before) throw notFound('Отдел');
    if (input.name && input.name !== before.name) await this.assertNameFree(input.name);

    return this.prisma.$transaction(async (tx) => {
      if (input.headId) await this.assertRop(tx, input.headId);
      const team = await tx.team.update({
        where: { id },
        data: { name: input.name, headId: input.headId },
      });
      if (input.headId) await tx.user.update({ where: { id: input.headId }, data: { teamId: id } });
      const changes = diffFields(before, team, ['name', 'headId']);
      if (changes) {
        await this.audit.log(tx, {
          actorId: auth.userId,
          action: 'team.update',
          entityType: 'team',
          entityId: id,
          changes,
          meta,
        });
      }
      return toDto(await tx.team.findUniqueOrThrow({ where: { id }, include: teamInclude }));
    });
  }

  async remove(auth: AuthContext, id: string, meta: RequestMeta): Promise<void> {
    const team = await this.prisma.team.findFirst({
      where: { id, deletedAt: null },
      include: teamInclude,
    });
    if (!team) throw notFound('Отдел');
    if (team._count.members > 0) {
      throw businessRule('В отделе есть сотрудники. Сначала переведите их в другой отдел');
    }
    await this.prisma.$transaction(async (tx) => {
      // Soft delete: имя освобождается, история сохраняется.
      await tx.team.update({
        where: { id },
        data: {
          deletedAt: new Date(),
          name: `${team.name} (удалён ${id.slice(0, 8)})`,
          headId: null,
        },
      });
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: 'team.delete',
        entityType: 'team',
        entityId: id,
        changes: { name: { old: team.name, new: null } },
        meta,
      });
    });
  }

  private async assertNameFree(name: string) {
    if (await this.prisma.team.findUnique({ where: { name } })) {
      throw conflict('Отдел с таким названием уже существует');
    }
  }

  private async assertRop(tx: Tx, userId: string) {
    const user = await tx.user.findFirst({
      where: { id: userId, deletedAt: null, status: 'ACTIVE' },
      include: { role: true },
    });
    if (!user || user.role.code !== 'ROP') {
      throw businessRule('Руководителем отдела может быть только активный сотрудник с ролью РОП', [
        { path: 'headId', message: 'Выберите сотрудника с ролью РОП' },
      ]);
    }
  }
}
