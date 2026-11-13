import { formatNumber, type DealDto } from '@fluggi/contracts';
import type { Prisma } from '@fluggi/db';
import { dateOnly, dec, decReq, iso } from '../../core/http/serialize';

export const dealInclude = {
  client: { select: { id: true, name: true } },
  contact: { select: { id: true, fullName: true } },
  owner: { select: { id: true, fullName: true } },
  team: { select: { id: true, name: true } },
  service: { select: { id: true, nameRu: true } },
  stage: true,
  lossReason: true,
  lead: { select: { id: true } },
} satisfies Prisma.DealInclude;

export type DealRow = Prisma.DealGetPayload<{ include: typeof dealInclude }>;

export function toDealDto(d: DealRow): DealDto {
  return {
    id: d.id,
    number: formatNumber('D', d.number),
    title: d.title,
    client: { id: d.client.id, name: d.client.name },
    contact: d.contact ? { id: d.contact.id, name: d.contact.fullName } : null,
    owner: { id: d.owner.id, name: d.owner.fullName },
    team: d.team,
    service: d.service ? { id: d.service.id, name: d.service.nameRu } : null,
    amount: decReq(d.amount),
    currency: d.currency,
    exchangeRate: d.exchangeRate.toString(),
    amountUzs: decReq(d.amountUzs),
    stage: { id: d.stage.id, code: d.stage.code, name: d.stage.nameRu, color: d.stage.color },
    status: d.status,
    probability: d.probabilityOverride ?? d.stage.probability,
    probabilityOverride: d.probabilityOverride,
    expectedCloseDate: dateOnly(d.expectedCloseDate),
    isRepeat: d.isRepeat,
    leadId: d.lead?.id ?? null,
    lossReason: d.lossReason ? { id: d.lossReason.id, name: d.lossReason.nameRu } : null,
    lossComment: d.lossComment,
    wonAt: iso(d.wonAt),
    closedAt: iso(d.closedAt),
    createdAt: d.createdAt.toISOString(),
    updatedAt: d.updatedAt.toISOString(),
  };
}

export { dec };
