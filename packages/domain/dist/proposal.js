"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.proposalTotals = proposalTotals;
const decimal_js_1 = __importDefault(require("decimal.js"));
/**
 * Итоги КП (ТЗ §15): по строке gross = кол-во × цена, скидка = gross × % / 100,
 * итог = gross − скидка. Округление до 2 знаков на каждой строке.
 */
function proposalTotals(lines) {
    const out = lines.map((l) => {
        const gross = new decimal_js_1.default(l.quantity).mul(l.unitPrice).toDecimalPlaces(2);
        const pct = decimal_js_1.default.min(100, decimal_js_1.default.max(0, new decimal_js_1.default(l.discountPct ?? 0)));
        const discount = gross.mul(pct).div(100).toDecimalPlaces(2);
        return { gross, discount, total: gross.sub(discount) };
    });
    const subtotal = out.reduce((a, l) => a.add(l.gross), new decimal_js_1.default(0));
    const discountAmount = out.reduce((a, l) => a.add(l.discount), new decimal_js_1.default(0));
    return { lines: out, subtotal, discountAmount, total: subtotal.sub(discountAmount) };
}
//# sourceMappingURL=proposal.js.map