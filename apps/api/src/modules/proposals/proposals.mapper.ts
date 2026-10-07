import { formatNumber, type ProposalDto } from '@fluggi/contracts';
import type { Prisma } from '@fluggi/db';
import { dateOnly, decReq, iso } from '../../core/http/serialize';

export const proposalInclude = {
  deal: { select: { id: true, number: true, title: true } },
  client: { select: { id: true, name: true } },
  manager: { select: { id: true, fullName: true } },
  approvedBy: { select: { id: true, fullName: true } },
  items: {
    include: {
      service: { select: { id: true, nameRu: true } },
      tariff: { select: { id: true, name: true } },
    },
    orderBy: { sort: 'asc' },
  },
} satisfies Prisma.ProposalInclude;

export type ProposalRow = Prisma.ProposalGetPayload<{ include: typeof proposalInclude }>;

export const toProposalDto = (p: ProposalRow): ProposalDto => ({
  id: p.id,
  number: formatNumber('KP', p.number),
  title: p.title,
  description: p.description,
  deal: { id: p.deal.id, name: p.deal.title, number: formatNumber('D', p.deal.number) },
  client: p.client,
  manager: { id: p.manager.id, name: p.manager.fullName },
  status: p.status,
  currency: p.currency,
  subtotal: decReq(p.subtotal),
  discountAmount: decReq(p.discountAmount),
  total: decReq(p.total),
  totalUzs: decReq(p.totalUzs),
  implementationTerm: p.implementationTerm,
  paymentTerms: p.paymentTerms,
  validUntil: dateOnly(p.validUntil),
  currentVersion: p.currentVersion,
  approvedBy: p.approvedBy ? { id: p.approvedBy.id, name: p.approvedBy.fullName } : null,
  approvedAt: iso(p.approvedAt),
  sentAt: iso(p.sentAt),
  viewedAt: iso(p.viewedAt),
  acceptedAt: iso(p.acceptedAt),
  rejectedAt: iso(p.rejectedAt),
  createdAt: p.createdAt.toISOString(),
  updatedAt: p.updatedAt.toISOString(),
  items: p.items.map((i) => ({
    id: i.id,
    service: i.service ? { id: i.service.id, name: i.service.nameRu } : null,
    tariff: i.tariff ? { id: i.tariff.id, name: i.tariff.name } : null,
    description: i.description,
    quantity: i.quantity.toString(),
    unitPrice: decReq(i.unitPrice),
    discountPct: i.discountPct.toString(),
    total: decReq(i.total),
  })),
});
