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
exports.ClientsService = void 0;
const common_1 = require("@nestjs/common");
const contracts_1 = require("@fluggi/contracts");
const audit_service_1 = require("../../core/audit/audit.service");
const app_exception_1 = require("../../core/http/app.exception");
const prisma_service_1 = require("../../core/prisma/prisma.service");
const activity_service_1 = require("../crm/activity.service");
const crm_access_service_1 = require("../crm/crm-access.service");
const deals_mapper_1 = require("../deals/deals.mapper");
const clientInclude = {
    owner: { select: { id: true, fullName: true } },
    team: { select: { id: true, name: true } },
    source: { select: { id: true, nameRu: true } },
    deals: { where: { deletedAt: null }, select: { status: true } },
};
const toClientDto = (c) => ({
    id: c.id,
    number: (0, contracts_1.formatNumber)('C', c.number),
    name: c.name,
    type: c.type,
    industry: c.industry,
    phone: c.phone,
    email: c.email,
    telegram: c.telegram,
    website: c.website,
    city: c.city,
    country: c.country,
    owner: { id: c.owner.id, name: c.owner.fullName },
    team: c.team,
    source: c.source ? { id: c.source.id, name: c.source.nameRu } : null,
    health: c.health,
    comment: c.comment,
    dealsCount: c.deals.length,
    openDealsCount: c.deals.filter((d) => d.status === 'OPEN').length,
    createdAt: c.createdAt.toISOString(),
});
const toContactDto = (c) => ({
    id: c.id,
    fullName: c.fullName,
    position: c.position,
    phone: c.phone,
    telegram: c.telegram,
    whatsapp: c.whatsapp,
    instagram: c.instagram,
    email: c.email,
    isPrimary: c.isPrimary,
});
let ClientsService = class ClientsService {
    prisma;
    access;
    activity;
    audit;
    constructor(prisma, access, activity, audit) {
        this.prisma = prisma;
        this.access = access;
        this.activity = activity;
        this.audit = audit;
    }
    async list(auth, q) {
        const and = [this.access.clientWhere(auth)];
        if (q.q) {
            and.push({
                OR: [
                    { name: { contains: q.q } },
                    { phone: { contains: q.q } },
                    { telegram: { contains: q.q } },
                    {
                        contacts: {
                            some: {
                                OR: [{ fullName: { contains: q.q } }, { phone: { contains: q.q } }],
                            },
                        },
                    },
                ],
            });
        }
        if (q.ownerId)
            and.push({ ownerId: q.ownerId });
        if (q.teamId)
            and.push({ teamId: q.teamId });
        if (q.type)
            and.push({ type: q.type });
        const where = { AND: and };
        const [items, total] = await Promise.all([
            this.prisma.client.findMany({
                where,
                include: clientInclude,
                orderBy: { createdAt: 'desc' },
                skip: (q.page - 1) * q.pageSize,
                take: q.pageSize,
            }),
            this.prisma.client.count({ where }),
        ]);
        return { items: items.map(toClientDto), total, page: q.page, pageSize: q.pageSize };
    }
    async get(auth, id) {
        await this.access.client(auth, id);
        const client = await this.prisma.client.findUniqueOrThrow({
            where: { id },
            include: {
                ...clientInclude,
                contacts: { orderBy: [{ isPrimary: 'desc' }, { fullName: 'asc' }] },
            },
        });
        // Сделки клиента — только те, что видны пользователю.
        const deals = auth.permissions['deal.read']
            ? await this.prisma.deal.findMany({
                where: { AND: [this.access.dealWhere(auth), { clientId: id }] },
                include: deals_mapper_1.dealInclude,
                orderBy: { createdAt: 'desc' },
            })
            : [];
        return {
            ...toClientDto(client),
            contacts: client.contacts.map(toContactDto),
            deals: deals.map(deals_mapper_1.toDealDto),
            requisites: client.requisites
                ? (contracts_1.clientRequisitesSchema.safeParse(client.requisites).data ?? null)
                : null,
        };
    }
    async create(auth, input, meta) {
        const owner = await this.access.assignableOwner(auth, input.ownerId, 'client.create');
        const { contact, ownerId: _ignored, ...fields } = input;
        const client = await this.prisma.$transaction(async (tx) => {
            const created = await tx.client.create({
                data: {
                    ...fields,
                    ownerId: owner.id,
                    teamId: owner.teamId,
                    contacts: contact ? { create: { ...contact, isPrimary: true } } : undefined,
                },
            });
            await this.activity.log(tx, {
                type: 'client.created',
                actorId: auth.userId,
                clientId: created.id,
                payload: { name: created.name },
            });
            await this.audit.log(tx, {
                actorId: auth.userId,
                action: 'client.create',
                entityType: 'client',
                entityId: created.id,
                changes: { name: { old: null, new: created.name } },
                meta,
            });
            return created;
        });
        return this.get(auth, client.id);
    }
    async update(auth, id, input, meta) {
        const before = await this.access.client(auth, id, 'client.update');
        await this.prisma.$transaction(async (tx) => {
            const after = await tx.client.update({ where: { id }, data: input });
            const changes = (0, audit_service_1.diffFields)(before, after, [
                'name',
                'type',
                'phone',
                'email',
                'telegram',
                'website',
                'city',
                'industry',
            ]);
            if (changes) {
                await this.activity.log(tx, {
                    type: 'client.updated',
                    actorId: auth.userId,
                    clientId: id,
                    payload: { changes },
                });
                await this.audit.log(tx, {
                    actorId: auth.userId,
                    action: 'client.update',
                    entityType: 'client',
                    entityId: id,
                    changes,
                    meta,
                });
            }
        });
        return this.get(auth, id);
    }
    async addContact(auth, clientId, input, meta) {
        await this.access.client(auth, clientId, 'client.update');
        return this.prisma.$transaction(async (tx) => {
            if (input.isPrimary)
                await tx.contact.updateMany({ where: { clientId }, data: { isPrimary: false } });
            const c = await tx.contact.create({ data: { ...input, clientId } });
            await this.audit.log(tx, {
                actorId: auth.userId,
                action: 'contact.create',
                entityType: 'contact',
                entityId: c.id,
                changes: { fullName: { old: null, new: c.fullName } },
                meta,
            });
            return toContactDto(c);
        });
    }
    async updateContact(auth, id, input, meta) {
        const contact = await this.prisma.contact.findUnique({ where: { id } });
        if (!contact)
            throw (0, app_exception_1.notFound)('Контакт');
        await this.access.client(auth, contact.clientId, 'client.update');
        return this.prisma.$transaction(async (tx) => {
            if (input.isPrimary)
                await tx.contact.updateMany({
                    where: { clientId: contact.clientId, id: { not: id } },
                    data: { isPrimary: false },
                });
            const after = await tx.contact.update({ where: { id }, data: input });
            const changes = (0, audit_service_1.diffFields)(contact, after, [
                'fullName',
                'position',
                'phone',
                'telegram',
                'email',
                'isPrimary',
            ]);
            if (changes) {
                await this.audit.log(tx, {
                    actorId: auth.userId,
                    action: 'contact.update',
                    entityType: 'contact',
                    entityId: id,
                    changes,
                    meta,
                });
            }
            return toContactDto(after);
        });
    }
    async removeContact(auth, id, meta) {
        const contact = await this.prisma.contact.findUnique({ where: { id } });
        if (!contact)
            throw (0, app_exception_1.notFound)('Контакт');
        await this.access.client(auth, contact.clientId, 'client.update');
        if (await this.prisma.deal.count({ where: { contactId: id } })) {
            throw (0, app_exception_1.businessRule)('Контакт указан в сделке — сначала замените его там');
        }
        await this.prisma.$transaction(async (tx) => {
            await tx.contact.delete({ where: { id } });
            await this.audit.log(tx, {
                actorId: auth.userId,
                action: 'contact.delete',
                entityType: 'contact',
                entityId: id,
                changes: { fullName: { old: contact.fullName, new: null } },
                meta,
            });
        });
    }
};
exports.ClientsService = ClientsService;
exports.ClientsService = ClientsService = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [prisma_service_1.PrismaService,
        crm_access_service_1.CrmAccessService,
        activity_service_1.ActivityService,
        audit_service_1.AuditService])
], ClientsService);
//# sourceMappingURL=clients.service.js.map