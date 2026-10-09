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
exports.AuthService = exports.LOCK_MINUTES = exports.MAX_FAILED_LOGINS = void 0;
const common_1 = require("@nestjs/common");
const audit_service_1 = require("../audit/audit.service");
const app_exception_1 = require("../http/app.exception");
const prisma_service_1 = require("../prisma/prisma.service");
const password_service_1 = require("./password.service");
const session_service_1 = require("./session.service");
exports.MAX_FAILED_LOGINS = 5;
exports.LOCK_MINUTES = 15;
const INVALID_CREDENTIALS = 'Неверный email или пароль';
let AuthService = class AuthService {
    prisma;
    passwords;
    sessions;
    audit;
    constructor(prisma, passwords, sessions, audit) {
        this.prisma = prisma;
        this.passwords = passwords;
        this.sessions = sessions;
        this.audit = audit;
    }
    async login(input, meta) {
        const user = await this.prisma.user.findUnique({ where: { email: input.email } });
        const usable = user && !user.deletedAt ? user : null;
        if (usable?.lockedUntil && usable.lockedUntil > new Date()) {
            throw new app_exception_1.AppException('RATE_LIMITED', `Слишком много неудачных попыток. Попробуйте через ${exports.LOCK_MINUTES} минут`);
        }
        const valid = await this.passwords.verify(usable?.passwordHash ?? null, input.password);
        if (!usable || !valid) {
            if (usable) {
                const failed = usable.failedLoginCount + 1;
                const lock = failed >= exports.MAX_FAILED_LOGINS;
                await this.prisma.$transaction(async (tx) => {
                    await tx.user.update({
                        where: { id: usable.id },
                        data: {
                            failedLoginCount: lock ? 0 : failed,
                            lockedUntil: lock ? new Date(Date.now() + exports.LOCK_MINUTES * 60_000) : undefined,
                        },
                    });
                    await this.audit.log(tx, {
                        actorId: usable.id,
                        action: lock ? 'auth.locked' : 'auth.login_failed',
                        entityType: 'user',
                        entityId: usable.id,
                        meta,
                    });
                });
            }
            throw new app_exception_1.AppException('UNAUTHENTICATED', INVALID_CREDENTIALS);
        }
        if (usable.status !== 'ACTIVE') {
            throw new app_exception_1.AppException('FORBIDDEN', 'Учётная запись заблокирована. Обратитесь к администратору');
        }
        return this.prisma.$transaction(async (tx) => {
            await tx.user.update({
                where: { id: usable.id },
                data: { failedLoginCount: 0, lockedUntil: null, lastLoginAt: new Date() },
            });
            const session = await this.sessions.create(usable.id, meta, tx);
            await this.audit.log(tx, {
                actorId: usable.id,
                action: 'auth.login',
                entityType: 'user',
                entityId: usable.id,
                meta: { ...meta, sessionId: session.sessionId },
            });
            return session;
        });
    }
    async logout(auth, meta) {
        await this.prisma.$transaction(async (tx) => {
            await this.sessions.revoke(auth.sessionId, tx);
            await this.audit.log(tx, {
                actorId: auth.userId,
                action: 'auth.logout',
                entityType: 'user',
                entityId: auth.userId,
                meta,
            });
        });
    }
    async changePassword(auth, input, meta) {
        const user = await this.prisma.user.findUniqueOrThrow({ where: { id: auth.userId } });
        if (!(await this.passwords.verify(user.passwordHash, input.currentPassword))) {
            throw new app_exception_1.AppException('VALIDATION_ERROR', 'Текущий пароль указан неверно', [
                { path: 'currentPassword', message: 'Неверный пароль' },
            ]);
        }
        const passwordHash = await this.passwords.hash(input.newPassword);
        await this.prisma.$transaction(async (tx) => {
            await tx.user.update({ where: { id: user.id }, data: { passwordHash } });
            // Остальные устройства разлогиниваются, текущая сессия сохраняется.
            await this.sessions.revokeAllForUser(user.id, auth.sessionId, tx);
            await this.audit.log(tx, {
                actorId: user.id,
                action: 'auth.password_changed',
                entityType: 'user',
                entityId: user.id,
                meta,
            });
        });
    }
    async listSessions(auth) {
        const sessions = await this.prisma.session.findMany({
            where: { userId: auth.userId, revokedAt: null, expiresAt: { gt: new Date() } },
            orderBy: { lastSeenAt: 'desc' },
        });
        return sessions.map((s) => ({
            id: s.id,
            ip: s.ip,
            userAgent: s.userAgent,
            createdAt: s.createdAt.toISOString(),
            lastSeenAt: s.lastSeenAt.toISOString(),
            current: s.id === auth.sessionId,
        }));
    }
    async revokeSession(auth, sessionId, meta) {
        await this.prisma.$transaction(async (tx) => {
            const { count } = await tx.session.updateMany({
                where: { id: sessionId, userId: auth.userId, revokedAt: null },
                data: { revokedAt: new Date() },
            });
            if (count === 0)
                throw new app_exception_1.AppException('NOT_FOUND', 'Сессия не найдена');
            await this.audit.log(tx, {
                actorId: auth.userId,
                action: 'auth.session_revoked',
                entityType: 'session',
                entityId: sessionId,
                meta,
            });
        });
    }
    toMe(auth) {
        return {
            id: auth.userId,
            email: auth.email,
            fullName: auth.fullName,
            locale: auth.locale,
            role: { code: auth.roleCode, name: auth.roleName },
            team: auth.teamId && auth.teamName ? { id: auth.teamId, name: auth.teamName } : null,
            headedTeamIds: auth.headedTeamIds,
            permissions: auth.permissions,
            telegramLinked: auth.telegramLinked,
        };
    }
};
exports.AuthService = AuthService;
exports.AuthService = AuthService = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [prisma_service_1.PrismaService,
        password_service_1.PasswordService,
        session_service_1.SessionService,
        audit_service_1.AuditService])
], AuthService);
//# sourceMappingURL=auth.service.js.map