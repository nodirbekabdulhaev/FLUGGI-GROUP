import { z } from 'zod';
import type { Locale, RoleCode } from '../enums';
import type { PermissionMap } from '../permissions';

export const PASSWORD_MIN_LENGTH = 10;

export const passwordSchema = z
  .string()
  .min(PASSWORD_MIN_LENGTH, `Минимум ${PASSWORD_MIN_LENGTH} символов`)
  .max(128, 'Максимум 128 символов')
  .refine((v) => /[A-Za-zА-Яа-я]/.test(v) && /\d/.test(v), 'Пароль должен содержать буквы и цифры');

export const emailSchema = z
  .string()
  .trim()
  .toLowerCase()
  .pipe(z.email('Некорректный email').max(254));

export const loginSchema = z.object({
  email: emailSchema,
  password: z.string().min(1, 'Введите пароль').max(128),
});
export type LoginInput = z.infer<typeof loginSchema>;

export const changePasswordSchema = z
  .object({
    currentPassword: z.string().min(1, 'Введите текущий пароль').max(128),
    newPassword: passwordSchema,
  })
  .refine((v) => v.currentPassword !== v.newPassword, {
    path: ['newPassword'],
    message: 'Новый пароль должен отличаться от текущего',
  });
export type ChangePasswordInput = z.infer<typeof changePasswordSchema>;

export interface MeResponse {
  id: string;
  email: string;
  fullName: string;
  locale: Locale;
  role: { code: RoleCode; name: string };
  team: { id: string; name: string } | null;
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
