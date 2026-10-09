"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.forecast = forecast;
exports.conversion = conversion;
const decimal_js_1 = __importDefault(require("decimal.js"));
/** Pipeline и взвешенный прогноз (ТЗ §40): Σ amount × probability. */
function forecast(deals) {
    let pipeline = new decimal_js_1.default(0);
    let weighted = new decimal_js_1.default(0);
    for (const d of deals) {
        const amount = new decimal_js_1.default(d.amountUzs);
        pipeline = pipeline.add(amount);
        weighted = weighted.add(amount.mul(Math.min(100, Math.max(0, d.probability))).div(100));
    }
    return { pipeline: pipeline.toDecimalPlaces(2), weighted: weighted.toDecimalPlaces(2) };
}
/** Конверсия, % (0 при пустом знаменателе). */
function conversion(converted, total) {
    return total > 0 ? Math.round((converted / total) * 10000) / 100 : 0;
}
//# sourceMappingURL=forecast.js.map