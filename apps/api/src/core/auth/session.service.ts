import { createHash, randomBytes } from 'node:crypto';
import { Injectable } from '@nestjs/common';
import type { PermissionMap } from '@fluggi/contracts';
import { loadEnv } from '../../config/env';
import { PrismaService, type Tx } from '../prisma/prisma.service';
import type { AuthContext } from './auth-context';

/** Как часто продлевать скользящую сессию (не на каждый запрос). */
const TOUCH_INTERVAL_MS = 5 * 60 * 1000;

export const hashToken = (token: string) => createHash('sha256').update(token).digest('hex');

@Injectable()
export class SessionService {
  constructor(private readonly prisma: PrismaService) {}

  private ttlMs() {
    return loadEnv().SESSION_TTL_DAYS * 24 * 60 * 60 * 1000;
  }

  async create(
    userId: string,
    meta: { ip: string | null; userAgent: string | null },
    tx: Tx = this.prisma,
  ): Promise<{ token: string; sessionId: string; expiresAt: Date }> {
    const token = randomBytes(32).toString('base64url');
    const expiresAt = new Date(Date.now() + this.ttlMs());
    const session = await tx.session.create({
      data: { userId, tokenHash: hashToken(token), ip: meta.ip, userAgent: meta.userAgent, expiresAt },
    });
    return { token, sessionId: session.id, expiresAt };
  }

  /** Проверяет токен cookie и возвращает контекст пользователя или null. */
  async resolve(token: string): Promise<AuthContext | null> {
    const session = await this.prisma.session.findUnique({
      where: { tokenHash: hashToken(token) },
      include: {
        user: {
          include: {
            role: { include: { permissions: { include: { permission: true } } } },
            team: true,
            headedTeams: { where: { deletedAt: null }, select: { id: true } },
          },
        },
      },
    });
    const now = new Date();
    if (!session || session.revokedAt || session.expiresAt <= now) return null;
    const { user } = session;
    if (user.status !== 'ACTIVE' || user.deletedAt) return null;

    if (now.getTime() - session.lastSeenAt.getTime() > TOUCH_INTERVAL_MS) {
      await this.prisma.session.update({
        where: { id: session.id },
        data: { lastSeenAt: now, expiresAt: new Date(now.getTime() + this.ttlMs()) },
      });
    }

    const permissions: PermissionMap = {};
    for (const rp of user.role.permissions) {
      (permissions as Record<string, string>)[rp.permission.code] = rp.scope;
    }

    return {
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
      permissions,
      telegramLinked: user.telegramChatId !== null,
    };
  }

  revoke(sessionId: string, tx: Tx = this.prisma) {
    return tx.session.updateMany({
      where: { id: sessionId, revokedAt: null },
      data: { revokedAt: new Date() },
    });
  }

  revokeAllForUser(userId: string, exceptSessionId?: string, tx: Tx = this.prisma) {
    return tx.session.updateMany({
      where: { userId, revokedAt: null, ...(exceptSessionId ? { id: { not: exceptSessionId } } : {}) },
      data: { revokedAt: new Date() },
    });
  }
}
