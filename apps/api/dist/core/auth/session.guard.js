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
exports.SessionGuard = void 0;
const common_1 = require("@nestjs/common");
const core_1 = require("@nestjs/core");
const contracts_1 = require("@fluggi/contracts");
const app_exception_1 = require("../http/app.exception");
const cookies_1 = require("./cookies");
const decorators_1 = require("./decorators");
const session_service_1 = require("./session.service");
/** Глобальный guard: все endpoint'ы требуют сессию, кроме помеченных @Public(). */
let SessionGuard = class SessionGuard {
    reflector;
    sessions;
    constructor(reflector, sessions) {
        this.reflector = reflector;
        this.sessions = sessions;
    }
    async canActivate(context) {
        const req = context.switchToHttp().getRequest();
        const isPublic = this.reflector.getAllAndOverride(decorators_1.IS_PUBLIC, [
            context.getHandler(),
            context.getClass(),
        ]);
        const res = context.switchToHttp().getResponse();
        const token = req.cookies?.[contracts_1.SESSION_COOKIE];
        if (typeof token === 'string' && token.length > 0 && token.length < 200) {
            const resolved = await this.sessions.resolve(token);
            if (resolved) {
                req.auth = resolved.auth;
                // Скользящая сессия: продлеваем и cookie, иначе браузер удалит её по старому сроку.
                if (resolved.renewedUntil)
                    (0, cookies_1.setSessionCookie)(res, token, resolved.renewedUntil);
            }
        }
        if (isPublic)
            return true;
        if (!req.auth)
            throw new app_exception_1.AppException('UNAUTHENTICATED', 'Сессия истекла. Войдите снова');
        // Гарантируем наличие CSRF-cookie у вошедшего пользователя.
        if (!req.cookies?.[contracts_1.CSRF_COOKIE]) {
            req.cookies[contracts_1.CSRF_COOKIE] = (0, cookies_1.issueCsrfCookie)(res);
        }
        return true;
    }
};
exports.SessionGuard = SessionGuard;
exports.SessionGuard = SessionGuard = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [core_1.Reflector,
        session_service_1.SessionService])
], SessionGuard);
//# sourceMappingURL=session.guard.js.map