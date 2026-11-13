"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.toProposalDto = exports.proposalInclude = void 0;
const contracts_1 = require("@fluggi/contracts");
const serialize_1 = require("../../core/http/serialize");
exports.proposalInclude = {
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
};
const toProposalDto = (p) => ({
    id: p.id,
    number: (0, contracts_1.formatNumber)('KP', p.number),
    title: p.title,
    description: p.description,
    deal: { id: p.deal.id, name: p.deal.title, number: (0, contracts_1.formatNumber)('D', p.deal.number) },
    client: p.client,
    manager: { id: p.manager.id, name: p.manager.fullName },
    status: p.status,
    currency: p.currency,
    subtotal: (0, serialize_1.decReq)(p.subtotal),
    discountAmount: (0, serialize_1.decReq)(p.discountAmount),
    total: (0, serialize_1.decReq)(p.total),
    totalUzs: (0, serialize_1.decReq)(p.totalUzs),
    implementationTerm: p.implementationTerm,
    paymentTerms: p.paymentTerms,
    validUntil: (0, serialize_1.dateOnly)(p.validUntil),
    currentVersion: p.currentVersion,
    approvedBy: p.approvedBy ? { id: p.approvedBy.id, name: p.approvedBy.fullName } : null,
    approvedAt: (0, serialize_1.iso)(p.approvedAt),
    sentAt: (0, serialize_1.iso)(p.sentAt),
    viewedAt: (0, serialize_1.iso)(p.viewedAt),
    acceptedAt: (0, serialize_1.iso)(p.acceptedAt),
    rejectedAt: (0, serialize_1.iso)(p.rejectedAt),
    createdAt: p.createdAt.toISOString(),
    updatedAt: p.updatedAt.toISOString(),
    items: p.items.map((i) => ({
        id: i.id,
        service: i.service ? { id: i.service.id, name: i.service.nameRu } : null,
        tariff: i.tariff ? { id: i.tariff.id, name: i.tariff.name } : null,
        description: i.description,
        quantity: i.quantity.toString(),
        unitPrice: (0, serialize_1.decReq)(i.unitPrice),
        discountPct: i.discountPct.toString(),
        total: (0, serialize_1.decReq)(i.total),
    })),
});
exports.toProposalDto = toProposalDto;
//# sourceMappingURL=proposals.mapper.js.map