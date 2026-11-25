"use strict";
var __decorate = (this && this.__decorate) || function (decorators, target, key, desc) {
    var c = arguments.length, r = c < 3 ? target : desc === null ? desc = Object.getOwnPropertyDescriptor(target, key) : desc, d;
    if (typeof Reflect === "object" && typeof Reflect.decorate === "function") r = Reflect.decorate(decorators, target, key, desc);
    else for (var i = decorators.length - 1; i >= 0; i--) if (d = decorators[i]) r = (c < 3 ? d(r) : c > 3 ? d(target, key, r) : d(target, key)) || r;
    return c > 3 && r && Object.defineProperty(target, key, r), r;
};
var __metadata = (this && this.__metadata) || function (k, v) {
    if (typeof Reflect === "object" && typeof Reflect.metadata === "function") return Reflect.metadata(k, v);
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.ExchangeRateService = void 0;
const common_1 = require("@nestjs/common");
const domain_1 = require("@fluggi/domain");
const app_exception_1 = require("../../core/http/app.exception");
const prisma_service_1 = require("../../core/prisma/prisma.service");
let ExchangeRateService = class ExchangeRateService {
    prisma;
    constructor(prisma) {
        this.prisma = prisma;
    }
    /** Действующий курс на дату (последний установленный не позже даты). */
    async rateFor(currency, at = new Date(), tx = this.prisma) {
        if (currency === 'UZS')
            return '1';
        const rate = await tx.exchangeRate.findFirst({
            where: { currency, date: { lte: at } },
            orderBy: { date: 'desc' },
        });
        if (!rate) {
            throw (0, app_exception_1.businessRule)('Не задан курс USD. CEO может указать его в «Настройки → Справочники»', [
                { path: 'currency', message: 'Курс USD не задан' },
            ]);
        }
        return rate.rateToUzs.toString();
    }
    async convert(amount, currency, tx = this.prisma) {
        const rate = await this.rateFor(currency, new Date(), tx);
        return { rate, amountUzs: (0, domain_1.toUzs)(amount, currency, rate).toFixed(2) };
    }
};
exports.ExchangeRateService = ExchangeRateService;
exports.ExchangeRateService = ExchangeRateService = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [prisma_service_1.PrismaService])
], ExchangeRateService);
//# sourceMappingURL=exchange-rate.service.js.map