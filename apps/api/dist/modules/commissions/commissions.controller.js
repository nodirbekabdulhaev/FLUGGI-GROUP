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
exports.CommissionsController = void 0;
const common_1 = require("@nestjs/common");
const contracts_1 = require("@fluggi/contracts");
const decorators_1 = require("../../core/auth/decorators");
const uuid_pipe_1 = require("../../core/http/uuid.pipe");
const zod_pipe_1 = require("../../core/http/zod.pipe");
const commissions_service_1 = require("./commissions.service");
let CommissionsController = class CommissionsController {
    commissions;
    constructor(commissions) {
        this.commissions = commissions;
    }
    list(auth, q) {
        return this.commissions.list(auth, q);
    }
    approve(auth, body, meta) {
        return this.commissions.transition(auth, body.ids, 'APPROVED', meta);
    }
    pay(auth, body, meta) {
        return this.commissions.transition(auth, body.ids, 'PAID', meta);
    }
    rules() {
        return this.commissions.rules();
    }
    async create(auth, body, meta) {
        return { id: await this.commissions.upsertRule(auth, null, body, meta) };
    }
    async update(auth, id, body, meta) {
        return { id: await this.commissions.upsertRule(auth, id, body, meta) };
    }
};
exports.CommissionsController = CommissionsController;
__decorate([
    (0, common_1.Get)('commissions'),
    (0, decorators_1.RequirePermission)('commission.read'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Query)((0, zod_pipe_1.zod)(contracts_1.commissionListQuerySchema))),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object]),
    __metadata("design:returntype", void 0)
], CommissionsController.prototype, "list", null);
__decorate([
    (0, common_1.Post)('commissions/approve'),
    (0, common_1.HttpCode)(200),
    (0, decorators_1.RequirePermission)('commission.approve', 'ALL'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.commissionIdsSchema))),
    __param(2, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object, Object]),
    __metadata("design:returntype", void 0)
], CommissionsController.prototype, "approve", null);
__decorate([
    (0, common_1.Post)('commissions/pay'),
    (0, common_1.HttpCode)(200),
    (0, decorators_1.RequirePermission)('commission.approve', 'ALL'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.commissionIdsSchema))),
    __param(2, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object, Object]),
    __metadata("design:returntype", void 0)
], CommissionsController.prototype, "pay", null);
__decorate([
    (0, common_1.Get)('commission-rules'),
    (0, decorators_1.RequirePermission)('commission_rule.manage', 'ALL'),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", []),
    __metadata("design:returntype", Promise)
], CommissionsController.prototype, "rules", null);
__decorate([
    (0, common_1.Post)('commission-rules'),
    (0, decorators_1.RequirePermission)('commission_rule.manage', 'ALL'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.upsertCommissionRuleSchema))),
    __param(2, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object, Object]),
    __metadata("design:returntype", Promise)
], CommissionsController.prototype, "create", null);
__decorate([
    (0, common_1.Put)('commission-rules/:id'),
    (0, decorators_1.RequirePermission)('commission_rule.manage', 'ALL'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.upsertCommissionRuleSchema))),
    __param(3, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object, Object]),
    __metadata("design:returntype", Promise)
], CommissionsController.prototype, "update", null);
exports.CommissionsController = CommissionsController = __decorate([
    (0, common_1.Controller)(),
    __metadata("design:paramtypes", [commissions_service_1.CommissionsService])
], CommissionsController);
//# sourceMappingURL=commissions.controller.js.map