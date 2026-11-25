"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.Decimal = void 0;
exports.toUzs = toUzs;
exports.sum = sum;
exports.percent = percent;
const decimal_js_1 = __importDefault(require("decimal.js"));
exports.Decimal = decimal_js_1.default;
/** Конвертация в UZS по зафиксированному курсу. Округление до 2 знаков (банковское). */
function toUzs(amount, currency, rateToUzs) {
    const value = new decimal_js_1.default(amount);
    if (currency === 'UZS')
        return value.toDecimalPlaces(2, decimal_js_1.default.ROUND_HALF_EVEN);
    return value.mul(rateToUzs).toDecimalPlaces(2, decimal_js_1.default.ROUND_HALF_EVEN);
}
function sum(values) {
    return values.reduce((acc, v) => acc.add(v), new decimal_js_1.default(0));
}
/** Процент a от b; при b = 0 возвращает 0. */
function percent(part, whole, dp = 2) {
    const w = new decimal_js_1.default(whole);
    if (w.isZero())
        return 0;
    return new decimal_js_1.default(part).div(w).mul(100).toDecimalPlaces(dp).toNumber();
}
//# sourceMappingURL=money.js.map