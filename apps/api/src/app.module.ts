import { Module } from '@nestjs/common';
import { APP_FILTER, APP_GUARD, APP_INTERCEPTOR } from '@nestjs/core';
import { ThrottlerGuard, ThrottlerModule } from '@nestjs/throttler';
import { randomUUID } from 'node:crypto';
import { LoggerModule } from 'nestjs-pino';
import { loadEnv } from './config/env';
import { AuditModule } from './core/audit/audit.module';
import { AuthModule } from './core/auth/auth.module';
import { CsrfGuard } from './core/auth/csrf.guard';
import { SessionGuard } from './core/auth/session.guard';
import { AllExceptionsFilter } from './core/http/all-exceptions.filter';
import { OutboxModule } from './core/outbox/outbox.module';
import { PrismaModule } from './core/prisma/prisma.module';
import { PermissionGuard } from './core/rbac/permission.guard';
import { HealthController } from './modules/health/health.controller';
import { RolesModule } from './modules/roles/roles.module';
import { TeamsModule } from './modules/teams/teams.module';
import { UsersModule } from './modules/users/users.module';
import { ClientsModule } from './modules/clients/clients.module';
import { CrmModule } from './modules/crm/crm.module';
import { DealsModule } from './modules/deals/deals.module';
import { LeadsModule } from './modules/leads/leads.module';
import { MeetingsModule } from './modules/meetings/meetings.module';
import { NotificationsModule } from './modules/notifications/notifications.module';
import { PipelineModule } from './modules/pipeline/pipeline.module';
import { ReferencesModule } from './modules/references/references.module';
import { CommissionsModule } from './modules/commissions/commissions.module';
import { ContractsModule } from './modules/contracts/contracts.module';
import { FilesModule } from './modules/files/files.module';
import { PaymentsModule } from './modules/payments/payments.module';
import { FinanceModule } from './modules/finance/finance.module';
import { PeopleModule } from './modules/people/people.module';
import { AutomationModule } from './modules/automation/automation.module';
import { AnalyticsModule } from './modules/analytics/analytics.module';
import { CatalogModule } from './modules/catalog/catalog.module';
import { ChatModule } from './modules/chat/chat.module';
import { TodosModule } from './modules/todos/todos.module';
import { TelegramModule } from './modules/telegram/telegram.module';
import { SettingsModule } from './core/settings/settings.service';
import { OverdueModule } from './modules/projects/overdue.module';
import { ProjectsModule } from './modules/projects/projects.module';
import { ProposalsModule } from './modules/proposals/proposals.module';
import { StorageModule } from './core/storage/storage.module';
import { IdempotencyInterceptor } from './core/idempotency/idempotency.interceptor';

const env = loadEnv();

@Module({
  imports: [
    LoggerModule.forRoot({
      pinoHttp: {
        level: env.LOG_LEVEL,
        genReqId: (req) => (req.headers['x-request-id'] as string | undefined) ?? randomUUID(),
        redact: ['req.headers.cookie', 'req.headers["x-csrf-token"]', 'res.headers["set-cookie"]'],
        transport:
          env.NODE_ENV === 'development'
            ? { target: 'pino-pretty', options: { singleLine: true } }
            : undefined,
        autoLogging: { ignore: (req) => req.url === '/api/v1/health' },
        // В лог — только необходимое: без cookie, заголовков и тел запросов.
        serializers: {
          req: (req: { id: string; method: string; url: string }) => ({
            id: req.id,
            method: req.method,
            url: req.url,
          }),
          res: (res: { statusCode: number }) => ({ statusCode: res.statusCode }),
        },
      },
    }),
    ThrottlerModule.forRoot({
      throttlers: [{ name: 'default', ttl: 60_000, limit: 300 }],
      skipIf: () => env.RATE_LIMIT_DISABLED,
    }),
    PrismaModule,
    AuditModule,
    OutboxModule,
    AuthModule,
    UsersModule,
    TeamsModule,
    RolesModule,
    ReferencesModule,
    CrmModule,
    LeadsModule,
    ClientsModule,
    DealsModule,
    MeetingsModule,
    PipelineModule,
    NotificationsModule,
    StorageModule,
    FilesModule,
    ProposalsModule,
    ContractsModule,
    CommissionsModule,
    PaymentsModule,
    ProjectsModule,
    OverdueModule,
    FinanceModule,
    PeopleModule,
    SettingsModule,
    TelegramModule,
    AutomationModule,
    AnalyticsModule,
    TodosModule,
    ChatModule,
    CatalogModule,
  ],
  controllers: [HealthController],
  providers: [
    { provide: APP_FILTER, useClass: AllExceptionsFilter },
    // Порядок важен: лимит → сессия → CSRF → права.
    { provide: APP_GUARD, useClass: ThrottlerGuard },
    { provide: APP_GUARD, useClass: SessionGuard },
    { provide: APP_GUARD, useClass: CsrfGuard },
    { provide: APP_GUARD, useClass: PermissionGuard },
    { provide: APP_INTERCEPTOR, useClass: IdempotencyInterceptor },
  ],
})
export class AppModule {}
