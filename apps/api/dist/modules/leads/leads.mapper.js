"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.leadInclude = void 0;
exports.toLeadDto = toLeadDto;
const contracts_1 = require("@fluggi/contracts");
const serialize_1 = require("../../core/http/serialize");
exports.leadInclude = {
    source: true,
    owner: { select: { id: true, fullName: true } },
    team: { select: { id: true, name: true } },
    service: true,
    stage: true,
    lossReason: true,
};
function toLeadDto(l) {
    return {
        id: l.id,
        number: (0, contracts_1.formatNumber)('L', l.number),
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
        budget: (0, serialize_1.dec)(l.budget),
        currency: l.currency,
        budgetUzs: (0, serialize_1.dec)(l.budgetUzs),
        desiredDate: (0, serialize_1.dateOnly)(l.desiredDate),
        priority: l.priority,
        companySize: l.companySize,
        interest: l.interest,
        stage: { id: l.stage.id, code: l.stage.code, name: l.stage.nameRu, color: l.stage.color },
        status: l.status,
        score: l.score,
        scoreLevel: l.scoreLevel,
        nextContactAt: (0, serialize_1.iso)(l.nextContactAt),
        lastContactAt: (0, serialize_1.iso)(l.lastContactAt),
        comment: l.comment,
        clientId: l.clientId,
        dealId: l.dealId,
        lossReason: l.lossReason ? { id: l.lossReason.id, name: l.lossReason.nameRu } : null,
        lossComment: l.lossComment,
        createdAt: l.createdAt.toISOString(),
        updatedAt: l.updatedAt.toISOString(),
    };
}
//# sourceMappingURL=leads.mapper.js.map