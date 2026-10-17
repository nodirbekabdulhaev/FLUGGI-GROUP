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
exports.PeopleController = void 0;
const common_1 = require("@nestjs/common");
const contracts_1 = require("@fluggi/contracts");
const domain_1 = require("@fluggi/domain");
const zod_1 = require("zod");
const decorators_1 = require("../../core/auth/decorators");
const app_exception_1 = require("../../core/http/app.exception");
const uuid_pipe_1 = require("../../core/http/uuid.pipe");
const zod_pipe_1 = require("../../core/http/zod.pipe");
const dashboard_service_1 = require("./dashboard.service");
const kpi_service_1 = require("./kpi.service");
const people_service_1 = require("./people.service");
const targetsQuery = zod_1.z.object({ userId: zod_1.z.uuid(), period: contracts_1.monthSchema });
const periodBody = zod_1.z.object({ period: contracts_1.monthSchema });
/** Дашборд, KPI, цели, графики, посещаемость, зарплата (ТЗ §5, §28–32, §35–36, §58–60). */
let PeopleController = class PeopleController {
    kpi;
    people;
    dashboard;
    constructor(kpi, people, dashboard) {
        this.kpi = kpi;
        this.people = people;
        this.dashboard = dashboard;
    }
    getDashboard(auth, q) {
        return this.dashboard.get(auth, q);
    }
    // KPI и цели
    list(auth, q) {
        return this.kpi.rows(auth, q.period, (0, domain_1.monthRange)(q.period), { group: q.group });
    }
    targets(auth, q) {
        return this.kpi.targets(auth, q.userId, q.period);
    }
    setTargets(auth, body, meta) {
        return this.people.setTargets(auth, body, meta);
    }
    // Рабочие графики
    schedules(auth) {
        if (!auth.permissions['schedule.manage'] && !auth.permissions['attendance.manage'])
            throw (0, app_exception_1.forbidden)();
        return this.people.schedules();
    }
    createSchedule(auth, body, meta) {
        return this.people.upsertSchedule(auth, null, body, meta);
    }
    updateSchedule(auth, id, body, meta) {
        return this.people.upsertSchedule(auth, id, body, meta);
    }
    // Посещаемость
    today(auth) {
        return this.people.today(auth);
    }
    checkIn(auth, body) {
        return this.people.checkIn(auth, body.comment);
    }
    checkOut(auth, body) {
        return this.people.checkOut(auth, body.comment);
    }
    attendance(auth, q) {
        return this.people.list(auth, q);
    }
    attendanceSummary(auth, q) {
        return this.people.summary(auth, q);
    }
    upsertAttendance(auth, body, meta) {
        return this.people.upsertAttendance(auth, body, meta);
    }
    // Зарплата
    payroll(auth, q) {
        return this.people.payroll(auth, q.period);
    }
    calculate(auth, body, meta) {
        return this.people.calculate(auth, body.period, meta);
    }
    updatePayroll(auth, id, body, meta) {
        return this.people.updatePayroll(auth, id, body, meta);
    }
    approvePayroll(auth, body, meta) {
        return this.people.payrollTransition(auth, body.ids, 'APPROVED', meta);
    }
    payPayroll(auth, body, meta) {
        return this.people.payrollTransition(auth, body.ids, 'PAID', meta);
    }
};
exports.PeopleController = PeopleController;
__decorate([
    (0, common_1.Get)('dashboard'),
    (0, decorators_1.AuthenticatedOnly)(),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Query)((0, zod_pipe_1.zod)(contracts_1.periodQuerySchema))),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object]),
    __metadata("design:returntype", Promise)
], PeopleController.prototype, "getDashboard", null);
__decorate([
    (0, common_1.Get)('kpi'),
    (0, decorators_1.RequirePermission)('kpi.read'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Query)((0, zod_pipe_1.zod)(contracts_1.kpiQuerySchema))),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object]),
    __metadata("design:returntype", Promise)
], PeopleController.prototype, "list", null);
__decorate([
    (0, common_1.Get)('kpi/targets'),
    (0, decorators_1.RequirePermission)('kpi.read'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Query)((0, zod_pipe_1.zod)(targetsQuery))),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object]),
    __metadata("design:returntype", void 0)
], PeopleController.prototype, "targets", null);
__decorate([
    (0, common_1.Put)('kpi/targets'),
    (0, decorators_1.RequirePermission)('kpi.target.manage'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.setTargetsSchema))),
    __param(2, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object, Object]),
    __metadata("design:returntype", void 0)
], PeopleController.prototype, "setTargets", null);
__decorate([
    (0, common_1.Get)('work-schedules'),
    (0, decorators_1.AuthenticatedOnly)(),
    __param(0, (0, decorators_1.CurrentUser)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object]),
    __metadata("design:returntype", Promise)
], PeopleController.prototype, "schedules", null);
__decorate([
    (0, common_1.Post)('work-schedules'),
    (0, decorators_1.RequirePermission)('schedule.manage', 'ALL'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.upsertScheduleSchema))),
    __param(2, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object, Object]),
    __metadata("design:returntype", Promise)
], PeopleController.prototype, "createSchedule", null);
__decorate([
    (0, common_1.Put)('work-schedules/:id'),
    (0, decorators_1.RequirePermission)('schedule.manage', 'ALL'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.upsertScheduleSchema))),
    __param(3, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object, Object]),
    __metadata("design:returntype", Promise)
], PeopleController.prototype, "updateSchedule", null);
__decorate([
    (0, common_1.Get)('attendance/today'),
    (0, decorators_1.AuthenticatedOnly)(),
    __param(0, (0, decorators_1.CurrentUser)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object]),
    __metadata("design:returntype", Promise)
], PeopleController.prototype, "today", null);
__decorate([
    (0, common_1.Post)('attendance/check-in'),
    (0, common_1.HttpCode)(200),
    (0, decorators_1.AuthenticatedOnly)(),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.checkSchema))),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object]),
    __metadata("design:returntype", Promise)
], PeopleController.prototype, "checkIn", null);
__decorate([
    (0, common_1.Post)('attendance/check-out'),
    (0, common_1.HttpCode)(200),
    (0, decorators_1.AuthenticatedOnly)(),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.checkSchema))),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object]),
    __metadata("design:returntype", Promise)
], PeopleController.prototype, "checkOut", null);
__decorate([
    (0, common_1.Get)('attendance'),
    (0, decorators_1.RequirePermission)('attendance.read'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Query)((0, zod_pipe_1.zod)(contracts_1.attendanceQuerySchema))),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object]),
    __metadata("design:returntype", Promise)
], PeopleController.prototype, "attendance", null);
__decorate([
    (0, common_1.Get)('attendance/summary'),
    (0, decorators_1.RequirePermission)('attendance.read'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Query)((0, zod_pipe_1.zod)(contracts_1.attendanceQuerySchema))),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object]),
    __metadata("design:returntype", Promise)
], PeopleController.prototype, "attendanceSummary", null);
__decorate([
    (0, common_1.Put)('attendance'),
    (0, decorators_1.RequirePermission)('attendance.manage'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.upsertAttendanceSchema))),
    __param(2, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object, Object]),
    __metadata("design:returntype", Promise)
], PeopleController.prototype, "upsertAttendance", null);
__decorate([
    (0, common_1.Get)('payroll'),
    (0, decorators_1.RequirePermission)('payroll.read'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Query)((0, zod_pipe_1.zod)(periodBody))),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object]),
    __metadata("design:returntype", Promise)
], PeopleController.prototype, "payroll", null);
__decorate([
    (0, common_1.Post)('payroll/calculate'),
    (0, common_1.HttpCode)(200),
    (0, decorators_1.RequirePermission)('payroll.manage', 'ALL'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Body)((0, zod_pipe_1.zod)(periodBody))),
    __param(2, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object, Object]),
    __metadata("design:returntype", Promise)
], PeopleController.prototype, "calculate", null);
__decorate([
    (0, common_1.Patch)('payroll/:id'),
    (0, decorators_1.RequirePermission)('payroll.manage', 'ALL'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.updatePayrollSchema))),
    __param(3, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object, Object]),
    __metadata("design:returntype", Promise)
], PeopleController.prototype, "updatePayroll", null);
__decorate([
    (0, common_1.Post)('payroll/approve'),
    (0, common_1.HttpCode)(200),
    (0, decorators_1.RequirePermission)('payroll.manage', 'ALL'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.payrollIdsSchema))),
    __param(2, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object, Object]),
    __metadata("design:returntype", void 0)
], PeopleController.prototype, "approvePayroll", null);
__decorate([
    (0, common_1.Post)('payroll/pay'),
    (0, common_1.HttpCode)(200),
    (0, decorators_1.RequirePermission)('payroll.manage', 'ALL'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.payrollIdsSchema))),
    __param(2, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object, Object]),
    __metadata("design:returntype", void 0)
], PeopleController.prototype, "payPayroll", null);
exports.PeopleController = PeopleController = __decorate([
    (0, common_1.Controller)(),
    __metadata("design:paramtypes", [kpi_service_1.KpiService,
        people_service_1.PeopleService,
        dashboard_service_1.DashboardService])
], PeopleController);
//# sourceMappingURL=people.controller.js.map