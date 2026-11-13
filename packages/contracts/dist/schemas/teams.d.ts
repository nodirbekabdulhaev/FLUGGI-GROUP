import { z } from 'zod';
export declare const createTeamSchema: z.ZodObject<{
    name: z.ZodString;
    headId: z.ZodOptional<z.ZodNullable<z.ZodUUID>>;
}, z.core.$strip>;
export type CreateTeamInput = z.input<typeof createTeamSchema>;
export declare const updateTeamSchema: z.ZodObject<{
    name: z.ZodOptional<z.ZodString>;
    headId: z.ZodOptional<z.ZodOptional<z.ZodNullable<z.ZodUUID>>>;
}, z.core.$strip>;
export type UpdateTeamInput = z.input<typeof updateTeamSchema>;
export interface TeamDto {
    id: string;
    name: string;
    head: {
        id: string;
        fullName: string;
    } | null;
    membersCount: number;
    createdAt: string;
}
