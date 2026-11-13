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
exports.SettingsModule = exports.SettingsService = void 0;
const common_1 = require("@nestjs/common");
const contracts_1 = require("@fluggi/contracts");
const prisma_service_1 = require("../prisma/prisma.service");
const KEY = 'automation';
const COMPANY_KEY = 'company';
const TTL_MS = 30_000;
/** Системные настройки (таблица settings) с кэшем на 30 секунд. */
let SettingsService = class SettingsService {
    prisma;
    cache = null;
    constructor(prisma) {
        this.prisma = prisma;
    }
    async automation() {
        if (this.cache && Date.now() - this.cache.at < TTL_MS)
            return this.cache.value;
        const row = await this.prisma.setting.findUnique({ where: { key: KEY } });
        const parsed = contracts_1.automationSettingsSchema.safeParse({
            ...contracts_1.DEFAULT_AUTOMATION_SETTINGS,
            ...(row?.value ?? {}),
        });
        const value = parsed.success ? parsed.data : contracts_1.DEFAULT_AUTOMATION_SETTINGS;
        this.cache = { at: Date.now(), value };
        return value;
    }
    async saveAutomation(value, userId) {
        await this.prisma.setting.upsert({
            where: { key: KEY },
            update: { value: value, updatedById: userId },
            create: { key: KEY, value: value, updatedById: userId },
        });
        this.cache = null;
        return this.automation();
    }
    /** Реквизиты компании для пакета бухгалтеру. */
    async company() {
        const row = await this.prisma.setting.findUnique({ where: { key: COMPANY_KEY } });
        const parsed = contracts_1.companySettingsSchema.safeParse({
            ...contracts_1.DEFAULT_COMPANY_SETTINGS,
            ...(row?.value ?? {}),
        });
        return parsed.success ? parsed.data : contracts_1.DEFAULT_COMPANY_SETTINGS;
    }
    async saveCompany(value, userId) {
        await this.prisma.setting.upsert({
            where: { key: COMPANY_KEY },
            update: { value: value, updatedById: userId },
            create: {
                key: COMPANY_KEY,
                value: value,
                updatedById: userId,
            },
        });
        return this.company();
    }
    /** Сбросить кэш (тесты, ручные изменения в БД). */
    invalidate() {
        this.cache = null;
    }
};
exports.SettingsService = SettingsService;
exports.SettingsService = SettingsService = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [prisma_service_1.PrismaService])
], SettingsService);
let SettingsModule = class SettingsModule {
};
exports.SettingsModule = SettingsModule;
exports.SettingsModule = SettingsModule = __decorate([
    (0, common_1.Global)(),
    (0, common_1.Module)({ providers: [SettingsService], exports: [SettingsService] })
], SettingsModule);
//# sourceMappingURL=settings.service.js.map