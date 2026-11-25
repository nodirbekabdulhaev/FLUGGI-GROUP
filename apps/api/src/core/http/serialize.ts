import type { Prisma } from '@fluggi/db';

/** Decimal → строка для API (никаких float в деньгах). */
export const dec = (v: Prisma.Decimal | null | undefined): string | null =>
  v == null ? null : v.toFixed(2);
export const decReq = (v: Prisma.Decimal): string => v.toFixed(2);
export const iso = (d: Date | null | undefined): string | null => (d ? d.toISOString() : null);
export const dateOnly = (d: Date | null | undefined): string | null =>
  d ? d.toISOString().slice(0, 10) : null;

/** YYYY-MM-DD → Date (UTC-полночь, для колонок @db.Date). */
export const parseDate = (v: string | null | undefined): Date | null | undefined =>
  v === undefined ? undefined : v === null ? null : new Date(`${v}T00:00:00Z`);

export const named = (
  r: { id: string; fullName?: string; name?: string; nameRu?: string } | null | undefined,
) => (r ? { id: r.id, name: r.fullName ?? r.nameRu ?? r.name ?? '' } : null);
