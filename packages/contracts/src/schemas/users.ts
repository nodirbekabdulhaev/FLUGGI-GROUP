import { z } from 'zod';
import { EXECUTOR_SPECIALTIES, LOCALES, ROLE_CODES, USER_STATUSES } from '../enums';
import type { ExecutorSpecialty, Locale, RoleCode, UserStatus } from '../enums';
import { emailSchema, passwordSchema } from './auth';
import { paginationQuerySchema } from './common';

const phoneSchema = z
  .string()
  .trim()
  .regex(/^\+?[0-9\s\-()]{7,20}$/, 'Некорректный телефон');

const optionalText = (max: number) =>
  z
    .string()
    .trim()
    .max(max)
    .optional()
    .transform((v) => (v === '' ? undefined : v));

export const createUserSchema = z.object({
  email: emailSchema,
  fullName: z.string().trim().min(2, 'Укажите имя').max(120),
  phone: phoneSchema.optional().or(z.literal('').transform(() => undefined)),
  roleCode: z.enum(ROLE_CODES, 'Выберите роль'),
  teamId: z.uuid().nullish(),
  position: optionalText(120),
  specialty: z.enum(EXECUTOR_SPECIALTIES).nullish(),
  locale: z.enum(LOCALES).default('ru'),
  /** Направления бизнеса (проект-менеджер видит проекты этих направлений) */
  directionIds: z.array(z.uuid()).max(20).default([]),
  /** Если не указан — сервер сгенерирует временный пароль и вернёт его один раз. */
  password: passwordSchema.optional(),
});
export type CreateUserInput = z.input<typeof createUserSchema>;

export const updateUserSchema = z
  .object({
    email: emailSchema,
    fullName: z.string().trim().min(2, 'Укажите имя').max(120),
    phone: phoneSchema.nullable().or(z.literal('').transform(() => null)),
    roleCode: z.enum(ROLE_CODES),
    teamId: z.uuid().nullable(),
    position: z
      .string()
      .trim()
      .max(120)
      .nullable()
      .transform((v) => (v === '' ? null : v)),
    specialty: z.enum(EXECUTOR_SPECIALTIES).nullable(),
    locale: z.enum(LOCALES),
    directionIds: z.array(z.uuid()).max(20),
  })
  .partial()
  .refine((v) => Object.values(v).some((x) => x !== undefined), 'Нет изменений');
export type UpdateUserInput = z.input<typeof updateUserSchema>;

export const userListQuerySchema = paginationQuerySchema.extend({
  q: z.string().trim().max(100).optional(),
  roleCode: z.enum(ROLE_CODES).optional(),
  teamId: z.uuid().optional(),
  status: z.enum(USER_STATUSES).optional(),
});
export type UserListQuery = Partial<z.output<typeof userListQuerySchema>>;

export interface UserDto {
  id: string;
  email: string;
  fullName: string;
  phone: string | null;
  status: UserStatus;
  locale: Locale;
  position: string | null;
  specialty: ExecutorSpecialty | null;
  role: { code: RoleCode; name: string };
  team: { id: string; name: string } | null;
  /** Направления бизнеса сотрудника */
  directions: { id: string; name: string }[];
  telegramLinked: boolean;
  lastLoginAt: string | null;
  createdAt: string;
}

export interface CreateUserResponse {
  user: UserDto;
  /** Возвращается только если пароль сгенерирован сервером. Показать один раз. */
  temporaryPassword?: string;
}

/** Открытая работа сотрудника — перед удалением её нужно передать другому. */
export interface UserWorkloadDto {
  leads: number;
  deals: number;
  clients: number;
  projects: number;
  tasks: number;
  todos: number;
  threads: number;
  total: number;
}

export const deleteUserSchema = z.object({
  /** Кому передать открытые лиды, сделки, клиентов, проекты, задачи и переписки */
  transferToId: z.uuid().nullish(),
});
export type DeleteUserInput = z.input<typeof deleteUserSchema>;
