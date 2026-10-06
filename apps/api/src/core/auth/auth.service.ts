import { Injectable } from '@nestjs/common';
import type { ChangePasswordInput, LoginInput, MeResponse, SessionInfo } from '@fluggi/contracts';
import { AuditService } from '../audit/audit.service';
import { AppException } from '../http/app.exception';
import { PrismaService } from '../prisma/prisma.service';
import type { AuthContext, RequestMeta } from './auth-context';
import { PasswordService } from './password.service';
import { SessionService } from './session.service';

export const MAX_FAILED_LOGINS = 5;
export const LOCK_MINUTES = 15;

const INVALID_CREDENTIALS = 'Неверный email или пароль';

@Injectable()
export class AuthService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly passwords: PasswordService,
    private readonly sessions: SessionService,
    private readonly audit: AuditService,
  ) {}

  async login(input: LoginInput, meta: RequestMeta) {
    const user = await this.prisma.user.findUnique({ where: { email: input.email } });
    const usable = user && !user.deletedAt ? user : null;

    if (usable?.lockedUntil && usable.lockedUntil > new Date()) {
      throw new AppException(
        'RATE_LIMITED',
        `Слишком много неудачных попыток. Попробуйте через ${LOCK_MINUTES} минут`,
      );
    }

    const valid = await this.passwords.verify(usable?.passwordHash ?? null, input.password);

    if (!usable || !valid) {
      if (usable) {
        const failed = usable.failedLoginCount + 1;
        const lock = failed >= MAX_FAILED_LOGINS;
        await this.prisma.$transaction(async (tx) => {
          await tx.user.update({
            where: { id: usable.id },
            data: {
              failedLoginCount: lock ? 0 : failed,
              lockedUntil: lock ? new Date(Date.now() + LOCK_MINUTES * 60_000) : undefined,
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
      throw new AppException('UNAUTHENTICATED', INVALID_CREDENTIALS);
    }

    if (usable.status !== 'ACTIVE') {
      throw new AppException('FORBIDDEN', 'Учётная запись заблокирована. Обратитесь к администратору');
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

  async logout(auth: AuthContext, meta: RequestMeta) {
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

  async changePassword(auth: AuthContext, input: ChangePasswordInput, meta: RequestMeta) {
    const user = await this.prisma.user.findUniqueOrThrow({ where: { id: auth.userId } });
    if (!(await this.passwords.verify(user.passwordHash, input.currentPassword))) {
      throw new AppException('VALIDATION_ERROR', 'Текущий пароль указан неверно', [
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

  async listSessions(auth: AuthContext): Promise<SessionInfo[]> {
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

  async revokeSession(auth: AuthContext, sessionId: string, meta: RequestMeta) {
    await this.prisma.$transaction(async (tx) => {
      const { count } = await tx.session.updateMany({
        where: { id: sessionId, userId: auth.userId, revokedAt: null },
        data: { revokedAt: new Date() },
      });
      if (count === 0) throw new AppException('NOT_FOUND', 'Сессия не найдена');
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: 'auth.session_revoked',
        entityType: 'session',
        entityId: sessionId,
        meta,
      });
    });
  }

  toMe(auth: AuthContext): MeResponse {
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
}
