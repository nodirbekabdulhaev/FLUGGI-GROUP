"use strict";
var __decorate = (this && this.__decorate) || function (decorators, target, key, desc) {
    var c = arguments.length, r = c < 3 ? target : desc === null ? desc = Object.getOwnPropertyDescriptor(target, key) : desc, d;
    if (typeof Reflect === "object" && typeof Reflect.decorate === "function") r = Reflect.decorate(decorators, target, key, desc);
    else for (var i = decorators.length - 1; i >= 0; i--) if (d = decorators[i]) r = (c < 3 ? d(r) : c > 3 ? d(target, key, r) : d(target, key)) || r;
    return c > 3 && r && Object.defineProperty(target, key, r), r;
};
var __metadata = (this && this.__metadata) || function (k, v) {
    if (typeof Reflect === "object" && typeof Reflect.metadata === "function") return Reflect.metadata(k, v);
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.TeamsService = void 0;
const common_1 = require("@nestjs/common");
const audit_service_1 = require("../../core/audit/audit.service");
const app_exception_1 = require("../../core/http/app.exception");
const prisma_service_1 = require("../../core/prisma/prisma.service");
const teamInclude = {
    head: { select: { id: true, fullName: true } },
    _count: { select: { members: { where: { deletedAt: null } } } },
};
const toDto = (t) => ({
    id: t.id,
    name: t.name,
    head: t.head,
    membersCount: t._count.members,
    createdAt: t.createdAt.toISOString(),
});
let TeamsService = class TeamsService {
    prisma;
    audit;
    constructor(prisma, audit) {
        this.prisma = prisma;
        this.audit = audit;
    }
    async list() {
        const teams = await this.prisma.team.findMany({
            where: { deletedAt: null },
            include: teamInclude,
            orderBy: { name: 'asc' },
        });
        return teams.map(toDto);
    }
    async create(auth, input, meta) {
        await this.assertNameFree(input.name);
        return this.prisma.$transaction(async (tx) => {
            if (input.headId)
                await this.assertRop(tx, input.headId);
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
            return toDto(await tx.team.findUniqueOrThrow({ where: { id: team.id }, include: teamInclude }));
        });
    }
    async update(auth, id, input, meta) {
        const before = await this.prisma.team.findFirst({ where: { id, deletedAt: null } });
        if (!before)
            throw (0, app_exception_1.notFound)('Отдел');
        if (input.name && input.name !== before.name)
            await this.assertNameFree(input.name);
        return this.prisma.$transaction(async (tx) => {
            if (input.headId)
                await this.assertRop(tx, input.headId);
            const team = await tx.team.update({
                where: { id },
                data: { name: input.name, headId: input.headId },
            });
            if (input.headId)
                await tx.user.update({ where: { id: input.headId }, data: { teamId: id } });
            const changes = (0, audit_service_1.diffFields)(before, team, ['name', 'headId']);
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
    async remove(auth, id, meta) {
        const team = await this.prisma.team.findFirst({
            where: { id, deletedAt: null },
            include: teamInclude,
        });
        if (!team)
            throw (0, app_exception_1.notFound)('Отдел');
        if (team._count.members > 0) {
            throw (0, app_exception_1.businessRule)('В отделе есть сотрудники. Сначала переведите их в другой отдел');
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
    async assertNameFree(name) {
        if (await this.prisma.team.findUnique({ where: { name } })) {
            throw (0, app_exception_1.conflict)('Отдел с таким названием уже существует');
        }
    }
    async assertRop(tx, userId) {
        const user = await tx.user.findFirst({
            where: { id: userId, deletedAt: null, status: 'ACTIVE' },
            include: { role: true },
        });
        if (!user || user.role.code !== 'ROP') {
            throw (0, app_exception_1.businessRule)('Руководителем отдела может быть только активный сотрудник с ролью РОП', [
                { path: 'headId', message: 'Выберите сотрудника с ролью РОП' },
            ]);
        }
    }
};
exports.TeamsService = TeamsService;
exports.TeamsService = TeamsService = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [prisma_service_1.PrismaService,
        audit_service_1.AuditService])
], TeamsService);
//# sourceMappingURL=teams.service.js.map