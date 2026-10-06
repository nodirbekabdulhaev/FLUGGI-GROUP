import { Body, Controller, Delete, Get, HttpCode, Param, Post, Req, Res } from '@nestjs/common';
import { Throttle } from '@nestjs/throttler';
import {
  changePasswordSchema,
  loginSchema,
  type ChangePasswordInput,
  type LoginInput,
  type MeResponse,
  type SessionInfo,
} from '@fluggi/contracts';
import type { Response } from 'express';
import { AppException } from '../http/app.exception';
import { UuidPipe } from '../http/uuid.pipe';
import { zod } from '../http/zod.pipe';
import type { AppRequest, AuthContext, RequestMeta } from './auth-context';
import { AuthService } from './auth.service';
import { clearAuthCookies, issueCsrfCookie, setSessionCookie } from './cookies';
import { AuthenticatedOnly, CurrentUser, Public, ReqMeta } from './decorators';
import { SessionService } from './session.service';

@Controller('auth')
export class AuthController {
  constructor(
    private readonly auth: AuthService,
    private readonly sessions: SessionService,
  ) {}

  @Post('login')
  @Public()
  @HttpCode(200)
  @Throttle({ default: { limit: 5, ttl: 60_000 } })
  async login(
    @Body(zod(loginSchema)) body: LoginInput,
    @ReqMeta() meta: RequestMeta,
    @Res({ passthrough: true }) res: Response,
  ): Promise<MeResponse> {
    const { token, expiresAt } = await this.auth.login(body, meta);
    setSessionCookie(res, token, expiresAt);
    issueCsrfCookie(res);
    const ctx = await this.sessions.resolve(token);
    if (!ctx) throw new AppException('INTERNAL', 'Не удалось создать сессию');
    return this.auth.toMe(ctx);
  }

  @Post('logout')
  @Public()
  @HttpCode(204)
  async logout(
    @Req() req: AppRequest,
    @ReqMeta() meta: RequestMeta,
    @Res({ passthrough: true }) res: Response,
  ): Promise<void> {
    if (req.auth) await this.auth.logout(req.auth, meta);
    clearAuthCookies(res);
  }

  @Get('me')
  @AuthenticatedOnly()
  me(@CurrentUser() user: AuthContext): MeResponse {
    return this.auth.toMe(user);
  }

  @Post('change-password')
  @AuthenticatedOnly()
  @HttpCode(204)
  @Throttle({ default: { limit: 5, ttl: 60_000 } })
  async changePassword(
    @CurrentUser() user: AuthContext,
    @Body(zod(changePasswordSchema)) body: ChangePasswordInput,
    @ReqMeta() meta: RequestMeta,
  ): Promise<void> {
    await this.auth.changePassword(user, body, meta);
  }

  @Get('sessions')
  @AuthenticatedOnly()
  sessionsList(@CurrentUser() user: AuthContext): Promise<SessionInfo[]> {
    return this.auth.listSessions(user);
  }

  @Delete('sessions/:id')
  @AuthenticatedOnly()
  @HttpCode(204)
  async revoke(
    @CurrentUser() user: AuthContext,
    @Param('id', UuidPipe) id: string,
    @ReqMeta() meta: RequestMeta,
  ): Promise<void> {
    await this.auth.revokeSession(user, id, meta);
  }
}
