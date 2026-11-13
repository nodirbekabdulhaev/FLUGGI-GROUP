import type { Locale, PermissionMap, RoleCode } from '@fluggi/contracts';
import type { Request } from 'express';

/** Аутентифицированный пользователь текущего запроса. */
export interface AuthContext {
  sessionId: string;
  userId: string;
  email: string;
  fullName: string;
  locale: Locale;
  roleCode: RoleCode;
  roleName: string;
  teamId: string | null;
  teamName: string | null;
  headedTeamIds: string[];
  /** Направления бизнеса, за которые отвечает сотрудник (проект-менеджер) */
  directionIds: string[];
  permissions: PermissionMap;
  telegramLinked: boolean;
}

export interface AppRequest extends Request {
  auth?: AuthContext;
}

/** Метаданные запроса для аудита. */
export interface RequestMeta {
  ip: string | null;
  userAgent: string | null;
  sessionId: string | null;
}

export function requestMeta(req: AppRequest): RequestMeta {
  return {
    ip: req.ip ?? null,
    userAgent: req.get('user-agent')?.slice(0, 500) ?? null,
    sessionId: req.auth?.sessionId ?? null,
  };
}
