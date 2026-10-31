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
exports.ReferencesService = exports.toStageDto = exports.toServiceDto = void 0;
const common_1 = require("@nestjs/common");
const audit_service_1 = require("../../core/audit/audit.service");
const app_exception_1 = require("../../core/http/app.exception");
const serialize_1 = require("../../core/http/serialize");
const prisma_service_1 = require("../../core/prisma/prisma.service");
const toServiceDto = (s) => ({
    id: s.id,
    code: s.code,
    name: s.nameRu,
    nameUz: s.nameUz,
    nameEn: s.nameEn,
    description: s.description,
    basePrice: (0, serialize_1.dec)(s.basePrice),
    minPrice: (0, serialize_1.dec)(s.minPrice),
    currency: s.currency,
    pricingType: s.pricingType,
    directionId: s.directionId,
    isActive: s.isActive,
    sort: s.sort,
});
exports.toServiceDto = toServiceDto;
const toItem = (r) => ({
    id: r.id,
    code: r.code,
    name: r.nameRu,
    nameUz: r.nameUz,
    nameEn: r.nameEn,
    isActive: r.isActive,
    sort: r.sort,
    ...('requiresComment' in r ? { requiresComment: r.requiresComment } : {}),
});
const toStageDto = (s) => ({
    id: s.id,
    code: s.code,
    entity: s.entity,
    name: s.nameRu,
    sort: s.sort,
    probability: s.probability,
    color: s.color,
});
exports.toStageDto = toStageDto;
let ReferencesService = class ReferencesService {
    prisma;
    audit;
    constructor(prisma, audit) {
        this.prisma = prisma;
        this.audit = audit;
    }
    async all() {
        const [services, directions, sources, lossReasons, stages, usd] = await Promise.all([
            this.prisma.service.findMany({ orderBy: [{ sort: 'asc' }, { nameRu: 'asc' }] }),
            this.prisma.direction.findMany({ orderBy: [{ sort: 'asc' }, { name: 'asc' }] }),
            this.prisma.leadSource.findMany({ orderBy: [{ sort: 'asc' }, { nameRu: 'asc' }] }),
            this.prisma.lossReason.findMany({ orderBy: [{ sort: 'asc' }, { nameRu: 'asc' }] }),
            this.prisma.dealStage.findMany({ orderBy: { sort: 'asc' } }),
            this.prisma.exchangeRate.findFirst({
                where: { currency: 'USD' },
                orderBy: { date: 'desc' },
                include: { setBy: { select: { id: true, fullName: true } } },
            }),
        ]);
        return {
            services: services.map(exports.toServiceDto),
            directions: directions.map((d) => ({
                id: d.id,
                code: d.code,
                name: d.name,
                sort: d.sort,
                isActive: d.isActive,
            })),
            sources: sources.map(toItem),
            lossReasons: lossReasons.map(toItem),
            stages: stages.map(exports.toStageDto),
            usdRate: usd ? this.toRateDto(usd) : null,
        };
    }
    toRateDto(r) {
        return {
            id: r.id,
            date: r.date.toISOString().slice(0, 10),
            currency: r.currency,
            rateToUzs: r.rateToUzs.toString(),
            setBy: r.setBy ? { id: r.setBy.id, name: r.setBy.fullName } : null,
        };
    }
    async upsertService(auth, id, input, meta) {
        const data = {
            code: input.code,
            nameRu: input.name,
            nameUz: input.nameUz ?? null,
            nameEn: input.nameEn ?? null,
            description: input.description ?? null,
            basePrice: input.basePrice ?? null,
            minPrice: input.minPrice ?? null,
            currency: input.currency,
            pricingType: input.pricingType,
            directionId: input.directionId ?? null,
            isActive: input.isActive,
            sort: input.sort,
        };
        return this.prisma.$transaction(async (tx) => {
            const before = id ? await tx.service.findUnique({ where: { id } }) : null;
            if (id && !before)
                throw (0, app_exception_1.notFound)('Услуга');
            const saved = id
                ? await tx.service.update({ where: { id }, data })
                : await tx.service.create({ data });
            await this.audit.log(tx, {
                actorId: auth.userId,
                action: id ? 'service.update' : 'service.create',
                entityType: 'service',
                entityId: saved.id,
                changes: before
                    ? (0, audit_service_1.diffFields)({ ...(0, exports.toServiceDto)(before) }, { ...(0, exports.toServiceDto)(saved) }, [
                        'name',
                        'basePrice',
                        'minPrice',
                        'currency',
                        'pricingType',
                        'isActive',
                    ])
                    : { name: { old: null, new: saved.nameRu } },
                meta,
            });
            return (0, exports.toServiceDto)(saved);
        });
    }
    async upsertItem(auth, kind, id, input, meta) {
        const data = {
            code: input.code,
            nameRu: input.name,
            nameUz: input.nameUz ?? null,
            nameEn: input.nameEn ?? null,
            isActive: input.isActive,
            sort: input.sort,
        };
        return this.prisma.$transaction(async (tx) => {
            let saved;
            if (kind === 'sources') {
                if (id && !(await tx.leadSource.findUnique({ where: { id } })))
                    throw (0, app_exception_1.notFound)();
                saved = id
                    ? await tx.leadSource.update({ where: { id }, data })
                    : await tx.leadSource.create({ data });
            }
            else {
                const full = { ...data, requiresComment: input.requiresComment ?? false };
                if (id && !(await tx.lossReason.findUnique({ where: { id } })))
                    throw (0, app_exception_1.notFound)();
                saved = id
                    ? await tx.lossReason.update({ where: { id }, data: full })
                    : await tx.lossReason.create({ data: full });
            }
            await this.audit.log(tx, {
                actorId: auth.userId,
                action: `${kind}.${id ? 'update' : 'create'}`,
                entityType: kind,
                entityId: saved.id,
                changes: {
                    name: { old: null, new: saved.nameRu },
                    isActive: { old: null, new: saved.isActive },
                },
                meta,
            });
            return toItem(saved);
        });
    }
    async updateStage(auth, id, input, meta) {
        return this.prisma.$transaction(async (tx) => {
            const before = await tx.dealStage.findUnique({ where: { id } });
            if (!before)
                throw (0, app_exception_1.notFound)('Этап');
            const saved = await tx.dealStage.update({
                where: { id },
                data: { nameRu: input.name, probability: input.probability, color: input.color },
            });
            const changes = (0, audit_service_1.diffFields)({ ...(0, exports.toStageDto)(before) }, { ...(0, exports.toStageDto)(saved) }, [
                'name',
                'probability',
                'color',
            ]);
            if (changes) {
                await this.audit.log(tx, {
                    actorId: auth.userId,
                    action: 'stage.update',
                    entityType: 'stage',
                    entityId: id,
                    changes,
                    meta,
                });
            }
            return (0, exports.toStageDto)(saved);
        });
    }
    async setRate(auth, input, meta) {
        const date = new Date(`${input.date ?? new Date().toISOString().slice(0, 10)}T00:00:00Z`);
        return this.prisma.$transaction(async (tx) => {
            const before = await tx.exchangeRate.findUnique({
                where: { date_currency: { date, currency: input.currency } },
            });
            const saved = await tx.exchangeRate.upsert({
                where: { date_currency: { date, currency: input.currency } },
                update: { rateToUzs: input.rateToUzs, setById: auth.userId },
                create: {
                    date,
                    currency: input.currency,
                    rateToUzs: input.rateToUzs,
                    setById: auth.userId,
                },
                include: { setBy: { select: { id: true, fullName: true } } },
            });
            await this.audit.log(tx, {
                actorId: auth.userId,
                action: 'exchange_rate.set',
                entityType: 'exchange_rate',
                entityId: saved.id,
                changes: {
                    [`${input.currency} ${date.toISOString().slice(0, 10)}`]: {
                        old: before?.rateToUzs.toString() ?? null,
                        new: saved.rateToUzs.toString(),
                    },
                },
                meta,
            });
            return this.toRateDto(saved);
        });
    }
};
exports.ReferencesService = ReferencesService;
exports.ReferencesService = ReferencesService = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [prisma_service_1.PrismaService,
        audit_service_1.AuditService])
], ReferencesService);
//# sourceMappingURL=references.service.js.map