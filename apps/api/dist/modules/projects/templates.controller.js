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
exports.TemplatesController = void 0;
const common_1 = require("@nestjs/common");
const contracts_1 = require("@fluggi/contracts");
const decorators_1 = require("../../core/auth/decorators");
const app_exception_1 = require("../../core/http/app.exception");
const uuid_pipe_1 = require("../../core/http/uuid.pipe");
const zod_pipe_1 = require("../../core/http/zod.pipe");
const templates_service_1 = require("./templates.service");
/** Шаблоны проектов (ТЗ §62): настраивает CEO/админ, применяет руководитель проекта. */
let TemplatesController = class TemplatesController {
    templates;
    constructor(templates) {
        this.templates = templates;
    }
    list(auth) {
        const manage = Boolean(auth.permissions['reference.manage']);
        if (!manage && !auth.permissions['project.update'])
            throw (0, app_exception_1.forbidden)();
        // Для применения к проекту — только активные; в настройках — все.
        return this.templates.list(!manage);
    }
    create(auth, body, meta) {
        return this.templates.create(auth, body, meta);
    }
    update(auth, id, body, meta) {
        return this.templates.update(auth, id, body, meta);
    }
};
exports.TemplatesController = TemplatesController;
__decorate([
    (0, common_1.Get)(),
    (0, decorators_1.AuthenticatedOnly)(),
    __param(0, (0, decorators_1.CurrentUser)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object]),
    __metadata("design:returntype", Promise)
], TemplatesController.prototype, "list", null);
__decorate([
    (0, common_1.Post)(),
    (0, decorators_1.RequirePermission)('reference.manage'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.upsertProjectTemplateSchema))),
    __param(2, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object, Object]),
    __metadata("design:returntype", Promise)
], TemplatesController.prototype, "create", null);
__decorate([
    (0, common_1.Put)(':id'),
    (0, decorators_1.RequirePermission)('reference.manage'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.upsertProjectTemplateSchema))),
    __param(3, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object, Object]),
    __metadata("design:returntype", Promise)
], TemplatesController.prototype, "update", null);
exports.TemplatesController = TemplatesController = __decorate([
    (0, common_1.Controller)('project-templates'),
    __metadata("design:paramtypes", [templates_service_1.TemplatesService])
], TemplatesController);
//# sourceMappingURL=templates.controller.js.map