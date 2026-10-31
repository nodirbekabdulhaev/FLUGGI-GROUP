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
exports.UsersService = void 0;
const common_1 = require("@nestjs/common");
const contracts_1 = require("@fluggi/contracts");
const password_service_1 = require("../../core/auth/password.service");
const session_service_1 = require("../../core/auth/session.service");
const audit_service_1 = require("../../core/audit/audit.service");
const app_exception_1 = require("../../core/http/app.exception");
const outbox_service_1 = require("../../core/outbox/outbox.service");
const prisma_service_1 = require("../../core/prisma/prisma.service");
const scope_1 = require("../../core/rbac/scope");
const users_mapper_1 = require("./users.mapper");
let UsersService = class UsersService {
    prisma;
    passwords;
    sessions;
    audit;
    outbox;
    constructor(prisma, passwords, sessions, audit, outbox) {
        this.prisma = prisma;
        this.passwords = passwords;
        this.sessions = sessions;
        this.audit = audit;
        this.outbox = outbox;
    }
    /** Видимость сотрудников: ALL — все, TEAM — свои отделы, OWN — только себя. */
    visibleWhere(auth) {
        return {
            deletedAt: null,
            ...(0, scope_1.scopeWhere)(auth, 'employee.read', {
                own: (userId) => ({ id: userId }),
                team: (teamIds, userId) => ({ OR: [{ teamId: { in: teamIds } }, { id: userId }] }),
            }),
        };
    }
    async list(auth, query) {
        const filters = [this.visibleWhere(auth)];
        if (query.q) {
            filters.push({
                OR: [
                    { fullName: { contains: query.q } },
                    { email: { contains: query.q } },
                    { phone: { contains: query.q } },
                ],
            });
        }
        if (query.roleCode)
            filters.push({ role: { code: query.roleCode } });
        if (query.teamId)
            filters.push({ teamId: query.teamId });
        if (query.status)
            filters.push({ status: query.status });
        const where = { AND: filters };
        const [items, total] = await Promise.all([
            this.prisma.user.findMany({
                where,
                include: users_mapper_1.userInclude,
                orderBy: [{ status: 'asc' }, { fullName: 'asc' }],
                skip: (query.page - 1) * query.pageSize,
                take: query.pageSize,
            }),
            this.prisma.user.count({ where }),
        ]);
        return { items: items.map(users_mapper_1.toUserDto), total, page: query.page, pageSize: query.pageSize };
    }
    async get(auth, id) {
        const user = await this.prisma.user.findFirst({
            where: { AND: [this.visibleWhere(auth), { id }] },
            include: users_mapper_1.userInclude,
        });
        if (!user)
            throw (0, app_exception_1.notFound)('Сотрудник');
        return (0, users_mapper_1.toUserDto)(user);
    }
    async assertDirections(ids) {
        if (!ids.length)
            return;
        const n = await this.prisma.direction.count({ where: { id: { in: ids } } });
        if (n !== new Set(ids).size)
            throw (0, app_exception_1.businessRule)('Нет такого направления', [
                { path: 'directionIds', message: 'Выберите направление из списка' },
            ]);
    }
    async create(auth, input, meta) {
        this.assertCanAssignRole(auth, input.roleCode);
        if (await this.prisma.user.findUnique({ where: { email: input.email } })) {
            throw (0, app_exception_1.conflict)('Сотрудник с таким email уже существует');
        }
        const role = await this.prisma.role.findUniqueOrThrow({ where: { code: input.roleCode } });
        if (input.teamId)
            await this.assertTeamExists(input.teamId);
        await this.assertDirections(input.directionIds);
        const temporaryPassword = input.password ? undefined : this.passwords.generateTemporary();
        const passwordHash = await this.passwords.hash(input.password ?? temporaryPassword);
        const user = await this.prisma.$transaction(async (tx) => {
            const created = await tx.user.create({
                data: {
                    email: input.email,
                    fullName: input.fullName,
                    phone: input.phone ?? null,
                    passwordHash,
                    roleId: role.id,
                    teamId: input.teamId ?? null,
                    locale: input.locale,
                    employee: {
                        create: {
                            position: input.position ?? null,
                            specialty: input.roleCode === 'EXECUTOR' ? (input.specialty ?? null) : null,
                        },
                    },
                    directions: { create: input.directionIds.map((directionId) => ({ directionId })) },
                },
                include: users_mapper_1.userInclude,
            });
            await this.audit.log(tx, {
                actorId: auth.userId,
                action: 'user.create',
                entityType: 'user',
                entityId: created.id,
                changes: {
                    email: { old: null, new: created.email },
                    role: { old: null, new: input.roleCode },
                    teamId: { old: null, new: created.teamId },
                },
                meta,
            });
            await this.outbox.publish(tx, 'user.created', { userId: created.id, roleCode: input.roleCode }, auth.userId);
            return created;
        });
        return { user: (0, users_mapper_1.toUserDto)(user), temporaryPassword };
    }
    async update(auth, id, input, meta) {
        const before = await this.findManageable(auth, id);
        const newRole = input.roleCode ?? before.role.code;
        if (input.roleCode && input.roleCode !== before.role.code) {
            if (id === auth.userId)
                throw (0, app_exception_1.businessRule)('Нельзя изменить собственную роль');
            this.assertCanAssignRole(auth, input.roleCode);
            if (before.role.code === 'CEO')
                await this.assertNotLastCeo(id);
            if (before.role.code === 'ROP')
                await this.assertNotTeamHead(id);
        }
        if (input.teamId)
            await this.assertTeamExists(input.teamId);
        if (input.directionIds)
            await this.assertDirections(input.directionIds);
        if (input.email && input.email !== before.email) {
            if (await this.prisma.user.findUnique({ where: { email: input.email } })) {
                throw (0, app_exception_1.conflict)('Сотрудник с таким email уже существует');
            }
        }
        const role = input.roleCode && input.roleCode !== before.role.code
            ? await this.prisma.role.findUniqueOrThrow({ where: { code: input.roleCode } })
            : null;
        const specialty = newRole === 'EXECUTOR'
            ? input.specialty === undefined
                ? (before.employee?.specialty ?? null)
                : input.specialty
            : null;
        const position = input.position === undefined ? (before.employee?.position ?? null) : input.position;
        return this.prisma.$transaction(async (tx) => {
            const updated = await tx.user.update({
                where: { id },
                data: {
                    email: input.email,
                    fullName: input.fullName,
                    phone: input.phone,
                    locale: input.locale,
                    teamId: input.teamId === undefined ? undefined : input.teamId,
                    roleId: role?.id,
                    employee: {
                        upsert: {
                            create: { position, specialty },
                            update: { position, specialty },
                        },
                    },
                    // Направления меняют видимость проектов сразу: контекст сессии читается из БД на каждый запрос
                    directions: input.directionIds
                        ? {
                            deleteMany: {},
                            create: input.directionIds.map((directionId) => ({ directionId })),
                        }
                        : undefined,
                },
                include: users_mapper_1.userInclude,
            });
            const changes = (0, audit_service_1.diffFields)({
                email: before.email,
                fullName: before.fullName,
                phone: before.phone,
                locale: before.locale,
                teamId: before.teamId,
                role: before.role.code,
                position: before.employee?.position ?? null,
                specialty: before.employee?.specialty ?? null,
                directions: before.directions.map((d) => d.direction.name).join(', '),
            }, {
                email: updated.email,
                fullName: updated.fullName,
                phone: updated.phone,
                locale: updated.locale,
                teamId: updated.teamId,
                role: updated.role.code,
                position: updated.employee?.position ?? null,
                specialty: updated.employee?.specialty ?? null,
                directions: updated.directions.map((d) => d.direction.name).join(', '),
            }, [
                'email',
                'fullName',
                'phone',
                'locale',
                'teamId',
                'role',
                'position',
                'specialty',
                'directions',
            ]);
            if (changes) {
                await this.audit.log(tx, {
                    actorId: auth.userId,
                    action: 'user.update',
                    entityType: 'user',
                    entityId: id,
                    changes,
                    meta,
                });
            }
            if (role) {
                // Права изменились — все сессии пользователя закрываются.
                await this.sessions.revokeAllForUser(id, undefined, tx);
                await this.outbox.publish(tx, 'user.role_changed', { userId: id, from: before.role.code, to: role.code }, auth.userId);
            }
            return (0, users_mapper_1.toUserDto)(updated);
        });
    }
    async setBlocked(auth, id, blocked, meta) {
        if (id === auth.userId)
            throw (0, app_exception_1.businessRule)('Нельзя заблокировать собственную учётную запись');
        const before = await this.findManageable(auth, id);
        if (blocked && before.role.code === 'CEO')
            await this.assertNotLastCeo(id);
        const status = blocked ? 'BLOCKED' : 'ACTIVE';
        if (before.status === status)
            return (0, users_mapper_1.toUserDto)(before);
        return this.prisma.$transaction(async (tx) => {
            const updated = await tx.user.update({
                where: { id },
                data: { status, failedLoginCount: 0, lockedUntil: null },
                include: users_mapper_1.userInclude,
            });
            if (blocked) {
                await this.sessions.revokeAllForUser(id, undefined, tx);
                await this.outbox.publish(tx, 'user.blocked', { userId: id }, auth.userId);
            }
            await this.audit.log(tx, {
                actorId: auth.userId,
                action: blocked ? 'user.block' : 'user.unblock',
                entityType: 'user',
                entityId: id,
                changes: { status: { old: before.status, new: status } },
                meta,
            });
            return (0, users_mapper_1.toUserDto)(updated);
        });
    }
    /** Открытая работа сотрудника: что нужно передать перед удалением. */
    async workload(auth, id) {
        await this.findManageable(auth, id);
        const open = { in: [...contracts_1.OPEN_TASK_STATUSES] };
        const [leads, deals, clients, projects, tasks, todos, threads] = await Promise.all([
            this.prisma.lead.count({ where: { ownerId: id, status: 'OPEN', deletedAt: null } }),
            this.prisma.deal.count({ where: { ownerId: id, status: 'OPEN', deletedAt: null } }),
            this.prisma.client.count({ where: { ownerId: id, deletedAt: null } }),
            this.prisma.project.count({
                where: {
                    deletedAt: null,
                    status: { in: [...contracts_1.ACTIVE_PROJECT_STATUSES] },
                    OR: [{ managerId: id }, { ropId: id }],
                },
            }),
            this.prisma.task.count({ where: { assigneeId: id, deletedAt: null, status: open } }),
            this.prisma.todo.count({ where: { ownerId: id, status: 'OPEN', deletedAt: null } }),
            this.prisma.socialThread.count({ where: { ownerId: id } }),
        ]);
        const total = leads + deals + clients + projects + tasks + todos + threads;
        return { leads, deals, clients, projects, tasks, todos, threads, total };
    }
    /**
     * Удаление сотрудника: открытая работа передаётся другому сотруднику, история (оплаты,
     * комиссии, зарплата, аудит) остаётся с его именем. Учётная запись скрывается и не может войти;
     * email освобождается — его можно выдать новому сотруднику.
     */
    async remove(auth, id, transferToId, meta) {
        if (id === auth.userId)
            throw (0, app_exception_1.businessRule)('Нельзя удалить собственную учётную запись');
        const before = await this.findManageable(auth, id);
        if (before.role.code === 'CEO')
            await this.assertNotLastCeo(id);
        await this.assertNotTeamHead(id);
        const work = await this.workload(auth, id);
        let target = null;
        if (transferToId) {
            if (transferToId === id)
                throw (0, app_exception_1.businessRule)('Выберите другого сотрудника');
            target = await this.prisma.user.findFirst({
                where: { id: transferToId, status: 'ACTIVE', deletedAt: null },
                select: { id: true, teamId: true, fullName: true },
            });
            if (!target)
                throw (0, app_exception_1.businessRule)('Сотрудник для передачи не найден', [
                    { path: 'transferToId', message: 'Выберите активного сотрудника' },
                ]);
        }
        else if (work.total > 0) {
            throw (0, app_exception_1.businessRule)('У сотрудника есть открытая работа — выберите, кому её передать', [
                { path: 'transferToId', message: 'Выберите, кому передать работу' },
            ]);
        }
        await this.prisma.$transaction(async (tx) => {
            if (target) {
                const to = target;
                await tx.lead.updateMany({
                    where: { ownerId: id, status: 'OPEN', deletedAt: null },
                    data: { ownerId: to.id, teamId: to.teamId },
                });
                await tx.deal.updateMany({
                    where: { ownerId: id, status: 'OPEN', deletedAt: null },
                    data: { ownerId: to.id, teamId: to.teamId },
                });
                await tx.client.updateMany({
                    where: { ownerId: id, deletedAt: null },
                    data: { ownerId: to.id, teamId: to.teamId },
                });
                const active = { deletedAt: null, status: { in: [...contracts_1.ACTIVE_PROJECT_STATUSES] } };
                await tx.project.updateMany({
                    where: { ...active, managerId: id },
                    data: { managerId: to.id },
                });
                await tx.project.updateMany({ where: { ...active, ropId: id }, data: { ropId: to.id } });
                // Задачи: новый исполнитель становится участником команды проекта
                const tasks = await tx.task.findMany({
                    where: { assigneeId: id, deletedAt: null, status: { in: [...contracts_1.OPEN_TASK_STATUSES] } },
                    select: { id: true, projectId: true },
                });
                for (const projectId of new Set(tasks.map((t) => t.projectId))) {
                    await tx.projectMember.upsert({
                        where: { projectId_userId: { projectId, userId: to.id } },
                        update: { status: 'ACTIVE' },
                        create: { projectId, userId: to.id, assignedById: auth.userId },
                    });
                }
                await tx.task.updateMany({
                    where: { id: { in: tasks.map((t) => t.id) } },
                    data: { assigneeId: to.id },
                });
                await tx.todo.updateMany({
                    where: { ownerId: id, status: 'OPEN', deletedAt: null },
                    data: { ownerId: to.id },
                });
                await tx.socialThread.updateMany({
                    where: { ownerId: id },
                    data: { ownerId: to.id, teamId: to.teamId },
                });
            }
            await tx.projectMember.updateMany({
                where: { userId: id, status: 'ACTIVE' },
                data: { status: 'REMOVED' },
            });
            await tx.recurringTodo.updateMany({
                where: { ownerId: id, deletedAt: null },
                data: { deletedAt: new Date(), isActive: false },
            });
            await tx.leadForm.updateMany({ where: { ownerId: id }, data: { ownerId: null } });
            await tx.userDirection.deleteMany({ where: { userId: id } });
            await tx.user.update({
                where: { id },
                data: {
                    status: 'BLOCKED',
                    deletedAt: new Date(),
                    // email свободен для нового сотрудника; исходный — в аудите
                    email: `deleted.${id.slice(0, 8)}.${before.email}`,
                    telegramChatId: null,
                },
            });
            await this.sessions.revokeAllForUser(id, undefined, tx);
            await this.outbox.publish(tx, 'user.blocked', { userId: id }, auth.userId);
            await this.audit.log(tx, {
                actorId: auth.userId,
                action: 'user.delete',
                entityType: 'user',
                entityId: id,
                changes: {
                    email: { old: before.email, new: null },
                    fullName: { old: before.fullName, new: null },
                    transferredTo: { old: null, new: target ? target.fullName : null },
                    workload: { old: work, new: null },
                },
                meta,
            });
        });
    }
    async resetPassword(auth, id, meta) {
        if (id === auth.userId)
            throw (0, app_exception_1.businessRule)('Для смены своего пароля используйте профиль');
        await this.findManageable(auth, id);
        const temporaryPassword = this.passwords.generateTemporary();
        const passwordHash = await this.passwords.hash(temporaryPassword);
        await this.prisma.$transaction(async (tx) => {
            await tx.user.update({
                where: { id },
                data: { passwordHash, failedLoginCount: 0, lockedUntil: null },
            });
            await this.sessions.revokeAllForUser(id, undefined, tx);
            await this.audit.log(tx, {
                actorId: auth.userId,
                action: 'user.reset_password',
                entityType: 'user',
                entityId: id,
                meta,
            });
        });
        return { temporaryPassword };
    }
    // ─── правила ───
    async findManageable(auth, id) {
        const user = await this.prisma.user.findFirst({
            where: { id, deletedAt: null },
            include: users_mapper_1.userInclude,
        });
        if (!user)
            throw (0, app_exception_1.notFound)('Сотрудник');
        if (user.role.code === 'CEO' && auth.roleCode !== 'CEO') {
            throw (0, app_exception_1.forbidden)('Только CEO может изменять учётные записи CEO');
        }
        return user;
    }
    assertCanAssignRole(auth, role) {
        if (role === 'CEO' && auth.roleCode !== 'CEO') {
            throw (0, app_exception_1.forbidden)('Только CEO может назначать роль CEO');
        }
    }
    async assertTeamExists(teamId, tx = this.prisma) {
        const team = await tx.team.findFirst({ where: { id: teamId, deletedAt: null } });
        if (!team)
            throw (0, app_exception_1.businessRule)('Отдел не найден', [{ path: 'teamId', message: 'Отдел не найден' }]);
    }
    async assertNotLastCeo(userId) {
        const others = await this.prisma.user.count({
            where: { id: { not: userId }, status: 'ACTIVE', deletedAt: null, role: { code: 'CEO' } },
        });
        if (others === 0)
            throw (0, app_exception_1.businessRule)('В системе должен остаться хотя бы один активный CEO');
    }
    async assertNotTeamHead(userId) {
        const headed = await this.prisma.team.count({ where: { headId: userId, deletedAt: null } });
        if (headed > 0) {
            throw (0, app_exception_1.businessRule)('Сотрудник руководит отделом. Сначала назначьте отделу другого РОП');
        }
    }
};
exports.UsersService = UsersService;
exports.UsersService = UsersService = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [prisma_service_1.PrismaService,
        password_service_1.PasswordService,
        session_service_1.SessionService,
        audit_service_1.AuditService,
        outbox_service_1.OutboxService])
], UsersService);
//# sourceMappingURL=users.service.js.map