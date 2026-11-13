import { z } from 'zod';
import type { ExecutorSpecialty, Locale, RoleCode, UserStatus } from '../enums';
export declare const createUserSchema: z.ZodObject<{
    email: z.ZodPipe<z.ZodString, z.ZodEmail>;
    fullName: z.ZodString;
    phone: z.ZodUnion<[z.ZodOptional<z.ZodString>, z.ZodPipe<z.ZodLiteral<"">, z.ZodTransform<undefined, "">>]>;
    roleCode: z.ZodEnum<{
        CEO: "CEO";
        ROP: "ROP";
        MANAGER: "MANAGER";
        EXECUTOR: "EXECUTOR";
        HR_ADMIN: "HR_ADMIN";
        PROJECT_MANAGER: "PROJECT_MANAGER";
    }>;
    teamId: z.ZodOptional<z.ZodNullable<z.ZodUUID>>;
    position: z.ZodPipe<z.ZodOptional<z.ZodString>, z.ZodTransform<string | undefined, string | undefined>>;
    specialty: z.ZodOptional<z.ZodNullable<z.ZodEnum<{
        SMM: "SMM";
        DESIGNER: "DESIGNER";
        VIDEOGRAPHER: "VIDEOGRAPHER";
        EDITOR: "EDITOR";
        TARGETOLOGIST: "TARGETOLOGIST";
        DEVELOPER: "DEVELOPER";
        PHOTOGRAPHER: "PHOTOGRAPHER";
        COPYWRITER: "COPYWRITER";
        MOBILOGRAPHER: "MOBILOGRAPHER";
        BRANDFACE: "BRANDFACE";
    }>>>;
    locale: z.ZodDefault<z.ZodEnum<{
        ru: "ru";
        uz: "uz";
        en: "en";
    }>>;
    directionIds: z.ZodDefault<z.ZodArray<z.ZodUUID>>;
    password: z.ZodOptional<z.ZodString>;
}, z.core.$strip>;
export type CreateUserInput = z.input<typeof createUserSchema>;
export declare const updateUserSchema: z.ZodObject<{
    email: z.ZodOptional<z.ZodPipe<z.ZodString, z.ZodEmail>>;
    fullName: z.ZodOptional<z.ZodString>;
    phone: z.ZodOptional<z.ZodUnion<[z.ZodNullable<z.ZodString>, z.ZodPipe<z.ZodLiteral<"">, z.ZodTransform<null, "">>]>>;
    roleCode: z.ZodOptional<z.ZodEnum<{
        CEO: "CEO";
        ROP: "ROP";
        MANAGER: "MANAGER";
        EXECUTOR: "EXECUTOR";
        HR_ADMIN: "HR_ADMIN";
        PROJECT_MANAGER: "PROJECT_MANAGER";
    }>>;
    teamId: z.ZodOptional<z.ZodNullable<z.ZodUUID>>;
    position: z.ZodOptional<z.ZodPipe<z.ZodNullable<z.ZodString>, z.ZodTransform<string | null, string | null>>>;
    specialty: z.ZodOptional<z.ZodNullable<z.ZodEnum<{
        SMM: "SMM";
        DESIGNER: "DESIGNER";
        VIDEOGRAPHER: "VIDEOGRAPHER";
        EDITOR: "EDITOR";
        TARGETOLOGIST: "TARGETOLOGIST";
        DEVELOPER: "DEVELOPER";
        PHOTOGRAPHER: "PHOTOGRAPHER";
        COPYWRITER: "COPYWRITER";
        MOBILOGRAPHER: "MOBILOGRAPHER";
        BRANDFACE: "BRANDFACE";
    }>>>;
    locale: z.ZodOptional<z.ZodEnum<{
        ru: "ru";
        uz: "uz";
        en: "en";
    }>>;
    directionIds: z.ZodOptional<z.ZodArray<z.ZodUUID>>;
}, z.core.$strip>;
export type UpdateUserInput = z.input<typeof updateUserSchema>;
export declare const userListQuerySchema: z.ZodObject<{
    page: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
    pageSize: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
    q: z.ZodOptional<z.ZodString>;
    roleCode: z.ZodOptional<z.ZodEnum<{
        CEO: "CEO";
        ROP: "ROP";
        MANAGER: "MANAGER";
        EXECUTOR: "EXECUTOR";
        HR_ADMIN: "HR_ADMIN";
        PROJECT_MANAGER: "PROJECT_MANAGER";
    }>>;
    teamId: z.ZodOptional<z.ZodUUID>;
    status: z.ZodOptional<z.ZodEnum<{
        ACTIVE: "ACTIVE";
        BLOCKED: "BLOCKED";
    }>>;
}, z.core.$strip>;
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
    role: {
        code: RoleCode;
        name: string;
    };
    team: {
        id: string;
        name: string;
    } | null;
    /** Направления бизнеса сотрудника */
    directions: {
        id: string;
        name: string;
    }[];
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
export declare const deleteUserSchema: z.ZodObject<{
    transferToId: z.ZodOptional<z.ZodNullable<z.ZodUUID>>;
}, z.core.$strip>;
export type DeleteUserInput = z.input<typeof deleteUserSchema>;
