import { Module } from '@nestjs/common';
import { APP_FILTER, APP_GUARD } from '@nestjs/core';
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

const env = loadEnv();

@Module({
  imports: [
    LoggerModule.forRoot({
      pinoHttp: {
        level: env.LOG_LEVEL,
        genReqId: (req) => (req.headers['x-request-id'] as string | undefined) ?? randomUUID(),
        redact: ['req.headers.cookie', 'req.headers["x-csrf-token"]', 'res.headers["set-cookie"]'],
        transport:
          env.NODE_ENV === 'development' ? { target: 'pino-pretty', options: { singleLine: true } } : undefined,
        autoLogging: { ignore: (req) => req.url === '/api/v1/health' },
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
  ],
  controllers: [HealthController],
  providers: [
    { provide: APP_FILTER, useClass: AllExceptionsFilter },
    // Порядок важен: лимит → сессия → CSRF → права.
    { provide: APP_GUARD, useClass: ThrottlerGuard },
    { provide: APP_GUARD, useClass: SessionGuard },
    { provide: APP_GUARD, useClass: CsrfGuard },
    { provide: APP_GUARD, useClass: PermissionGuard },
  ],
})
export class AppModule {}
