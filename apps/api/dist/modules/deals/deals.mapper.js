"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.dec = exports.dealInclude = void 0;
exports.toDealDto = toDealDto;
const contracts_1 = require("@fluggi/contracts");
const serialize_1 = require("../../core/http/serialize");
Object.defineProperty(exports, "dec", { enumerable: true, get: function () { return serialize_1.dec; } });
exports.dealInclude = {
    client: { select: { id: true, name: true } },
    contact: { select: { id: true, fullName: true } },
    owner: { select: { id: true, fullName: true } },
    team: { select: { id: true, name: true } },
    service: { select: { id: true, nameRu: true } },
    stage: true,
    lossReason: true,
    lead: { select: { id: true } },
};
function toDealDto(d) {
    return {
        id: d.id,
        number: (0, contracts_1.formatNumber)('D', d.number),
        title: d.title,
        client: { id: d.client.id, name: d.client.name },
        contact: d.contact ? { id: d.contact.id, name: d.contact.fullName } : null,
        owner: { id: d.owner.id, name: d.owner.fullName },
        team: d.team,
        service: d.service ? { id: d.service.id, name: d.service.nameRu } : null,
        amount: (0, serialize_1.decReq)(d.amount),
        currency: d.currency,
        exchangeRate: d.exchangeRate.toString(),
        amountUzs: (0, serialize_1.decReq)(d.amountUzs),
        stage: { id: d.stage.id, code: d.stage.code, name: d.stage.nameRu, color: d.stage.color },
        status: d.status,
        probability: d.probabilityOverride ?? d.stage.probability,
        probabilityOverride: d.probabilityOverride,
        expectedCloseDate: (0, serialize_1.dateOnly)(d.expectedCloseDate),
        isRepeat: d.isRepeat,
        leadId: d.lead?.id ?? null,
        lossReason: d.lossReason ? { id: d.lossReason.id, name: d.lossReason.nameRu } : null,
        lossComment: d.lossComment,
        wonAt: (0, serialize_1.iso)(d.wonAt),
        closedAt: (0, serialize_1.iso)(d.closedAt),
        createdAt: d.createdAt.toISOString(),
        updatedAt: d.updatedAt.toISOString(),
    };
}
//# sourceMappingURL=deals.mapper.js.map