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
exports.OverheadService = void 0;
exports.monthsBetween = monthsBetween;
const common_1 = require("@nestjs/common");
const contracts_1 = require("@fluggi/contracts");
const domain_1 = require("@fluggi/domain");
const db_1 = require("@fluggi/db");
const prisma_service_1 = require("../../core/prisma/prisma.service");
const ZERO = new db_1.Prisma.Decimal(0);
const SETTINGS_KEY = 'finance';
/** Месяцы YYYY-MM от from до to включительно. */
function monthsBetween(from, to) {
    const out = [];
    let [y, m] = from.split('-').map(Number);
    const [ty, tm] = to.split('-').map(Number);
    while (y < ty || (y === ty && m <= tm)) {
        out.push(`${y}-${String(m).padStart(2, '0')}`);
        m += 1;
        if (m > 12) {
            m = 1;
            y += 1;
        }
    }
    return out;
}
/**
 * Накладные расходы (аренда, офис — категории с отметкой «накладные») делятся
 * на проекты, которые были в работе в этом месяце, или на заданный в настройках делитель.
 * Это управленческая аналитика проекта: в расходах компании накладные остаются как есть.
 */
let OverheadService = class OverheadService {
    prisma;
    constructor(prisma) {
        this.prisma = prisma;
    }
    async settings() {
        const row = await this.prisma.setting.findUnique({ where: { key: SETTINGS_KEY } });
        const parsed = contracts_1.financeSettingsSchema.safeParse({
            overheadDivisor: null,
            ...(row?.value ?? {}),
        });
        return parsed.success ? parsed.data : { overheadDivisor: null };
    }
    async saveSettings(value, userId) {
        await this.prisma.setting.upsert({
            where: { key: SETTINGS_KEY },
            update: { value: value, updatedById: userId },
            create: {
                key: SETTINGS_KEY,
                value: value,
                updatedById: userId,
            },
        });
        return this.settings();
    }
    /** Накладные по месяцам, UZS. */
    async overheadByMonth(months) {
        const map = new Map();
        if (!months.length)
            return map;
        const rows = await this.prisma.expense.findMany({
            where: {
                deletedAt: null,
                scope: 'COMPANY',
                categoryRef: { isOverhead: true },
                // Календарная дата расхода — полночь UTC
                expenseDate: {
                    gte: new Date(`${months[0]}-01`),
                    lt: new Date(Date.UTC(...nextMonth(months.at(-1)))),
                },
            },
            select: { expenseDate: true, amountUzs: true },
        });
        for (const r of rows) {
            const m = r.expenseDate.toISOString().slice(0, 7);
            map.set(m, (map.get(m) ?? ZERO).add(r.amountUzs));
        }
        return map;
    }
    async activeByMonth(months) {
        const map = new Map();
        if (!months.length)
            return map;
        const last = (0, domain_1.monthRange)(months.at(-1)).to;
        const first = (0, domain_1.monthRange)(months[0]).from;
        const projects = await this.prisma.project.findMany({
            where: {
                deletedAt: null,
                status: { not: 'CANCELLED' },
                createdAt: { lt: last },
                OR: [{ completedAt: null }, { completedAt: { gte: first } }],
            },
            select: { createdAt: true, completedAt: true },
        });
        for (const m of months) {
            const r = (0, domain_1.monthRange)(m);
            map.set(m, projects.filter((p) => p.createdAt < r.to && (!p.completedAt || p.completedAt >= r.from))
                .length);
        }
        return map;
    }
    /** Доля накладных для каждого проекта за все месяцы его работы (до сегодня). */
    async forProjects(projects, now = new Date()) {
        const out = new Map();
        if (!projects.length)
            return out;
        const today = (0, domain_1.companyDate)(now).slice(0, 7);
        const spans = projects.map((p) => ({
            id: p.id,
            months: p.status === 'CANCELLED'
                ? []
                : monthsBetween((0, domain_1.companyDate)(p.createdAt).slice(0, 7), p.completedAt ? (0, domain_1.companyDate)(p.completedAt).slice(0, 7) : today),
        }));
        const all = [...new Set(spans.flatMap((s) => s.months))].sort();
        const [overhead, active, settings] = await Promise.all([
            this.overheadByMonth(all),
            this.activeByMonth(all),
            this.settings(),
        ]);
        for (const s of spans) {
            out.set(s.id, s.months.reduce((acc, m) => acc.add((0, domain_1.overheadShare)((overhead.get(m) ?? ZERO).toString(), active.get(m) ?? 1, settings.overheadDivisor)), ZERO));
        }
        return out;
    }
    /** Оценка накладных на один проект в месяц — по последнему полному месяцу (для экономики тарифа). */
    async monthlyShareEstimate(now = new Date()) {
        const months = [prevMonth((0, domain_1.companyDate)(now).slice(0, 7))];
        const [overhead, active, settings] = await Promise.all([
            this.overheadByMonth(months),
            this.activeByMonth(months),
            this.settings(),
        ]);
        const m = months[0];
        return new db_1.Prisma.Decimal((0, domain_1.overheadShare)((overhead.get(m) ?? ZERO).toString(), active.get(m) ?? 1, settings.overheadDivisor));
    }
};
exports.OverheadService = OverheadService;
exports.OverheadService = OverheadService = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [prisma_service_1.PrismaService])
], OverheadService);
function nextMonth(m) {
    const [y, mm] = m.split('-').map(Number);
    return mm === 12 ? [y + 1, 0, 1] : [y, mm, 1];
}
function prevMonth(m) {
    const [y, mm] = m.split('-').map(Number);
    return mm === 1 ? `${y - 1}-12` : `${y}-${String(mm - 1).padStart(2, '0')}`;
}
//# sourceMappingURL=overhead.service.js.map