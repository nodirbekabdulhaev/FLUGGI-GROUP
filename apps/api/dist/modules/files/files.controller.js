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
exports.FilesController = void 0;
const common_1 = require("@nestjs/common");
const platform_express_1 = require("@nestjs/platform-express");
const contracts_1 = require("@fluggi/contracts");
const zod_1 = require("zod");
const decorators_1 = require("../../core/auth/decorators");
const uuid_pipe_1 = require("../../core/http/uuid.pipe");
const zod_pipe_1 = require("../../core/http/zod.pipe");
const files_service_1 = require("./files.service");
const uploadBody = zod_1.z.object({
    dealId: zod_1.z.uuid().optional(),
    contractId: zod_1.z.uuid().optional(),
    proposalId: zod_1.z.uuid().optional(),
    paymentId: zod_1.z.uuid().optional(),
    projectId: zod_1.z.uuid().optional(),
    taskId: zod_1.z.uuid().optional(),
    category: zod_1.z.enum(contracts_1.FILE_CATEGORIES).default('DOCUMENT'),
});
const listQuery = zod_1.z.object({
    dealId: zod_1.z.uuid().optional(),
    projectId: zod_1.z.uuid().optional(),
    taskId: zod_1.z.uuid().optional(),
});
/** Файлы сделки, проекта и задач. Права проверяются по записи, к которой относится файл. */
let FilesController = class FilesController {
    files;
    constructor(files) {
        this.files = files;
    }
    upload(auth, file, body, meta) {
        const { category, ...target } = body;
        return this.files.upload(auth, file, target, category, meta);
    }
    list(auth, q) {
        return this.files.list(auth, q);
    }
    async download(auth, id, res) {
        const { file, stream, name } = await this.files.open(auth, id);
        res.setHeader('Content-Type', file.mimeType);
        res.setHeader('Content-Disposition', `attachment; filename*=UTF-8''${encodeURIComponent(name)}`);
        res.setHeader('X-Content-Type-Options', 'nosniff');
        return new common_1.StreamableFile(stream);
    }
    remove(auth, id, meta) {
        return this.files.remove(auth, id, meta);
    }
};
exports.FilesController = FilesController;
__decorate([
    (0, common_1.Post)(),
    (0, decorators_1.AuthenticatedOnly)(),
    (0, common_1.UseInterceptors)((0, platform_express_1.FileInterceptor)('file', { limits: { fileSize: contracts_1.MAX_FILE_BYTES, files: 1 } })),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.UploadedFile)()),
    __param(2, (0, common_1.Body)((0, zod_pipe_1.zod)(uploadBody))),
    __param(3, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object, Object, Object]),
    __metadata("design:returntype", Promise)
], FilesController.prototype, "upload", null);
__decorate([
    (0, common_1.Get)(),
    (0, decorators_1.AuthenticatedOnly)(),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Query)((0, zod_pipe_1.zod)(listQuery))),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object]),
    __metadata("design:returntype", Promise)
], FilesController.prototype, "list", null);
__decorate([
    (0, common_1.Get)(':id/download'),
    (0, decorators_1.AuthenticatedOnly)(),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, common_1.Res)({ passthrough: true })),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object]),
    __metadata("design:returntype", Promise)
], FilesController.prototype, "download", null);
__decorate([
    (0, common_1.Delete)(':id'),
    (0, common_1.HttpCode)(204),
    (0, decorators_1.AuthenticatedOnly)(),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object]),
    __metadata("design:returntype", Promise)
], FilesController.prototype, "remove", null);
exports.FilesController = FilesController = __decorate([
    (0, common_1.Controller)('files'),
    __metadata("design:paramtypes", [files_service_1.FilesService])
], FilesController);
//# sourceMappingURL=files.controller.js.map