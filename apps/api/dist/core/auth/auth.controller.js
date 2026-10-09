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
exports.AuthController = void 0;
const common_1 = require("@nestjs/common");
const throttler_1 = require("@nestjs/throttler");
const contracts_1 = require("@fluggi/contracts");
const app_exception_1 = require("../http/app.exception");
const uuid_pipe_1 = require("../http/uuid.pipe");
const zod_pipe_1 = require("../http/zod.pipe");
const auth_service_1 = require("./auth.service");
const cookies_1 = require("./cookies");
const decorators_1 = require("./decorators");
const session_service_1 = require("./session.service");
let AuthController = class AuthController {
    auth;
    sessions;
    constructor(auth, sessions) {
        this.auth = auth;
        this.sessions = sessions;
    }
    async login(body, meta, res) {
        const { token, expiresAt } = await this.auth.login(body, meta);
        (0, cookies_1.setSessionCookie)(res, token, expiresAt);
        (0, cookies_1.issueCsrfCookie)(res);
        const resolved = await this.sessions.resolve(token);
        if (!resolved)
            throw new app_exception_1.AppException('INTERNAL', 'Не удалось создать сессию');
        return this.auth.toMe(resolved.auth);
    }
    async logout(req, meta, res) {
        if (req.auth)
            await this.auth.logout(req.auth, meta);
        (0, cookies_1.clearAuthCookies)(res);
    }
    me(user) {
        return this.auth.toMe(user);
    }
    async changePassword(user, body, meta) {
        await this.auth.changePassword(user, body, meta);
    }
    sessionsList(user) {
        return this.auth.listSessions(user);
    }
    async revoke(user, id, meta) {
        await this.auth.revokeSession(user, id, meta);
    }
};
exports.AuthController = AuthController;
__decorate([
    (0, common_1.Post)('login'),
    (0, decorators_1.Public)(),
    (0, common_1.HttpCode)(200),
    (0, throttler_1.Throttle)({ default: { limit: 5, ttl: 60_000 } }),
    __param(0, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.loginSchema))),
    __param(1, (0, decorators_1.ReqMeta)()),
    __param(2, (0, common_1.Res)({ passthrough: true })),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object, Object]),
    __metadata("design:returntype", Promise)
], AuthController.prototype, "login", null);
__decorate([
    (0, common_1.Post)('logout'),
    (0, decorators_1.Public)(),
    (0, common_1.HttpCode)(204),
    __param(0, (0, common_1.Req)()),
    __param(1, (0, decorators_1.ReqMeta)()),
    __param(2, (0, common_1.Res)({ passthrough: true })),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object, Object]),
    __metadata("design:returntype", Promise)
], AuthController.prototype, "logout", null);
__decorate([
    (0, common_1.Get)('me'),
    (0, decorators_1.AuthenticatedOnly)(),
    __param(0, (0, decorators_1.CurrentUser)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object]),
    __metadata("design:returntype", Object)
], AuthController.prototype, "me", null);
__decorate([
    (0, common_1.Post)('change-password'),
    (0, decorators_1.AuthenticatedOnly)(),
    (0, common_1.HttpCode)(204),
    (0, throttler_1.Throttle)({ default: { limit: 5, ttl: 60_000 } }),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.changePasswordSchema))),
    __param(2, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object, Object]),
    __metadata("design:returntype", Promise)
], AuthController.prototype, "changePassword", null);
__decorate([
    (0, common_1.Get)('sessions'),
    (0, decorators_1.AuthenticatedOnly)(),
    __param(0, (0, decorators_1.CurrentUser)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object]),
    __metadata("design:returntype", Promise)
], AuthController.prototype, "sessionsList", null);
__decorate([
    (0, common_1.Delete)('sessions/:id'),
    (0, decorators_1.AuthenticatedOnly)(),
    (0, common_1.HttpCode)(204),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object]),
    __metadata("design:returntype", Promise)
], AuthController.prototype, "revoke", null);
exports.AuthController = AuthController = __decorate([
    (0, common_1.Controller)('auth'),
    __metadata("design:paramtypes", [auth_service_1.AuthService,
        session_service_1.SessionService])
], AuthController);
//# sourceMappingURL=auth.controller.js.map