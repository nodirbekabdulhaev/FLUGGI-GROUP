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
var PermissionGuard_1;
Object.defineProperty(exports, "__esModule", { value: true });
exports.PermissionGuard = void 0;
const common_1 = require("@nestjs/common");
const core_1 = require("@nestjs/core");
const contracts_1 = require("@fluggi/contracts");
const app_exception_1 = require("../http/app.exception");
const decorators_1 = require("../auth/decorators");
/**
 * Default deny: endpoint без @Public / @AuthenticatedOnly / @RequirePermission
 * недоступен никому. Так забытая проверка прав не превращается в дыру.
 */
let PermissionGuard = PermissionGuard_1 = class PermissionGuard {
    reflector;
    logger = new common_1.Logger(PermissionGuard_1.name);
    constructor(reflector) {
        this.reflector = reflector;
    }
    canActivate(context) {
        const targets = [context.getHandler(), context.getClass()];
        if (this.reflector.getAllAndOverride(decorators_1.IS_PUBLIC, targets))
            return true;
        const req = context.switchToHttp().getRequest();
        const auth = req.auth;
        const required = this.reflector.getAllAndOverride(decorators_1.REQUIRED_PERMISSION, targets);
        if (required) {
            if ((0, contracts_1.hasPermission)(auth.permissions, required.code, required.minScope))
                return true;
            throw (0, app_exception_1.forbidden)();
        }
        if (this.reflector.getAllAndOverride(decorators_1.AUTHENTICATED_ONLY, targets))
            return true;
        this.logger.error(`Endpoint ${req.method} ${req.path} не объявил требования к доступу`);
        throw (0, app_exception_1.forbidden)();
    }
};
exports.PermissionGuard = PermissionGuard;
exports.PermissionGuard = PermissionGuard = PermissionGuard_1 = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [core_1.Reflector])
], PermissionGuard);
//# sourceMappingURL=permission.guard.js.map