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
exports.FormsService = void 0;
const node_crypto_1 = require("node:crypto");
const common_1 = require("@nestjs/common");
const contracts_1 = require("@fluggi/contracts");
const audit_service_1 = require("../../core/audit/audit.service");
const app_exception_1 = require("../../core/http/app.exception");
const prisma_service_1 = require("../../core/prisma/prisma.service");
const intake_service_1 = require("./intake.service");
/** Слишком быстрая отправка после показа формы — признак бота. */
const MIN_FILL_MS = 1500;
/** Ключи полей, которые ложатся в поля лида. */
const LEAD_KEYS = ['name', 'phone', 'email', 'company', 'message', 'instagram'];
/**
 * Формы для сайта: CEO собирает форму в CRM, вставляет код на сайт (WordPress),
 * заявки становятся лидами (источник «Сайт»), повторные — отмечаются в найденном лиде.
 */
let FormsService = class FormsService {
    prisma;
    audit;
    intake;
    constructor(prisma, audit, intake) {
        this.prisma = prisma;
        this.audit = audit;
        this.intake = intake;
    }
    async toDto(f) {
        const [submissions, leads, last] = await Promise.all([
            this.prisma.formSubmission.count({ where: { formId: f.id } }),
            this.prisma.formSubmission.count({ where: { formId: f.id, result: 'LEAD_CREATED' } }),
            this.prisma.formSubmission.findFirst({
                where: { formId: f.id },
                orderBy: { createdAt: 'desc' },
                select: { createdAt: true },
            }),
        ]);
        return {
            id: f.id,
            key: f.key,
            name: f.name,
            title: f.title,
            description: f.description,
            buttonText: f.buttonText,
            successMessage: f.successMessage,
            fields: f.fields,
            serviceId: f.serviceId,
            sourceId: f.sourceId,
            owner: f.owner ? { id: f.owner.id, name: f.owner.fullName } : null,
            teamId: f.teamId,
            isActive: f.isActive,
            submissions,
            leads,
            lastSubmissionAt: last?.createdAt.toISOString() ?? null,
            createdAt: f.createdAt.toISOString(),
        };
    }
    include = { owner: { select: { id: true, fullName: true } } };
    async list() {
        const rows = await this.prisma.leadForm.findMany({
            where: { deletedAt: null },
            include: this.include,
            orderBy: { createdAt: 'asc' },
        });
        return Promise.all(rows.map((r) => this.toDto(r)));
    }
    async save(auth, id, input, meta) {
        const data = {
            name: input.name,
            title: input.title,
            description: input.description ?? null,
            buttonText: input.buttonText,
            successMessage: input.successMessage,
            fields: input.fields,
            serviceId: input.serviceId ?? null,
            sourceId: input.sourceId ?? null,
            ownerId: input.ownerId ?? null,
            teamId: input.teamId ?? null,
            isActive: input.isActive,
        };
        if (id && !(await this.prisma.leadForm.findFirst({ where: { id, deletedAt: null } })))
            throw (0, app_exception_1.notFound)('Форма');
        const row = await this.prisma.$transaction(async (tx) => {
            const f = id
                ? await tx.leadForm.update({ where: { id }, data, include: this.include })
                : await tx.leadForm.create({
                    data: { ...data, key: (0, node_crypto_1.randomBytes)(9).toString('base64url') },
                    include: this.include,
                });
            await this.audit.log(tx, {
                actorId: auth.userId,
                action: id ? 'lead_form.update' : 'lead_form.create',
                entityType: 'lead_form',
                entityId: f.id,
                changes: { name: { old: null, new: f.name }, isActive: { old: null, new: f.isActive } },
                meta,
            });
            return f;
        });
        return this.toDto(row);
    }
    async remove(auth, id, meta) {
        const f = await this.prisma.leadForm.findFirst({ where: { id, deletedAt: null } });
        if (!f)
            throw (0, app_exception_1.notFound)('Форма');
        await this.prisma.$transaction(async (tx) => {
            await tx.leadForm.update({ where: { id }, data: { deletedAt: new Date(), isActive: false } });
            await this.audit.log(tx, {
                actorId: auth.userId,
                action: 'lead_form.delete',
                entityType: 'lead_form',
                entityId: id,
                changes: { name: { old: f.name, new: null } },
                meta,
            });
        });
    }
    async submissions(id) {
        const rows = await this.prisma.formSubmission.findMany({
            where: { formId: id },
            include: { lead: { select: { id: true, number: true, title: true } } },
            orderBy: { createdAt: 'desc' },
            take: 100,
        });
        return rows.map((r) => ({
            id: r.id,
            data: r.data,
            utm: r.utm ?? null,
            page: r.page,
            result: r.result,
            lead: r.lead
                ? { id: r.lead.id, number: (0, contracts_1.formatNumber)('L', r.lead.number), name: r.lead.title }
                : null,
            createdAt: r.createdAt.toISOString(),
        }));
    }
    // ─────────────── Публичная часть (без входа) ───────────────
    async active(key) {
        const f = await this.prisma.leadForm.findFirst({
            where: { key, isActive: true, deletedAt: null },
        });
        if (!f)
            throw (0, app_exception_1.notFound)('Форма');
        return f;
    }
    async publicForm(key) {
        const f = await this.active(key);
        return {
            key: f.key,
            title: f.title,
            description: f.description,
            buttonText: f.buttonText,
            successMessage: f.successMessage,
            fields: f.fields,
        };
    }
    /** Заявка с сайта. Боты получают «успех», но лид не создаётся. */
    async submit(key, input, ip) {
        const form = await this.active(key);
        const fields = form.fields;
        const data = {};
        for (const f of fields) {
            const v = (input.data[f.key] ?? '').trim();
            if (f.required && !v)
                throw (0, app_exception_1.businessRule)('Заполните обязательные поля', [
                    { path: `data.${f.key}`, message: `Заполните «${f.label}»` },
                ]);
            if (v && f.type === 'phone' && (v.replace(/\D/g, '').length < 7 || v.length > 30))
                throw (0, app_exception_1.businessRule)('Проверьте телефон', [
                    { path: `data.${f.key}`, message: 'Похоже, номер указан с ошибкой' },
                ]);
            if (v && f.type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v))
                throw (0, app_exception_1.businessRule)('Проверьте email', [
                    { path: `data.${f.key}`, message: 'Похоже, email указан с ошибкой' },
                ]);
            if (v && f.type === 'select' && f.options?.length && !f.options.includes(v))
                throw (0, app_exception_1.businessRule)('Выберите вариант из списка', [
                    { path: `data.${f.key}`, message: 'Выберите вариант из списка' },
                ]);
            if (v)
                data[f.key] = v.slice(0, 4000);
        }
        const utm = input.utm && Object.keys(input.utm).length ? input.utm : null;
        const record = (result, leadId) => this.prisma.formSubmission.create({
            data: {
                formId: form.id,
                leadId,
                data: data,
                utm: (utm ?? undefined),
                page: input.page?.slice(0, 1000) ?? null,
                ip,
                result,
            },
        });
        const tooFast = input.renderedAt !== undefined && Date.now() - input.renderedAt < MIN_FILL_MS;
        if (input.website || tooFast) {
            await record('SPAM', null);
            return { ok: true, message: form.successMessage };
        }
        const by = (type, k) => data[k] ?? data[fields.find((f) => f.type === type)?.key ?? ''] ?? null;
        const name = data.name ?? null;
        const company = data.company ?? null;
        const extra = fields
            .filter((f) => !LEAD_KEYS.includes(f.key) && data[f.key])
            .filter((f) => f.type !== 'phone' && f.type !== 'email')
            .map((f) => `${f.label}: ${data[f.key]}`);
        const utmLine = utm
            ? Object.entries(utm)
                .map(([k, v]) => `${k}=${v}`)
                .join(', ')
            : null;
        const comment = [
            data.message,
            ...extra,
            `Форма: ${form.name}`,
            input.page ? `Страница: ${input.page.slice(0, 300)}` : null,
            utmLine ? `UTM: ${utmLine}` : null,
        ]
            .filter(Boolean)
            .join('\n');
        const res = await this.intake.intake({
            title: `${company ?? name ?? 'Заявка с сайта'} — ${form.name}`,
            contactName: name,
            companyName: company,
            phone: by('phone', 'phone'),
            email: by('email', 'email'),
            instagram: data.instagram ?? null,
            comment,
            sourceCode: 'WEBSITE',
            sourceId: form.sourceId,
            serviceId: form.serviceId,
            ownerId: form.ownerId,
            teamId: form.teamId,
            channel: `Форма «${form.name}»`,
        });
        await record(res.duplicate ? 'DUPLICATE' : 'LEAD_CREATED', res.leadId);
        return { ok: true, message: form.successMessage };
    }
};
exports.FormsService = FormsService;
exports.FormsService = FormsService = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [prisma_service_1.PrismaService,
        audit_service_1.AuditService,
        intake_service_1.IntakeService])
], FormsService);
//# sourceMappingURL=forms.service.js.map