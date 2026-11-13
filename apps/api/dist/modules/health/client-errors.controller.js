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
exports.ClientErrorsController = void 0;
const common_1 = require("@nestjs/common");
const zod_1 = require("zod");
const decorators_1 = require("../../core/auth/decorators");
const zod_pipe_1 = require("../../core/http/zod.pipe");
const clientErrorSchema = zod_1.z.object({
    widget: zod_1.z.string().trim().max(40),
    message: zod_1.z.string().max(1000),
    stack: zod_1.z.string().max(4000).optional(),
    path: zod_1.z.string().max(300).optional(),
    userAgent: zod_1.z.string().max(300).optional(),
});
/**
 * Ошибки интерфейса (чат, «Список дел»): браузер присылает текст ошибки, сервер пишет его в лог —
 * так причину видно в логе API, даже если у пользователя она не воспроизводится у нас.
 */
let ClientErrorsController = class ClientErrorsController {
    logger = new common_1.Logger('ClientError');
    report(auth, body) {
        this.logger.warn(`[${body.widget}] ${body.message} | user=${auth.userId} path=${body.path ?? ''} ua=${body.userAgent ?? ''}\n${body.stack ?? ''}`);
    }
};
exports.ClientErrorsController = ClientErrorsController;
__decorate([
    (0, common_1.Post)(),
    (0, common_1.HttpCode)(204),
    (0, decorators_1.AuthenticatedOnly)(),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Body)((0, zod_pipe_1.zod)(clientErrorSchema))),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object]),
    __metadata("design:returntype", void 0)
], ClientErrorsController.prototype, "report", null);
exports.ClientErrorsController = ClientErrorsController = __decorate([
    (0, common_1.Controller)('client-errors')
], ClientErrorsController);
//# sourceMappingURL=client-errors.controller.js.map