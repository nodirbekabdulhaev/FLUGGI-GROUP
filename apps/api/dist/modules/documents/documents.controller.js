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
exports.DocumentsController = void 0;
const common_1 = require("@nestjs/common");
const contracts_1 = require("@fluggi/contracts");
const zod_1 = require("zod");
const decorators_1 = require("../../core/auth/decorators");
const uuid_pipe_1 = require("../../core/http/uuid.pipe");
const zod_pipe_1 = require("../../core/http/zod.pipe");
const documents_service_1 = require("./documents.service");
const render_docx_1 = require("./render-docx");
const render_html_1 = require("./render-html");
const render_pdf_1 = require("./render-pdf");
const render_txt_1 = require("./render-txt");
const formatQuery = zod_1.z.object({
    format: zod_1.z.enum(contracts_1.DOCUMENT_FORMATS).default('html'),
    download: zod_1.z.enum(['true', 'false']).optional(),
});
const TRANSLIT = { Д: 'D', Г: 'G', К: 'K', П: 'P' };
/**
 * Имя файла латиницей: «ДГ-0001» → «DG-0001». Только filename="…" — с filename*=UTF-8''
 * Chromium в части сборок отдаёт имя «download».
 */
const asciiName = (name) => name.replace(/[^\x20-\x7e]/g, (ch) => TRANSLIT[ch] ?? '_').replace(/["\\]/g, '_');
const TYPES = {
    html: 'text/html; charset=utf-8',
    txt: 'text/plain; charset=utf-8',
    pdf: 'application/pdf',
    docx: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
};
/** Генерация КП и договора с закреплённым дизайном: HTML (просмотр), PDF, Word, TXT. */
let DocumentsController = class DocumentsController {
    docs;
    constructor(docs) {
        this.docs = docs;
    }
    async send(model, q, res) {
        const body = q.format === 'html'
            ? Buffer.from((0, render_html_1.renderHtml)(model))
            : q.format === 'txt'
                ? Buffer.from((0, render_txt_1.renderTxt)(model))
                : q.format === 'pdf'
                    ? await (0, render_pdf_1.pdfBuffer)(model)
                    : await (0, render_docx_1.docxBuffer)(model);
        const name = `${model.fileName}.${q.format}`;
        res.setHeader('Content-Type', TYPES[q.format]);
        res.setHeader('Content-Disposition', `${q.download === 'true' ? 'attachment' : 'inline'}; filename="${asciiName(name)}"`);
        res.setHeader('Cache-Control', 'no-store');
        return new common_1.StreamableFile(body);
    }
    async proposal(auth, id, q, res) {
        return this.send(await this.docs.proposalModel(auth, id), q, res);
    }
    async contract(auth, id, q, res) {
        return this.send(await this.docs.contractModel(auth, id), q, res);
    }
    checkProposal(auth, id) {
        return this.docs.check(auth, 'proposals', id);
    }
    checkContract(auth, id) {
        return this.docs.check(auth, 'contracts', id);
    }
    settings() {
        return this.docs.documentSettings();
    }
    saveSettings(auth, body, meta) {
        return this.docs.saveDocumentSettings(auth, body, meta);
    }
    saveRequisites(auth, id, body, meta) {
        return this.docs.saveClientRequisites(auth, id, body, meta);
    }
};
exports.DocumentsController = DocumentsController;
__decorate([
    (0, common_1.Get)('documents/proposals/:id'),
    (0, decorators_1.RequirePermission)('proposal.read'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, common_1.Query)((0, zod_pipe_1.zod)(formatQuery))),
    __param(3, (0, common_1.Res)({ passthrough: true })),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object, Object]),
    __metadata("design:returntype", Promise)
], DocumentsController.prototype, "proposal", null);
__decorate([
    (0, common_1.Get)('documents/contracts/:id'),
    (0, decorators_1.RequirePermission)('contract.read'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, common_1.Query)((0, zod_pipe_1.zod)(formatQuery))),
    __param(3, (0, common_1.Res)({ passthrough: true })),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object, Object]),
    __metadata("design:returntype", Promise)
], DocumentsController.prototype, "contract", null);
__decorate([
    (0, common_1.Get)('documents/proposals/:id/check'),
    (0, decorators_1.RequirePermission)('proposal.read'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String]),
    __metadata("design:returntype", Promise)
], DocumentsController.prototype, "checkProposal", null);
__decorate([
    (0, common_1.Get)('documents/contracts/:id/check'),
    (0, decorators_1.RequirePermission)('contract.read'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String]),
    __metadata("design:returntype", Promise)
], DocumentsController.prototype, "checkContract", null);
__decorate([
    (0, common_1.Get)('settings/documents'),
    (0, decorators_1.RequirePermission)('settings.manage', 'ALL'),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", []),
    __metadata("design:returntype", Promise)
], DocumentsController.prototype, "settings", null);
__decorate([
    (0, common_1.Put)('settings/documents'),
    (0, decorators_1.RequirePermission)('settings.manage', 'ALL'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.documentSettingsSchema))),
    __param(2, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object, Object]),
    __metadata("design:returntype", Promise)
], DocumentsController.prototype, "saveSettings", null);
__decorate([
    (0, common_1.Put)('clients/:id/requisites'),
    (0, decorators_1.RequirePermission)('client.update'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.clientRequisitesSchema))),
    __param(3, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object, Object]),
    __metadata("design:returntype", Promise)
], DocumentsController.prototype, "saveRequisites", null);
exports.DocumentsController = DocumentsController = __decorate([
    (0, common_1.Controller)(),
    __metadata("design:paramtypes", [documents_service_1.DocumentsService])
], DocumentsController);
//# sourceMappingURL=documents.controller.js.map