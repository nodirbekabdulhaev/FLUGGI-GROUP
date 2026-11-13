import { z } from 'zod';
export declare const idSchema: z.ZodUUID;
export declare const paginationQuerySchema: z.ZodObject<{
    page: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
    pageSize: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
}, z.core.$strip>;
export type PaginationQuery = z.output<typeof paginationQuerySchema>;
export interface Paginated<T> {
    items: T[];
    total: number;
    page: number;
    pageSize: number;
}
export declare const ERROR_CODES: readonly ["UNAUTHENTICATED", "FORBIDDEN", "NOT_FOUND", "CONFLICT", "VALIDATION_ERROR", "BUSINESS_RULE_VIOLATION", "RATE_LIMITED", "CSRF_INVALID", "INTERNAL"];
export type ErrorCode = (typeof ERROR_CODES)[number];
export interface ApiErrorDetail {
    path: string;
    message: string;
}
export interface ApiErrorBody {
    error: {
        code: ErrorCode;
        message: string;
        details?: ApiErrorDetail[];
        requestId?: string;
    };
}
