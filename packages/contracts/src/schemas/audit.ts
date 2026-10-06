import { z } from 'zod';
import { paginationQuerySchema } from './common';

export const auditListQuerySchema = paginationQuerySchema.extend({
  actorId: z.uuid().optional(),
  entityType: z.string().trim().max(50).optional(),
  entityId: z.uuid().optional(),
  dateFrom: z.iso.datetime({ offset: true }).optional(),
  dateTo: z.iso.datetime({ offset: true }).optional(),
});
export type AuditListQuery = z.input<typeof auditListQuerySchema>;

export interface AuditChange {
  old: unknown;
  new: unknown;
}

export interface AuditLogDto {
  id: string;
  actor: { id: string; fullName: string } | null;
  action: string;
  entityType: string;
  entityId: string | null;
  changes: Record<string, AuditChange> | null;
  ip: string | null;
  createdAt: string;
}
