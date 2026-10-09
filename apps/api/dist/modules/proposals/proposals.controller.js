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
exports.ProposalsController = void 0;
const common_1 = require("@nestjs/common");
const contracts_1 = require("@fluggi/contracts");
const decorators_1 = require("../../core/auth/decorators");
const uuid_pipe_1 = require("../../core/http/uuid.pipe");
const zod_pipe_1 = require("../../core/http/zod.pipe");
const proposals_service_1 = require("./proposals.service");
let ProposalsController = class ProposalsController {
    proposals;
    constructor(proposals) {
        this.proposals = proposals;
    }
    list(auth, q) {
        return this.proposals.list(auth, q);
    }
    get(auth, id) {
        return this.proposals.get(auth, id);
    }
    versions(auth, id) {
        return this.proposals.versions(auth, id);
    }
    create(auth, body, meta) {
        return this.proposals.create(auth, body, meta);
    }
    update(auth, id, body, meta) {
        return this.proposals.update(auth, id, body, meta);
    }
    submit(auth, id, meta) {
        return this.proposals.submitApproval(auth, id, meta);
    }
    approve(auth, id, meta) {
        return this.proposals.approve(auth, id, meta);
    }
    send(auth, id, meta) {
        return this.proposals.send(auth, id, meta);
    }
    viewed(auth, id, meta) {
        return this.proposals.markViewed(auth, id, meta);
    }
    accept(auth, id, meta) {
        return this.proposals.accept(auth, id, meta);
    }
    reject(auth, id, meta) {
        return this.proposals.reject(auth, id, meta);
    }
};
exports.ProposalsController = ProposalsController;
__decorate([
    (0, common_1.Get)(),
    (0, decorators_1.RequirePermission)('proposal.read'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Query)((0, zod_pipe_1.zod)(contracts_1.proposalListQuerySchema))),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object]),
    __metadata("design:returntype", Promise)
], ProposalsController.prototype, "list", null);
__decorate([
    (0, common_1.Get)(':id'),
    (0, decorators_1.RequirePermission)('proposal.read'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String]),
    __metadata("design:returntype", Promise)
], ProposalsController.prototype, "get", null);
__decorate([
    (0, common_1.Get)(':id/versions'),
    (0, decorators_1.RequirePermission)('proposal.read'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String]),
    __metadata("design:returntype", Promise)
], ProposalsController.prototype, "versions", null);
__decorate([
    (0, common_1.Post)(),
    (0, decorators_1.RequirePermission)('proposal.create'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.createProposalSchema))),
    __param(2, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object, Object]),
    __metadata("design:returntype", Promise)
], ProposalsController.prototype, "create", null);
__decorate([
    (0, common_1.Put)(':id'),
    (0, decorators_1.RequirePermission)('proposal.update'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.upsertProposalSchema))),
    __param(3, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object, Object]),
    __metadata("design:returntype", Promise)
], ProposalsController.prototype, "update", null);
__decorate([
    (0, common_1.Post)(':id/submit-approval'),
    (0, common_1.HttpCode)(200),
    (0, decorators_1.RequirePermission)('proposal.update'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object]),
    __metadata("design:returntype", void 0)
], ProposalsController.prototype, "submit", null);
__decorate([
    (0, common_1.Post)(':id/approve'),
    (0, common_1.HttpCode)(200),
    (0, decorators_1.RequirePermission)('proposal.approve'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object]),
    __metadata("design:returntype", void 0)
], ProposalsController.prototype, "approve", null);
__decorate([
    (0, common_1.Post)(':id/send'),
    (0, common_1.HttpCode)(200),
    (0, decorators_1.RequirePermission)('proposal.send'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object]),
    __metadata("design:returntype", void 0)
], ProposalsController.prototype, "send", null);
__decorate([
    (0, common_1.Post)(':id/mark-viewed'),
    (0, common_1.HttpCode)(200),
    (0, decorators_1.RequirePermission)('proposal.update'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object]),
    __metadata("design:returntype", void 0)
], ProposalsController.prototype, "viewed", null);
__decorate([
    (0, common_1.Post)(':id/accept'),
    (0, common_1.HttpCode)(200),
    (0, decorators_1.RequirePermission)('proposal.update'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object]),
    __metadata("design:returntype", void 0)
], ProposalsController.prototype, "accept", null);
__decorate([
    (0, common_1.Post)(':id/reject'),
    (0, common_1.HttpCode)(200),
    (0, decorators_1.RequirePermission)('proposal.update'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object]),
    __metadata("design:returntype", void 0)
], ProposalsController.prototype, "reject", null);
exports.ProposalsController = ProposalsController = __decorate([
    (0, common_1.Controller)('proposals'),
    __metadata("design:paramtypes", [proposals_service_1.ProposalsService])
], ProposalsController);
//# sourceMappingURL=proposals.controller.js.map