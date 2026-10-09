"use strict";
var __decorate = (this && this.__decorate) || function (decorators, target, key, desc) {
    var c = arguments.length, r = c < 3 ? target : desc === null ? desc = Object.getOwnPropertyDescriptor(target, key) : desc, d;
    if (typeof Reflect === "object" && typeof Reflect.decorate === "function") r = Reflect.decorate(decorators, target, key, desc);
    else for (var i = decorators.length - 1; i >= 0; i--) if (d = decorators[i]) r = (c < 3 ? d(r) : c > 3 ? d(target, key, r) : d(target, key)) || r;
    return c > 3 && r && Object.defineProperty(target, key, r), r;
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.ReferencesModule = void 0;
const common_1 = require("@nestjs/common");
const exchange_rate_service_1 = require("./exchange-rate.service");
const references_controller_1 = require("./references.controller");
const references_service_1 = require("./references.service");
let ReferencesModule = class ReferencesModule {
};
exports.ReferencesModule = ReferencesModule;
exports.ReferencesModule = ReferencesModule = __decorate([
    (0, common_1.Global)(),
    (0, common_1.Module)({
        controllers: [references_controller_1.ReferencesController],
        providers: [references_service_1.ReferencesService, exchange_rate_service_1.ExchangeRateService],
        exports: [exchange_rate_service_1.ExchangeRateService],
    })
], ReferencesModule);
//# sourceMappingURL=references.module.js.map