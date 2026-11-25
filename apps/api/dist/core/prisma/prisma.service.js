"use strict";
var __decorate = (this && this.__decorate) || function (decorators, target, key, desc) {
    var c = arguments.length, r = c < 3 ? target : desc === null ? desc = Object.getOwnPropertyDescriptor(target, key) : desc, d;
    if (typeof Reflect === "object" && typeof Reflect.decorate === "function") r = Reflect.decorate(decorators, target, key, desc);
    else for (var i = decorators.length - 1; i >= 0; i--) if (d = decorators[i]) r = (c < 3 ? d(r) : c > 3 ? d(target, key, r) : d(target, key)) || r;
    return c > 3 && r && Object.defineProperty(target, key, r), r;
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.PrismaService = void 0;
exports.supportsSkipLocked = supportsSkipLocked;
const common_1 = require("@nestjs/common");
const db_1 = require("@fluggi/db");
/** Поддерживает ли сервер `FOR UPDATE SKIP LOCKED` (MySQL 8.0+, MariaDB 10.6+). */
function supportsSkipLocked(version) {
    const [major = 0, minor = 0] = version.split(/[.-]/).map(Number);
    if (/mariadb/i.test(version))
        return major > 10 || (major === 10 && minor >= 6);
    return major >= 8;
}
let PrismaService = class PrismaService extends db_1.PrismaClient {
    /**
     * Окончание блокирующего SELECT для очередей (outbox, Telegram): на MySQL 8 занятые строки
     * пропускаются, на MySQL 5.7 (виртуальный хостинг) — ожидание блокировки.
     */
    lockRows = db_1.Prisma.sql `FOR UPDATE`;
    async onModuleInit() {
        await this.$connect();
        const [row] = await this.$queryRaw `SELECT VERSION() AS v`;
        if (row && supportsSkipLocked(row.v))
            this.lockRows = db_1.Prisma.sql `FOR UPDATE SKIP LOCKED`;
    }
    async onModuleDestroy() {
        await this.$disconnect();
    }
};
exports.PrismaService = PrismaService;
exports.PrismaService = PrismaService = __decorate([
    (0, common_1.Injectable)()
], PrismaService);
//# sourceMappingURL=prisma.service.js.map