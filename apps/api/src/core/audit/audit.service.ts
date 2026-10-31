import { Injectable } from '@nestjs/common';
import { Prisma } from '@fluggi/db';
import type { AuditChange } from '@fluggi/contracts';
import type { RequestMeta } from '../auth/auth-context';
import type { Tx } from '../prisma/prisma.service';

export interface AuditEntry {
  actorId: string | null;
  action: string;
  entityType: string;
  entityId?: string | null;
  changes?: Record<string, AuditChange> | null;
  meta?: RequestMeta;
}

/** Сравнивает значения выбранных полей и возвращает только изменившиеся. */
export function diffFields<T extends Record<string, unknown>>(
  before: T,
  after: Partial<T>,
  fields: (keyof T)[],
): Record<string, AuditChange> | null {
  const changes: Record<string, AuditChange> = {};
  for (const field of fields) {
    if (!(field in after)) continue;
    const oldValue = normalize(before[field]);
    const newValue = normalize(after[field]);
    if (JSON.stringify(oldValue) !== JSON.stringify(newValue)) {
      changes[String(field)] = { old: oldValue, new: newValue };
    }
  }
  return Object.keys(changes).length > 0 ? changes : null;
}

function normalize(v: unknown): unknown {
  if (v === undefined) return null;
  if (v instanceof Date) return v.toISOString();
  if (v instanceof Prisma.Decimal) return v.toString();
  return v;
}

/**
 * Журнал аудита (ТЗ §45). Пишется в той же транзакции, что и изменение,
 * поэтому изменение без записи в журнал невозможно. Таблица append-only.
 */
@Injectable()
export class AuditService {
  async log(tx: Tx, entry: AuditEntry): Promise<void> {
    await tx.auditLog.create({
      data: {
        actorId: entry.actorId,
        action: entry.action,
        entityType: entry.entityType,
        entityId: entry.entityId ?? null,
        changes: (entry.changes ?? undefined) as Prisma.InputJsonValue | undefined,
        ip: entry.meta?.ip ?? null,
        userAgent: entry.meta?.userAgent ?? null,
        sessionId: entry.meta?.sessionId ?? null,
      },
    });
  }
}
