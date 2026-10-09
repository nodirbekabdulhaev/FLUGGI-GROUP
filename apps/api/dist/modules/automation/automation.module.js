"use strict";
var __decorate = (this && this.__decorate) || function (decorators, target, key, desc) {
    var c = arguments.length, r = c < 3 ? target : desc === null ? desc = Object.getOwnPropertyDescriptor(target, key) : desc, d;
    if (typeof Reflect === "object" && typeof Reflect.decorate === "function") r = Reflect.decorate(decorators, target, key, desc);
    else for (var i = decorators.length - 1; i >= 0; i--) if (d = decorators[i]) r = (c < 3 ? d(r) : c > 3 ? d(target, key, r) : d(target, key)) || r;
    return c > 3 && r && Object.defineProperty(target, key, r), r;
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.AutomationModule = void 0;
const common_1 = require("@nestjs/common");
const analytics_module_1 = require("../analytics/analytics.module");
const deals_module_1 = require("../deals/deals.module");
const todos_module_1 = require("../todos/todos.module");
const notifications_module_1 = require("../notifications/notifications.module");
const people_module_1 = require("../people/people.module");
const overdue_module_1 = require("../projects/overdue.module");
const automation_controller_1 = require("./automation.controller");
const automation_events_1 = require("./automation.events");
const follow_ups_service_1 = require("./follow-ups.service");
const reminders_service_1 = require("./reminders.service");
const reports_service_1 = require("./reports.service");
const scheduler_service_1 = require("./scheduler.service");
const providers = [
    follow_ups_service_1.FollowUpsService,
    reminders_service_1.RemindersService,
    reports_service_1.ReportsService,
    scheduler_service_1.SchedulerService,
    automation_events_1.AutomationEvents,
];
let AutomationModule = class AutomationModule {
};
exports.AutomationModule = AutomationModule;
exports.AutomationModule = AutomationModule = __decorate([
    (0, common_1.Module)({
        imports: [
            deals_module_1.DealsModule,
            notifications_module_1.NotificationsModule,
            people_module_1.PeopleModule,
            overdue_module_1.OverdueModule,
            analytics_module_1.ClientInsightsModule,
            todos_module_1.TodosModule,
        ],
        controllers: [automation_controller_1.AutomationController],
        providers,
        exports: [scheduler_service_1.SchedulerService, reminders_service_1.RemindersService, reports_service_1.ReportsService],
    })
], AutomationModule);
//# sourceMappingURL=automation.module.js.map