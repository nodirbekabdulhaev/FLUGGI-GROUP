import { Body, Controller, Get, HttpCode, Param, Post, Put, Query } from '@nestjs/common';
import {
  automationSettingsSchema,
  companySettingsSchema,
  type CompanySettings,
  completeFollowUpSchema,
  followUpListQuerySchema,
  type AutomationSettings,
  type FollowUpDto,
  type JobRunDto,
  type Paginated,
  type ReportPreviewDto,
} from '@fluggi/contracts';
import { z } from 'zod';
import type { AuthContext, RequestMeta } from '../../core/auth/auth-context';
import {
  AuthenticatedOnly,
  CurrentUser,
  ReqMeta,
  RequirePermission,
} from '../../core/auth/decorators';
import { AuditService } from '../../core/audit/audit.service';
import { forbidden, notFound } from '../../core/http/app.exception';
import { UuidPipe } from '../../core/http/uuid.pipe';
import { zod } from '../../core/http/zod.pipe';
import { PrismaService } from '../../core/prisma/prisma.service';
import { SettingsService } from '../../core/settings/settings.service';
import { FollowUpsService } from './follow-ups.service';
import { ReportsService } from './reports.service';
import { SchedulerService } from './scheduler.service';

const reportQuery = z.object({ kind: z.enum(['daily', 'weekly']).default('daily') });

/** Автоматизация (ТЗ §38, §41, §54–57): настройки, планировщик, отчёты, follow-up. */
@Controller()
export class AutomationController {
  constructor(
    private readonly settings: SettingsService,
    private readonly scheduler: SchedulerService,
    private readonly reports: ReportsService,
    private readonly followUps: FollowUpsService,
    private readonly prisma: PrismaService,
    private readonly audit: AuditService,
  ) {}

  @Get('settings/automation')
  @RequirePermission('settings.manage', 'ALL')
  getSettings(): Promise<AutomationSettings> {
    return this.settings.automation();
  }

  @Put('settings/automation')
  @RequirePermission('settings.manage', 'ALL')
  async saveSettings(
    @CurrentUser() auth: AuthContext,
    @Body(zod(automationSettingsSchema)) body: z.output<typeof automationSettingsSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<AutomationSettings> {
    const before = await this.settings.automation();
    const after = await this.settings.saveAutomation(body, auth.userId);
    await this.prisma.$transaction((tx) =>
      this.audit.log(tx, {
        actorId: auth.userId,
        action: 'settings.automation',
        entityType: 'setting',
        entityId: null,
        changes: { automation: { old: before, new: after } },
        meta,
      }),
    );
    return after;
  }

  @Get('settings/company')
  @RequirePermission('settings.manage', 'ALL')
  getCompany(): Promise<CompanySettings> {
    return this.settings.company();
  }

  @Put('settings/company')
  @RequirePermission('settings.manage', 'ALL')
  async saveCompany(
    @CurrentUser() auth: AuthContext,
    @Body(zod(companySettingsSchema)) body: CompanySettings,
    @ReqMeta() meta: RequestMeta,
  ): Promise<CompanySettings> {
    const before = await this.settings.company();
    const after = await this.settings.saveCompany(body, auth.userId);
    await this.prisma.$transaction((tx) =>
      this.audit.log(tx, {
        actorId: auth.userId,
        action: 'settings.company',
        entityType: 'setting',
        entityId: null,
        changes: { company: { old: before, new: after } },
        meta,
      }),
    );
    return after;
  }

  @Get('automation/jobs')
  @RequirePermission('settings.manage', 'ALL')
  async jobs() {
    const runs = await this.prisma.jobRun.findMany({ orderBy: { startedAt: 'desc' }, take: 200 });
    return this.scheduler.jobs.map((j) => {
      const last = runs.find((r) => r.job === j.name);
      return {
        name: j.name,
        label: j.label,
        schedule: j.schedule,
        last: last
          ? ({
              job: last.job,
              slot: last.slot,
              startedAt: last.startedAt.toISOString(),
              finishedAt: last.finishedAt?.toISOString() ?? null,
              error: last.error,
            } satisfies JobRunDto)
          : null,
      };
    });
  }

  @Post('automation/jobs/:name/run')
  @HttpCode(200)
  @RequirePermission('settings.manage', 'ALL')
  async run(@Param('name') name: string) {
    const r = await this.scheduler.runNow(name);
    if (!r) throw notFound('Задача');
    return { job: r.job, error: r.error, result: r.result };
  }

  /** Предпросмотр отчёта: CEO — компания, РОП — свой отдел. */
  @Get('reports/preview')
  @AuthenticatedOnly()
  async preview(
    @CurrentUser() auth: AuthContext,
    @Query(zod(reportQuery)) q: z.output<typeof reportQuery>,
  ): Promise<ReportPreviewDto> {
    const ceo = auth.permissions['dashboard.ceo'] === 'ALL';
    if (!ceo && !(auth.roleCode === 'ROP' && auth.headedTeamIds.length)) throw forbidden();
    const scope = ceo ? {} : { teamIds: auth.headedTeamIds };
    return {
      text:
        q.kind === 'weekly' ? await this.reports.weekly(scope) : await this.reports.daily(scope),
    };
  }

  @Get('follow-ups')
  @RequirePermission('client.read')
  list(
    @CurrentUser() auth: AuthContext,
    @Query(zod(followUpListQuerySchema)) q: z.output<typeof followUpListQuerySchema>,
  ): Promise<Paginated<FollowUpDto>> {
    return this.followUps.list(auth, q);
  }

  @Post('follow-ups/:id/complete')
  @HttpCode(200)
  @RequirePermission('client.read')
  complete(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Body(zod(completeFollowUpSchema)) body: z.output<typeof completeFollowUpSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<FollowUpDto> {
    return this.followUps.complete(auth, id, body, meta);
  }
}
