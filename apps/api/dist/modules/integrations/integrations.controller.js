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
var __param = (this && this.__param) || function (paramIndex, decorator) {
    return function (target, key) { decorator(target, key, paramIndex); }
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.PublicIntakeController = exports.InboxController = exports.IntegrationsController = void 0;
const common_1 = require("@nestjs/common");
const throttler_1 = require("@nestjs/throttler");
const contracts_1 = require("@fluggi/contracts");
const decorators_1 = require("../../core/auth/decorators");
const uuid_pipe_1 = require("../../core/http/uuid.pipe");
const zod_pipe_1 = require("../../core/http/zod.pipe");
const forms_service_1 = require("./forms.service");
const inbox_service_1 = require("./inbox.service");
const intake_service_1 = require("./intake.service");
const meta_service_1 = require("./meta.service");
/** Настройки интеграций: формы для сайта, Instagram/Facebook (только CEO). */
let IntegrationsController = class IntegrationsController {
    forms;
    intake;
    meta;
    constructor(forms, intake, meta) {
        this.forms = forms;
        this.intake = intake;
        this.meta = meta;
    }
    list() {
        return this.forms.list();
    }
    create(auth, body, meta) {
        return this.forms.save(auth, null, body, meta);
    }
    update(auth, id, body, meta) {
        return this.forms.save(auth, id, body, meta);
    }
    remove(auth, id, meta) {
        return this.forms.remove(auth, id, meta);
    }
    submissions(id) {
        return this.forms.submissions(id);
    }
    settings() {
        return this.intake.settings();
    }
    saveSettings(auth, body) {
        return this.intake.saveSettings(body, auth.userId);
    }
    metaStatus() {
        return this.meta.status();
    }
};
exports.IntegrationsController = IntegrationsController;
__decorate([
    (0, common_1.Get)('lead-forms'),
    (0, decorators_1.RequirePermission)('settings.manage', 'ALL'),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", []),
    __metadata("design:returntype", Promise)
], IntegrationsController.prototype, "list", null);
__decorate([
    (0, common_1.Post)('lead-forms'),
    (0, decorators_1.RequirePermission)('settings.manage', 'ALL'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.leadFormSchema))),
    __param(2, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object, Object]),
    __metadata("design:returntype", Promise)
], IntegrationsController.prototype, "create", null);
__decorate([
    (0, common_1.Put)('lead-forms/:id'),
    (0, decorators_1.RequirePermission)('settings.manage', 'ALL'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.leadFormSchema))),
    __param(3, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object, Object]),
    __metadata("design:returntype", Promise)
], IntegrationsController.prototype, "update", null);
__decorate([
    (0, common_1.Delete)('lead-forms/:id'),
    (0, common_1.HttpCode)(204),
    (0, decorators_1.RequirePermission)('settings.manage', 'ALL'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object]),
    __metadata("design:returntype", Promise)
], IntegrationsController.prototype, "remove", null);
__decorate([
    (0, common_1.Get)('lead-forms/:id/submissions'),
    (0, decorators_1.RequirePermission)('settings.manage', 'ALL'),
    __param(0, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [String]),
    __metadata("design:returntype", Promise)
], IntegrationsController.prototype, "submissions", null);
__decorate([
    (0, common_1.Get)('settings/integrations'),
    (0, decorators_1.RequirePermission)('settings.manage', 'ALL'),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", []),
    __metadata("design:returntype", Promise)
], IntegrationsController.prototype, "settings", null);
__decorate([
    (0, common_1.Put)('settings/integrations'),
    (0, decorators_1.RequirePermission)('settings.manage', 'ALL'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.integrationSettingsSchema))),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object]),
    __metadata("design:returntype", Promise)
], IntegrationsController.prototype, "saveSettings", null);
__decorate([
    (0, common_1.Get)('integrations/meta'),
    (0, decorators_1.RequirePermission)('settings.manage', 'ALL'),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", []),
    __metadata("design:returntype", Promise)
], IntegrationsController.prototype, "metaStatus", null);
exports.IntegrationsController = IntegrationsController = __decorate([
    (0, common_1.Controller)(),
    __metadata("design:paramtypes", [forms_service_1.FormsService,
        intake_service_1.IntakeService,
        meta_service_1.MetaService])
], IntegrationsController);
/** «Входящие»: Директ и комментарии Instagram/Facebook. */
let InboxController = class InboxController {
    inbox;
    constructor(inbox) {
        this.inbox = inbox;
    }
    list(auth, q) {
        return this.inbox.list(auth, q);
    }
    messages(auth, id) {
        return this.inbox.messages(auth, id);
    }
    reply(auth, id, body) {
        return this.inbox.reply(auth, id, body);
    }
    lead(auth, id) {
        return this.inbox.createLead(auth, id);
    }
};
exports.InboxController = InboxController;
__decorate([
    (0, common_1.Get)(),
    (0, decorators_1.RequirePermission)('lead.read'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Query)((0, zod_pipe_1.zod)(contracts_1.inboxListQuerySchema))),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object]),
    __metadata("design:returntype", void 0)
], InboxController.prototype, "list", null);
__decorate([
    (0, common_1.Get)(':id/messages'),
    (0, decorators_1.RequirePermission)('lead.read'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String]),
    __metadata("design:returntype", Promise)
], InboxController.prototype, "messages", null);
__decorate([
    (0, common_1.Post)(':id/reply'),
    (0, common_1.HttpCode)(200),
    (0, decorators_1.RequirePermission)('lead.update'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.socialReplySchema))),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object]),
    __metadata("design:returntype", Promise)
], InboxController.prototype, "reply", null);
__decorate([
    (0, common_1.Post)(':id/lead'),
    (0, common_1.HttpCode)(200),
    (0, decorators_1.RequirePermission)('lead.create'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String]),
    __metadata("design:returntype", Promise)
], InboxController.prototype, "lead", null);
exports.InboxController = InboxController = __decorate([
    (0, common_1.Controller)('inbox'),
    __metadata("design:paramtypes", [inbox_service_1.InboxService])
], InboxController);
const esc = (s) => s.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);
/** Публичные адреса: форма для сайта и webhook Meta. Без входа, без проверки Origin. */
let PublicIntakeController = class PublicIntakeController {
    forms;
    meta;
    constructor(forms, meta) {
        this.forms = forms;
        this.meta = meta;
    }
    form(key) {
        return this.forms.publicForm(key);
    }
    /**
     * Заявка: JSON { data, utm, page } (наша форма) или обычная HTML-форма / webhook
     * плагина WordPress (поля плоско: name=…&phone=…&utm_source=…).
     */
    async submit(key, raw, req, res) {
        const flat = !raw || typeof raw.data !== 'object';
        const body = flat
            ? {
                data: Object.fromEntries(Object.entries(raw ?? {}).filter(([k, v]) => typeof v === 'string' && !k.startsWith('utm_') && !['website', 'page'].includes(k))),
                utm: Object.fromEntries(Object.entries(raw ?? {}).filter(([k, v]) => k.startsWith('utm_') && typeof v === 'string')),
                page: raw?.page ?? req.get('referer'),
                website: raw?.website,
            }
            : raw;
        const input = (0, zod_pipe_1.zod)(contracts_1.formSubmitSchema).transform(body);
        const result = await this.forms.submit(key, input, req.ip ?? null);
        // Обычная HTML-форма без JavaScript — показываем страницу «Спасибо»
        if (flat &&
            req.is('application/x-www-form-urlencoded') &&
            req.accepts(['json', 'html']) === 'html') {
            res.setHeader('Content-Type', 'text/html; charset=utf-8');
            const back = req.get('referer');
            return `<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Спасибо</title><body style="font-family:system-ui;max-width:480px;margin:15vh auto;padding:0 16px;text-align:center"><h2>${esc(result.message)}</h2>${back ? `<p><a href="${esc(back)}">Вернуться на сайт</a></p>` : ''}</body>`;
        }
        return result;
    }
    verify(q) {
        return this.meta.verify(q['hub.mode'], q['hub.verify_token'], q['hub.challenge']);
    }
    async webhook(req, body) {
        this.meta.checkSignature(req.rawBody, req.get('x-hub-signature-256'));
        await this.meta.handle(body);
        return { ok: true };
    }
};
exports.PublicIntakeController = PublicIntakeController;
__decorate([
    (0, common_1.Get)('forms/:key'),
    __param(0, (0, common_1.Param)('key')),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [String]),
    __metadata("design:returntype", Promise)
], PublicIntakeController.prototype, "form", null);
__decorate([
    (0, common_1.Post)('forms/:key'),
    (0, common_1.HttpCode)(200),
    (0, throttler_1.Throttle)({ default: { limit: 10, ttl: 60_000 } }),
    __param(0, (0, common_1.Param)('key')),
    __param(1, (0, common_1.Body)()),
    __param(2, (0, common_1.Req)()),
    __param(3, (0, common_1.Res)({ passthrough: true })),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [String, Object, Object, Object]),
    __metadata("design:returntype", Promise)
], PublicIntakeController.prototype, "submit", null);
__decorate([
    (0, common_1.Get)('meta/webhook'),
    (0, common_1.Header)('Content-Type', 'text/plain'),
    __param(0, (0, common_1.Query)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object]),
    __metadata("design:returntype", String)
], PublicIntakeController.prototype, "verify", null);
__decorate([
    (0, common_1.Post)('meta/webhook'),
    (0, common_1.HttpCode)(200),
    __param(0, (0, common_1.Req)()),
    __param(1, (0, common_1.Body)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object]),
    __metadata("design:returntype", Promise)
], PublicIntakeController.prototype, "webhook", null);
exports.PublicIntakeController = PublicIntakeController = __decorate([
    (0, common_1.Controller)('public'),
    (0, decorators_1.Public)(),
    (0, decorators_1.CrossOrigin)(),
    __metadata("design:paramtypes", [forms_service_1.FormsService,
        meta_service_1.MetaService])
], PublicIntakeController);
//# sourceMappingURL=integrations.controller.js.map