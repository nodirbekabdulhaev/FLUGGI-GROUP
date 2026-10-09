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
exports.ReferencesController = void 0;
const common_1 = require("@nestjs/common");
const contracts_1 = require("@fluggi/contracts");
const decorators_1 = require("../../core/auth/decorators");
const app_exception_1 = require("../../core/http/app.exception");
const uuid_pipe_1 = require("../../core/http/uuid.pipe");
const zod_pipe_1 = require("../../core/http/zod.pipe");
const references_service_1 = require("./references.service");
let ReferencesController = class ReferencesController {
    refs;
    constructor(refs) {
        this.refs = refs;
    }
    /** Справочники нужны формам всех ролей. Финансовых данных здесь нет. */
    all() {
        return this.refs.all();
    }
    createService(auth, body, meta) {
        return this.refs.upsertService(auth, null, body, meta);
    }
    updateService(auth, id, body, meta) {
        return this.refs.upsertService(auth, id, body, meta);
    }
    createItem(auth, kind, body, meta) {
        return this.refs.upsertItem(auth, this.kind(kind), null, body, meta);
    }
    updateItem(auth, kind, id, body, meta) {
        return this.refs.upsertItem(auth, this.kind(kind), id, body, meta);
    }
    updateStage(auth, id, body, meta) {
        return this.refs.updateStage(auth, id, body, meta);
    }
    /** Курс валюты влияет на финансы — только CEO (finance.company.read). */
    setRate(auth, body, meta) {
        return this.refs.setRate(auth, body, meta);
    }
    kind(kind) {
        if (kind === 'sources' || kind === 'loss-reasons')
            return kind;
        throw (0, app_exception_1.notFound)('Справочник');
    }
};
exports.ReferencesController = ReferencesController;
__decorate([
    (0, common_1.Get)(),
    (0, decorators_1.AuthenticatedOnly)(),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", []),
    __metadata("design:returntype", Promise)
], ReferencesController.prototype, "all", null);
__decorate([
    (0, common_1.Post)('services'),
    (0, decorators_1.RequirePermission)('reference.manage', 'ALL'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.upsertServiceSchema))),
    __param(2, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object, Object]),
    __metadata("design:returntype", Promise)
], ReferencesController.prototype, "createService", null);
__decorate([
    (0, common_1.Put)('services/:id'),
    (0, decorators_1.RequirePermission)('reference.manage', 'ALL'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.upsertServiceSchema))),
    __param(3, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object, Object]),
    __metadata("design:returntype", Promise)
], ReferencesController.prototype, "updateService", null);
__decorate([
    (0, common_1.Post)(':kind'),
    (0, decorators_1.RequirePermission)('reference.manage', 'ALL'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('kind')),
    __param(2, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.upsertReferenceItemSchema))),
    __param(3, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object, Object]),
    __metadata("design:returntype", Promise)
], ReferencesController.prototype, "createItem", null);
__decorate([
    (0, common_1.Put)(':kind/:id'),
    (0, decorators_1.RequirePermission)('reference.manage', 'ALL'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('kind')),
    __param(2, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(3, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.upsertReferenceItemSchema))),
    __param(4, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, String, Object, Object]),
    __metadata("design:returntype", Promise)
], ReferencesController.prototype, "updateItem", null);
__decorate([
    (0, common_1.Patch)('stages/:id'),
    (0, decorators_1.RequirePermission)('reference.manage', 'ALL'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.updateStageSchema))),
    __param(3, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object, Object]),
    __metadata("design:returntype", Promise)
], ReferencesController.prototype, "updateStage", null);
__decorate([
    (0, common_1.Put)('exchange-rates'),
    (0, decorators_1.RequirePermission)('finance.company.read', 'ALL'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.setExchangeRateSchema))),
    __param(2, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object, Object]),
    __metadata("design:returntype", Promise)
], ReferencesController.prototype, "setRate", null);
exports.ReferencesController = ReferencesController = __decorate([
    (0, common_1.Controller)('references'),
    __metadata("design:paramtypes", [references_service_1.ReferencesService])
], ReferencesController);
//# sourceMappingURL=references.controller.js.map