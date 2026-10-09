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
exports.SessionService = exports.hashToken = void 0;
const node_crypto_1 = require("node:crypto");
const common_1 = require("@nestjs/common");
const env_1 = require("../../config/env");
const prisma_service_1 = require("../prisma/prisma.service");
/** Как часто продлевать скользящую сессию (не на каждый запрос). */
const TOUCH_INTERVAL_MS = 5 * 60 * 1000;
const hashToken = (token) => (0, node_crypto_1.createHash)('sha256').update(token).digest('hex');
exports.hashToken = hashToken;
let SessionService = class SessionService {
    prisma;
    constructor(prisma) {
        this.prisma = prisma;
    }
    ttlMs() {
        return (0, env_1.loadEnv)().SESSION_TTL_DAYS * 24 * 60 * 60 * 1000;
    }
    async create(userId, meta, tx = this.prisma) {
        const token = (0, node_crypto_1.randomBytes)(32).toString('base64url');
        const expiresAt = new Date(Date.now() + this.ttlMs());
        const session = await tx.session.create({
            data: {
                userId,
                tokenHash: (0, exports.hashToken)(token),
                ip: meta.ip,
                userAgent: meta.userAgent,
                expiresAt,
            },
        });
        return { token, sessionId: session.id, expiresAt };
    }
    /**
     * Проверяет токен cookie и возвращает контекст пользователя или null.
     * `renewedUntil` — новый срок, если скользящая сессия была продлена (нужно обновить cookie).
     */
    async resolve(token) {
        const session = await this.prisma.session.findUnique({
            where: { tokenHash: (0, exports.hashToken)(token) },
            include: {
                user: {
                    include: {
                        role: { include: { permissions: { include: { permission: true } } } },
                        team: true,
                        headedTeams: { where: { deletedAt: null }, select: { id: true } },
                        directions: { select: { directionId: true } },
                    },
                },
            },
        });
        const now = new Date();
        if (!session || session.revokedAt || session.expiresAt <= now)
            return null;
        const { user } = session;
        if (user.status !== 'ACTIVE' || user.deletedAt)
            return null;
        let renewedUntil;
        if (now.getTime() - session.lastSeenAt.getTime() > TOUCH_INTERVAL_MS) {
            renewedUntil = new Date(now.getTime() + this.ttlMs());
            await this.prisma.session.update({
                where: { id: session.id },
                data: { lastSeenAt: now, expiresAt: renewedUntil },
            });
        }
        const permissions = {};
        for (const rp of user.role.permissions) {
            permissions[rp.permission.code] = rp.scope;
        }
        const auth = {
            sessionId: session.id,
            userId: user.id,
            email: user.email,
            fullName: user.fullName,
            locale: user.locale,
            roleCode: user.role.code,
            roleName: user.role.name,
            teamId: user.team && !user.team.deletedAt ? user.team.id : null,
            teamName: user.team && !user.team.deletedAt ? user.team.name : null,
            headedTeamIds: user.headedTeams.map((t) => t.id),
            directionIds: user.directions.map((d) => d.directionId),
            permissions,
            telegramLinked: user.telegramChatId !== null,
        };
        return { auth, renewedUntil };
    }
    revoke(sessionId, tx = this.prisma) {
        return tx.session.updateMany({
            where: { id: sessionId, revokedAt: null },
            data: { revokedAt: new Date() },
        });
    }
    revokeAllForUser(userId, exceptSessionId, tx = this.prisma) {
        return tx.session.updateMany({
            where: {
                userId,
                revokedAt: null,
                ...(exceptSessionId ? { id: { not: exceptSessionId } } : {}),
            },
            data: { revokedAt: new Date() },
        });
    }
};
exports.SessionService = SessionService;
exports.SessionService = SessionService = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [prisma_service_1.PrismaService])
], SessionService);
//# sourceMappingURL=session.service.js.map