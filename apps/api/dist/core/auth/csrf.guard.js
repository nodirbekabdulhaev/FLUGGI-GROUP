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
exports.CsrfGuard = void 0;
const node_crypto_1 = require("node:crypto");
const common_1 = require("@nestjs/common");
const core_1 = require("@nestjs/core");
const contracts_1 = require("@fluggi/contracts");
const env_1 = require("../../config/env");
const app_exception_1 = require("../http/app.exception");
const decorators_1 = require("./decorators");
const SAFE_METHODS = new Set(['GET', 'HEAD', 'OPTIONS']);
function safeEqual(a, b) {
    const ab = Buffer.from(a);
    const bb = Buffer.from(b);
    return ab.length === bb.length && (0, node_crypto_1.timingSafeEqual)(ab, bb);
}
/**
 * CSRF-защита изменяющих запросов:
 *  1. Origin (если браузер его прислал) должен совпадать с APP_URL;
 *  2. для вошедшего пользователя — double submit: заголовок X-CSRF-Token = cookie.
 * Плюс SameSite=Lax у сессионной cookie.
 */
let CsrfGuard = class CsrfGuard {
    reflector;
    constructor(reflector) {
        this.reflector = reflector;
    }
    canActivate(context) {
        const req = context.switchToHttp().getRequest();
        if (SAFE_METHODS.has(req.method))
            return true;
        if (this.reflector.getAllAndOverride(decorators_1.CROSS_ORIGIN, [
            context.getHandler(),
            context.getClass(),
        ]))
            return true;
        const origin = req.get('origin');
        if (origin && origin !== new URL((0, env_1.loadEnv)().APP_URL).origin) {
            throw new app_exception_1.AppException('CSRF_INVALID', 'Запрос отклонён: недопустимый источник');
        }
        if (req.auth) {
            const cookie = req.cookies?.[contracts_1.CSRF_COOKIE];
            const header = req.get(contracts_1.CSRF_HEADER);
            if (typeof cookie !== 'string' || !header || !safeEqual(cookie, header)) {
                throw new app_exception_1.AppException('CSRF_INVALID', 'Сессия устарела. Обновите страницу');
            }
        }
        return true;
    }
};
exports.CsrfGuard = CsrfGuard;
exports.CsrfGuard = CsrfGuard = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [core_1.Reflector])
], CsrfGuard);
//# sourceMappingURL=csrf.guard.js.map