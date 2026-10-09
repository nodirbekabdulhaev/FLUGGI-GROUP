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
exports.AutomationController = void 0;
const common_1 = require("@nestjs/common");
const contracts_1 = require("@fluggi/contracts");
const zod_1 = require("zod");
const decorators_1 = require("../../core/auth/decorators");
const audit_service_1 = require("../../core/audit/audit.service");
const app_exception_1 = require("../../core/http/app.exception");
const uuid_pipe_1 = require("../../core/http/uuid.pipe");
const zod_pipe_1 = require("../../core/http/zod.pipe");
const prisma_service_1 = require("../../core/prisma/prisma.service");
const settings_service_1 = require("../../core/settings/settings.service");
const follow_ups_service_1 = require("./follow-ups.service");
const reports_service_1 = require("./reports.service");
const scheduler_service_1 = require("./scheduler.service");
const reportQuery = zod_1.z.object({ kind: zod_1.z.enum(['daily', 'weekly']).default('daily') });
/** Автоматизация (ТЗ §38, §41, §54–57): настройки, планировщик, отчёты, follow-up. */
let AutomationController = class AutomationController {
    settings;
    scheduler;
    reports;
    followUps;
    prisma;
    audit;
    constructor(settings, scheduler, reports, followUps, prisma, audit) {
        this.settings = settings;
        this.scheduler = scheduler;
        this.reports = reports;
        this.followUps = followUps;
        this.prisma = prisma;
        this.audit = audit;
    }
    getSettings() {
        return this.settings.automation();
    }
    async saveSettings(auth, body, meta) {
        const before = await this.settings.automation();
        const after = await this.settings.saveAutomation(body, auth.userId);
        await this.prisma.$transaction((tx) => this.audit.log(tx, {
            actorId: auth.userId,
            action: 'settings.automation',
            entityType: 'setting',
            entityId: null,
            changes: { automation: { old: before, new: after } },
            meta,
        }));
        return after;
    }
    getCompany() {
        return this.settings.company();
    }
    async saveCompany(auth, body, meta) {
        const before = await this.settings.company();
        const after = await this.settings.saveCompany(body, auth.userId);
        await this.prisma.$transaction((tx) => this.audit.log(tx, {
            actorId: auth.userId,
            action: 'settings.company',
            entityType: 'setting',
            entityId: null,
            changes: { company: { old: before, new: after } },
            meta,
        }));
        return after;
    }
    async jobs() {
        const runs = await this.prisma.jobRun.findMany({ orderBy: { startedAt: 'desc' }, take: 200 });
        return this.scheduler.jobs.map((j) => {
            const last = runs.find((r) => r.job === j.name);
            return {
                name: j.name,
                label: j.label,
                schedule: j.schedule,
                last: last
                    ? {
                        job: last.job,
                        slot: last.slot,
                        startedAt: last.startedAt.toISOString(),
                        finishedAt: last.finishedAt?.toISOString() ?? null,
                        error: last.error,
                    }
                    : null,
            };
        });
    }
    async run(name) {
        const r = await this.scheduler.runNow(name);
        if (!r)
            throw (0, app_exception_1.notFound)('Задача');
        return { job: r.job, error: r.error, result: r.result };
    }
    /** Предпросмотр отчёта: CEO — компания, РОП — свой отдел. */
    async preview(auth, q) {
        const ceo = auth.permissions['dashboard.ceo'] === 'ALL';
        if (!ceo && !(auth.roleCode === 'ROP' && auth.headedTeamIds.length))
            throw (0, app_exception_1.forbidden)();
        const scope = ceo ? {} : { teamIds: auth.headedTeamIds };
        return {
            text: q.kind === 'weekly' ? await this.reports.weekly(scope) : await this.reports.daily(scope),
        };
    }
    list(auth, q) {
        return this.followUps.list(auth, q);
    }
    complete(auth, id, body, meta) {
        return this.followUps.complete(auth, id, body, meta);
    }
};
exports.AutomationController = AutomationController;
__decorate([
    (0, common_1.Get)('settings/automation'),
    (0, decorators_1.RequirePermission)('settings.manage', 'ALL'),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", []),
    __metadata("design:returntype", Promise)
], AutomationController.prototype, "getSettings", null);
__decorate([
    (0, common_1.Put)('settings/automation'),
    (0, decorators_1.RequirePermission)('settings.manage', 'ALL'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.automationSettingsSchema))),
    __param(2, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object, Object]),
    __metadata("design:returntype", Promise)
], AutomationController.prototype, "saveSettings", null);
__decorate([
    (0, common_1.Get)('settings/company'),
    (0, decorators_1.RequirePermission)('settings.manage', 'ALL'),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", []),
    __metadata("design:returntype", Promise)
], AutomationController.prototype, "getCompany", null);
__decorate([
    (0, common_1.Put)('settings/company'),
    (0, decorators_1.RequirePermission)('settings.manage', 'ALL'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.companySettingsSchema))),
    __param(2, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object, Object]),
    __metadata("design:returntype", Promise)
], AutomationController.prototype, "saveCompany", null);
__decorate([
    (0, common_1.Get)('automation/jobs'),
    (0, decorators_1.RequirePermission)('settings.manage', 'ALL'),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", []),
    __metadata("design:returntype", Promise)
], AutomationController.prototype, "jobs", null);
__decorate([
    (0, common_1.Post)('automation/jobs/:name/run'),
    (0, common_1.HttpCode)(200),
    (0, decorators_1.RequirePermission)('settings.manage', 'ALL'),
    __param(0, (0, common_1.Param)('name')),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [String]),
    __metadata("design:returntype", Promise)
], AutomationController.prototype, "run", null);
__decorate([
    (0, common_1.Get)('reports/preview'),
    (0, decorators_1.AuthenticatedOnly)(),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Query)((0, zod_pipe_1.zod)(reportQuery))),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object]),
    __metadata("design:returntype", Promise)
], AutomationController.prototype, "preview", null);
__decorate([
    (0, common_1.Get)('follow-ups'),
    (0, decorators_1.RequirePermission)('client.read'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Query)((0, zod_pipe_1.zod)(contracts_1.followUpListQuerySchema))),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object]),
    __metadata("design:returntype", Promise)
], AutomationController.prototype, "list", null);
__decorate([
    (0, common_1.Post)('follow-ups/:id/complete'),
    (0, common_1.HttpCode)(200),
    (0, decorators_1.RequirePermission)('client.read'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.completeFollowUpSchema))),
    __param(3, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object, Object]),
    __metadata("design:returntype", Promise)
], AutomationController.prototype, "complete", null);
exports.AutomationController = AutomationController = __decorate([
    (0, common_1.Controller)(),
    __metadata("design:paramtypes", [settings_service_1.SettingsService,
        scheduler_service_1.SchedulerService,
        reports_service_1.ReportsService,
        follow_ups_service_1.FollowUpsService,
        prisma_service_1.PrismaService,
        audit_service_1.AuditService])
], AutomationController);
//# sourceMappingURL=automation.controller.js.map