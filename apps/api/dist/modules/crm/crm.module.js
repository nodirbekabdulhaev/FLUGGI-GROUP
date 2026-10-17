"use strict";
var __decorate = (this && this.__decorate) || function (decorators, target, key, desc) {
    var c = arguments.length, r = c < 3 ? target : desc === null ? desc = Object.getOwnPropertyDescriptor(target, key) : desc, d;
    if (typeof Reflect === "object" && typeof Reflect.decorate === "function") r = Reflect.decorate(decorators, target, key, desc);
    else for (var i = decorators.length - 1; i >= 0; i--) if (d = decorators[i]) r = (c < 3 ? d(r) : c > 3 ? d(target, key, r) : d(target, key)) || r;
    return c > 3 && r && Object.defineProperty(target, key, r), r;
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.CrmModule = void 0;
const common_1 = require("@nestjs/common");
const activity_service_1 = require("./activity.service");
const crm_access_service_1 = require("./crm-access.service");
const timeline_controller_1 = require("./timeline.controller");
let CrmModule = class CrmModule {
};
exports.CrmModule = CrmModule;
exports.CrmModule = CrmModule = __decorate([
    (0, common_1.Global)(),
    (0, common_1.Module)({
        controllers: [timeline_controller_1.TimelineController],
        providers: [crm_access_service_1.CrmAccessService, activity_service_1.ActivityService],
        exports: [crm_access_service_1.CrmAccessService, activity_service_1.ActivityService],
    })
], CrmModule);
//# sourceMappingURL=crm.module.js.map