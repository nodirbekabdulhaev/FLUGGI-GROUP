import { z } from 'zod';
export declare const auditListQuerySchema: z.ZodObject<{
    page: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
    pageSize: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
    actorId: z.ZodOptional<z.ZodUUID>;
    entityType: z.ZodOptional<z.ZodString>;
    entityId: z.ZodOptional<z.ZodUUID>;
    dateFrom: z.ZodOptional<z.ZodISODateTime>;
    dateTo: z.ZodOptional<z.ZodISODateTime>;
}, z.core.$strip>;
export type AuditListQuery = Partial<z.output<typeof auditListQuerySchema>>;
export interface AuditChange {
    old: unknown;
    new: unknown;
}
export interface AuditLogDto {
    id: string;
    actor: {
        id: string;
        fullName: string;
    } | null;
    action: string;
    entityType: string;
    entityId: string | null;
    changes: Record<string, AuditChange> | null;
    ip: string | null;
    createdAt: string;
}
