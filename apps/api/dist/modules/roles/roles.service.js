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
exports.RolesService = void 0;
const common_1 = require("@nestjs/common");
const contracts_1 = require("@fluggi/contracts");
const audit_service_1 = require("../../core/audit/audit.service");
const app_exception_1 = require("../../core/http/app.exception");
const prisma_service_1 = require("../../core/prisma/prisma.service");
let RolesService = class RolesService {
    prisma;
    audit;
    constructor(prisma, audit) {
        this.prisma = prisma;
        this.audit = audit;
    }
    async list() {
        const roles = await this.prisma.role.findMany({
            include: {
                permissions: { include: { permission: true } },
                _count: { select: { users: { where: { deletedAt: null } } } },
            },
            orderBy: { createdAt: 'asc' },
        });
        return roles.map((r) => ({
            id: r.id,
            code: r.code,
            name: r.name,
            isSystem: r.isSystem,
            usersCount: r._count.users,
            permissions: Object.fromEntries(r.permissions.map((p) => [p.permission.code, p.scope])),
        }));
    }
    async updatePermissions(auth, roleId, input, meta) {
        const role = await this.prisma.role.findUnique({
            where: { id: roleId },
            include: { permissions: { include: { permission: true } } },
        });
        if (!role)
            throw (0, app_exception_1.notFound)('Роль');
        if (role.code === 'CEO') {
            const missing = contracts_1.CEO_LOCKED_PERMISSIONS.filter((c) => input.permissions[c] !== 'ALL');
            if (missing.length > 0) {
                throw (0, app_exception_1.businessRule)(`У роли CEO нельзя ограничить права: ${missing.join(', ')}`);
            }
        }
        const all = await this.prisma.permission.findMany();
        const idByCode = new Map(all.map((p) => [p.code, p.id]));
        const before = Object.fromEntries(role.permissions.map((p) => [p.permission.code, p.scope]));
        const changes = {};
        for (const code of new Set([...Object.keys(before), ...Object.keys(input.permissions)])) {
            const oldScope = before[code] ?? null;
            const newScope = input.permissions[code] ?? null;
            if (oldScope !== newScope)
                changes[code] = { old: oldScope, new: newScope };
        }
        await this.prisma.$transaction(async (tx) => {
            await tx.rolePermission.deleteMany({ where: { roleId } });
            await tx.rolePermission.createMany({
                data: Object.entries(input.permissions).map(([code, scope]) => ({
                    roleId,
                    permissionId: idByCode.get(code),
                    scope,
                })),
            });
            if (Object.keys(changes).length > 0) {
                await this.audit.log(tx, {
                    actorId: auth.userId,
                    action: 'role.permissions_update',
                    entityType: 'role',
                    entityId: roleId,
                    changes,
                    meta,
                });
            }
        });
        return (await this.list()).find((r) => r.id === roleId);
    }
};
exports.RolesService = RolesService;
exports.RolesService = RolesService = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [prisma_service_1.PrismaService,
        audit_service_1.AuditService])
], RolesService);
//# sourceMappingURL=roles.service.js.map