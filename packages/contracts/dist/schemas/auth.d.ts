import { z } from 'zod';
import type { Locale, RoleCode } from '../enums';
import type { PermissionMap } from '../permissions';
export declare const PASSWORD_MIN_LENGTH = 10;
export declare const passwordSchema: z.ZodString;
export declare const emailSchema: z.ZodPipe<z.ZodString, z.ZodEmail>;
export declare const loginSchema: z.ZodObject<{
    email: z.ZodPipe<z.ZodString, z.ZodEmail>;
    password: z.ZodString;
}, z.core.$strip>;
export type LoginInput = z.infer<typeof loginSchema>;
export declare const changePasswordSchema: z.ZodObject<{
    currentPassword: z.ZodString;
    newPassword: z.ZodString;
}, z.core.$strip>;
export type ChangePasswordInput = z.infer<typeof changePasswordSchema>;
export interface MeResponse {
    id: string;
    email: string;
    fullName: string;
    locale: Locale;
    role: {
        code: RoleCode;
        name: string;
    };
    team: {
        id: string;
        name: string;
    } | null;
    /** Отделы, которыми руководит пользователь (для РОП). */
    headedTeamIds: string[];
    permissions: PermissionMap;
    telegramLinked: boolean;
}
export interface SessionInfo {
    id: string;
    ip: string | null;
    userAgent: string | null;
    createdAt: string;
    lastSeenAt: string;
    current: boolean;
}
