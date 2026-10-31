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
exports.DocumentsService = void 0;
exports.shortName = shortName;
const common_1 = require("@nestjs/common");
const contracts_1 = require("@fluggi/contracts");
const domain_1 = require("@fluggi/domain");
const audit_service_1 = require("../../core/audit/audit.service");
const prisma_service_1 = require("../../core/prisma/prisma.service");
const settings_service_1 = require("../../core/settings/settings.service");
const contracts_service_1 = require("../contracts/contracts.service");
const crm_access_service_1 = require("../crm/crm-access.service");
const proposals_service_1 = require("../proposals/proposals.service");
const doc_model_1 = require("./doc-model");
const KEY = 'documents';
const EMPTY_REQ = contracts_1.clientRequisitesSchema.parse({});
/** «Иванов Иван Иванович» → «Иванов И.И.» для подписи. */
function shortName(full) {
    const [last, ...rest] = full.trim().split(/\s+/);
    if (!last)
        return '';
    return [last, rest.map((p) => `${p[0]}.`).join('')].filter(Boolean).join(' ');
}
const join = (parts, sep = ' · ') => parts
    .map((p) => p?.trim())
    .filter(Boolean)
    .join(sep);
let DocumentsService = class DocumentsService {
    prisma;
    settings;
    audit;
    proposals;
    contracts;
    access;
    constructor(prisma, settings, audit, proposals, contracts, access) {
        this.prisma = prisma;
        this.settings = settings;
        this.audit = audit;
        this.proposals = proposals;
        this.contracts = contracts;
        this.access = access;
    }
    // ─────────────── Настройки документов ───────────────
    async documentSettings() {
        const row = await this.prisma.setting.findUnique({ where: { key: KEY } });
        const parsed = contracts_1.documentSettingsSchema.safeParse(row?.value ?? {});
        return parsed.success ? parsed.data : contracts_1.documentSettingsSchema.parse({});
    }
    async saveDocumentSettings(auth, value, meta) {
        const json = value;
        await this.prisma.$transaction(async (tx) => {
            await tx.setting.upsert({
                where: { key: KEY },
                update: { value: json, updatedById: auth.userId },
                create: { key: KEY, value: json, updatedById: auth.userId },
            });
            await this.audit.log(tx, {
                actorId: auth.userId,
                action: 'settings.documents',
                entityType: 'setting',
                entityId: null,
                changes: { city: { old: null, new: value.city } },
                meta,
            });
        });
        return this.documentSettings();
    }
    // ─────────────── Реквизиты клиента ───────────────
    async saveClientRequisites(auth, clientId, input, meta) {
        const before = await this.access.client(auth, clientId, 'client.update');
        await this.prisma.$transaction(async (tx) => {
            await tx.client.update({
                where: { id: clientId },
                data: { requisites: input },
            });
            await this.audit.log(tx, {
                actorId: auth.userId,
                action: 'client.requisites',
                entityType: 'client',
                entityId: clientId,
                changes: { requisites: { old: before.requisites ?? null, new: input } },
                meta,
            });
        });
        return input;
    }
    async clientParty(clientId) {
        const c = await this.prisma.client.findUniqueOrThrow({ where: { id: clientId } });
        const parsed = contracts_1.clientRequisitesSchema.safeParse(c.requisites ?? {});
        const req = parsed.success ? parsed.data : EMPTY_REQ;
        return { client: c, req };
    }
    // ─────────────── Общие части ───────────────
    brand(co) {
        return {
            name: co.name || co.legalName || 'Fluggi',
            contacts: join([co.phone, co.email, co.website, co.address]),
        };
    }
    companyParty(co) {
        return {
            role: 'Исполнитель',
            name: co.legalName || co.name || '________',
            lines: this.reqLines({
                address: co.address,
                inn: co.inn,
                oked: co.oked,
                vatCode: co.vatCode,
                bank: co.bank,
                mfo: co.mfo,
                account: co.account,
                phone: co.phone,
            }),
            position: co.directorPosition || 'Директор',
            signer: shortName(co.director) || '________',
        };
    }
    reqLines(r) {
        const rows = [
            ['Адрес', r.address],
            ['ИНН', r.inn],
            ['ОКЭД', r.oked],
            ['Рег. код НДС', r.vatCode],
            ['Банк', r.bank],
            ['МФО', r.mfo],
            ['Р/с', r.account],
            ['Тел.', r.phone],
        ];
        return rows.filter((x) => Boolean(x[1]?.trim()));
    }
    itemsTable(items, currency, totals) {
        return {
            t: 'table',
            head: [
                '№',
                'Услуга',
                'Кол-во',
                `Цена, ${currency === 'UZS' ? 'сум' : currency}`,
                `Сумма, ${currency === 'UZS' ? 'сум' : currency}`,
            ],
            align: ['left', 'left', 'right', 'right', 'right'],
            widths: [0.06, 0.5, 0.1, 0.17, 0.17],
            rows: items.map((i, idx) => [
                String(idx + 1),
                [
                    i.description,
                    join([i.tariffName ? `Тариф «${i.tariffName}»` : null, i.tariffDescription], ': '),
                    Number(i.discountPct) ? `скидка ${Number(i.discountPct)}%` : '',
                ]
                    .filter(Boolean)
                    .join('\n'),
                Number(i.quantity).toLocaleString('ru-RU'),
                (0, doc_model_1.amount)(i.unitPrice),
                (0, doc_model_1.amount)(i.total),
            ]),
            totals,
        };
    }
    async itemLines(proposalId) {
        const items = await this.prisma.proposalItem.findMany({
            where: { proposalId },
            include: {
                service: { select: { nameRu: true } },
                tariff: { select: { name: true, description: true } },
            },
            orderBy: { sort: 'asc' },
        });
        return items.map((i) => ({
            description: i.description,
            serviceName: i.service?.nameRu ?? null,
            tariffName: i.tariff?.name ?? null,
            tariffDescription: i.tariff?.description ?? null,
            quantity: i.quantity.toString(),
            unitPrice: i.unitPrice.toFixed(2),
            discountPct: i.discountPct.toString(),
            total: i.total.toFixed(2),
        }));
    }
    // ─────────────── Коммерческое предложение ───────────────
    async proposalModel(auth, id) {
        const p = await this.proposals.get(auth, id);
        const [co, ds, { client, req }, items] = await Promise.all([
            this.settings.company(),
            this.documentSettings(),
            this.clientParty(p.client.id),
            this.itemLines(p.id),
        ]);
        const cur = p.currency;
        const totals = [{ label: 'Сумма', value: (0, doc_model_1.moneyText)(p.subtotal, cur) }];
        if (Number(p.discountAmount))
            totals.push({ label: 'Скидка', value: `− ${(0, doc_model_1.moneyText)(p.discountAmount, cur)}` });
        totals.push({ label: 'Итого', value: (0, doc_model_1.moneyText)(p.total, cur), strong: true });
        const blocks = [
            {
                t: 'title',
                text: 'Коммерческое предложение',
                sub: `${p.number} от ${(0, doc_model_1.shortDate)(p.updatedAt)}`,
            },
            {
                t: 'kv',
                rows: [
                    ['Для', req.legalName || client.name],
                    ['Проект', p.title],
                    ['Менеджер', p.manager.name],
                    ...(p.validUntil
                        ? [['Действительно до', (0, doc_model_1.shortDate)(p.validUntil)]]
                        : []),
                ],
            },
        ];
        if (ds.proposalIntro.trim())
            blocks.push({ t: 'p', text: ds.proposalIntro.trim() });
        if (p.description)
            blocks.push({ t: 'p', text: p.description });
        blocks.push(this.itemsTable(items, cur, totals));
        blocks.push({ t: 'p', text: `Итого к оплате: ${(0, domain_1.amountInWords)(p.total, cur)}.` });
        const terms = [];
        if (p.implementationTerm)
            terms.push(['Срок реализации', p.implementationTerm]);
        if (p.paymentTerms)
            terms.push(['Условия оплаты', p.paymentTerms]);
        if (terms.length)
            blocks.push({ t: 'h', text: 'Условия' }, { t: 'kv', rows: terms });
        if (ds.proposalNote.trim())
            blocks.push({ t: 'p', text: ds.proposalNote.trim() });
        blocks.push({
            t: 'sign',
            company: co.legalName || co.name || 'Fluggi',
            position: co.directorPosition || 'Директор',
            signer: shortName(co.director) || '________',
        });
        return {
            fileName: `${p.number}`,
            title: `Коммерческое предложение ${p.number}`,
            brand: this.brand(co),
            blocks,
        };
    }
    // ─────────────── Договор ───────────────
    async contractModel(auth, id) {
        const c = await this.contracts.get(auth, id);
        const [co, ds, { client, req }, proposal] = await Promise.all([
            this.settings.company(),
            this.documentSettings(),
            this.clientParty(c.client.id),
            c.proposal
                ? this.prisma.proposal.findUnique({
                    where: { id: c.proposal.id },
                    select: {
                        id: true,
                        implementationTerm: true,
                        paymentTerms: true,
                        currency: true,
                        total: true,
                    },
                })
                : null,
        ]);
        const items = proposal ? await this.itemLines(proposal.id) : [];
        const cur = c.currency;
        const lines = items.length && proposal?.currency === cur
            ? items
            : [
                {
                    description: items.length
                        ? items.map((i) => i.description).join('; ')
                        : 'Услуги по договору',
                    serviceName: null,
                    tariffName: null,
                    tariffDescription: null,
                    quantity: '1',
                    unitPrice: c.amount,
                    discountPct: '0',
                    total: c.amount,
                },
            ];
        const itemsSum = lines.reduce((s, i) => s + Number(i.total), 0);
        const totals = [
            { label: 'Итого по договору', value: (0, doc_model_1.moneyText)(c.amount, cur), strong: true },
        ];
        if (Math.abs(itemsSum - Number(c.amount)) > 0.009)
            totals.unshift({ label: 'Сумма по позициям', value: (0, doc_model_1.moneyText)(itemsSum, cur) });
        const signer = (genitive, position, director) => genitive || join([position.toLowerCase(), director], ' ');
        const values = {
            'contract.number': c.number,
            'contract.date': (0, doc_model_1.longDate)(c.contractDate),
            'contract.amount': (0, doc_model_1.moneyText)(c.amount, cur),
            'contract.amountWords': (0, domain_1.amountInWords)(c.amount, cur),
            city: ds.city,
            'company.name': co.legalName || co.name,
            'company.signer': signer(co.signerGenitive, co.directorPosition, co.director),
            'company.director': co.director,
            'company.position': co.directorPosition,
            'company.basis': co.basis,
            'client.name': req.legalName || client.name,
            'client.signer': signer(req.signerGenitive, req.directorPosition, req.director),
            'client.director': req.director,
            'client.position': req.directorPosition,
            'client.basis': req.basis,
            services: lines.map((i) => i.description).join('; '),
            term: proposal?.implementationTerm ?? '',
            paymentTerms: proposal?.paymentTerms ?? '',
        };
        const parties = {
            t: 'parties',
            parties: [
                this.companyParty(co),
                {
                    role: 'Заказчик',
                    name: req.legalName || client.name,
                    lines: this.reqLines({ ...req, phone: req.phone || client.phone || '' }),
                    position: req.directorPosition || 'Директор',
                    signer: shortName(req.director) || '________',
                },
            ],
        };
        const blocks = (0, doc_model_1.templateBlocks)(ds.contractTemplate, values, { services: this.itemsTable(lines, cur, totals), parties }, { t: 'meta', left: ds.city, right: (0, doc_model_1.longDate)(c.contractDate) });
        return {
            fileName: `Dogovor_${c.number}`,
            title: `Договор ${c.number}`,
            brand: this.brand(co),
            blocks,
        };
    }
    /** Каких реквизитов не хватает — показываем перед скачиванием. */
    async check(auth, kind, id) {
        const co = await this.settings.company();
        const missing = [];
        if (!co.name && !co.legalName)
            missing.push('Название компании (Настройки → Автоматизация → Реквизиты)');
        if (kind === 'contracts') {
            const c = await this.contracts.get(auth, id);
            if (!co.inn)
                missing.push('ИНН компании');
            if (!co.bank || !co.mfo || !co.account)
                missing.push('Банк, МФО и расчётный счёт компании');
            if (!co.director)
                missing.push('Директор компании');
            if (!co.signerGenitive)
                missing.push('Компания: «в лице …» в родительном падеже (директора Иванова И.И.)');
            const { req } = await this.clientParty(c.client.id);
            if (!req.legalName)
                missing.push('Юридическое название клиента (карточка клиента → Реквизиты)');
            if (!req.inn)
                missing.push('ИНН / ПИНФЛ клиента');
            if (!req.director)
                missing.push('Подписант клиента');
            if (!req.signerGenitive)
                missing.push('Клиент: «в лице …» в родительном падеже');
        }
        else {
            await this.proposals.get(auth, id);
        }
        return { missing };
    }
};
exports.DocumentsService = DocumentsService;
exports.DocumentsService = DocumentsService = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [prisma_service_1.PrismaService,
        settings_service_1.SettingsService,
        audit_service_1.AuditService,
        proposals_service_1.ProposalsService,
        contracts_service_1.ContractsService,
        crm_access_service_1.CrmAccessService])
], DocumentsService);
//# sourceMappingURL=documents.service.js.map