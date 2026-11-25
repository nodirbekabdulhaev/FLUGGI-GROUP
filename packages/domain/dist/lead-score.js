"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.SCORE_WEIGHTS = void 0;
exports.scoreLevel = scoreLevel;
exports.computeLeadScore = computeLeadScore;
exports.SCORE_WEIGHTS = {
    budget: 25,
    urgency: 15,
    serviceFit: 10,
    companySize: 10,
    interest: 20,
    stage: 20,
};
const PRIORITY_URGENCY = {
    LOW: 0.2,
    MEDIUM: 0.5,
    HIGH: 0.8,
    URGENT: 1,
};
const SIZE = { SOLO: 0.25, SMALL: 0.5, MEDIUM: 0.8, LARGE: 1 };
const clamp01 = (v) => Math.min(1, Math.max(0, v));
function scoreLevel(score) {
    if (score > 80)
        return 'HOT';
    if (score > 60)
        return 'HIGH';
    if (score > 30)
        return 'MEDIUM';
    return 'LOW';
}
function computeLeadScore(input) {
    const factors = {};
    if (input.budgetUzs != null && input.budgetUzs > 0) {
        // Бюджет относительно минимальной цены услуги; без каталожной цены — шкала до 50 млн UZS.
        const ref = input.serviceMinPriceUzs && input.serviceMinPriceUzs > 0
            ? input.serviceMinPriceUzs * 2
            : 50_000_000;
        factors.budget = clamp01(input.budgetUzs / ref);
    }
    let urgency = PRIORITY_URGENCY[input.priority];
    if (input.daysToDesiredDate != null) {
        const byDate = input.daysToDesiredDate <= 14
            ? 1
            : input.daysToDesiredDate <= 45
                ? 0.7
                : input.daysToDesiredDate <= 90
                    ? 0.4
                    : 0.2;
        urgency = Math.max(urgency, byDate);
    }
    factors.urgency = urgency;
    factors.serviceFit = input.hasService ? 1 : 0.3;
    if (input.companySize)
        factors.companySize = SIZE[input.companySize];
    if (input.interest != null)
        factors.interest = clamp01((input.interest - 1) / 4);
    factors.stage = input.stageCount > 1 ? clamp01(input.stageIndex / (input.stageCount - 1)) : 0;
    let weighted = 0;
    let weights = 0;
    for (const [key, value] of Object.entries(factors)) {
        const w = exports.SCORE_WEIGHTS[key];
        weighted += w * value;
        weights += w;
    }
    const score = weights > 0 ? Math.round((weighted / weights) * 100) : 0;
    return { score, level: scoreLevel(score), factors };
}
//# sourceMappingURL=lead-score.js.map