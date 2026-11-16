import { formatNumber, type LeadDto } from '@fluggi/contracts';
import type { Prisma } from '@fluggi/db';
import { dateOnly, dec, iso } from '../../core/http/serialize';

export const leadInclude = {
  source: true,
  owner: { select: { id: true, fullName: true } },
  team: { select: { id: true, name: true } },
  service: true,
  stage: true,
  lossReason: true,
} satisfies Prisma.LeadInclude;

export type LeadRow = Prisma.LeadGetPayload<{ include: typeof leadInclude }>;

export function toLeadDto(l: LeadRow): LeadDto {
  return {
    id: l.id,
    number: formatNumber('L', l.number),
    title: l.title,
    contactName: l.contactName,
    companyName: l.companyName,
    phone: l.phone,
    telegram: l.telegram,
    whatsapp: l.whatsapp,
    instagram: l.instagram,
    email: l.email,
    website: l.website,
    city: l.city,
    country: l.country,
    source: { id: l.source.id, name: l.source.nameRu },
    owner: { id: l.owner.id, name: l.owner.fullName },
    team: l.team,
    service: l.service ? { id: l.service.id, name: l.service.nameRu } : null,
    budget: dec(l.budget),
    currency: l.currency,
    budgetUzs: dec(l.budgetUzs),
    desiredDate: dateOnly(l.desiredDate),
    priority: l.priority,
    companySize: l.companySize,
    interest: l.interest,
    stage: { id: l.stage.id, code: l.stage.code, name: l.stage.nameRu, color: l.stage.color },
    status: l.status,
    score: l.score,
    scoreLevel: l.scoreLevel,
    nextContactAt: iso(l.nextContactAt),
    lastContactAt: iso(l.lastContactAt),
    comment: l.comment,
    clientId: l.clientId,
    dealId: l.dealId,
    lossReason: l.lossReason ? { id: l.lossReason.id, name: l.lossReason.nameRu } : null,
    lossComment: l.lossComment,
    createdAt: l.createdAt.toISOString(),
    updatedAt: l.updatedAt.toISOString(),
  };
}
