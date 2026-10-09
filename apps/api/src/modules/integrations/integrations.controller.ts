import {
  Body,
  Controller,
  Delete,
  Get,
  Header,
  HttpCode,
  Param,
  Post,
  Put,
  Query,
  Req,
  Res,
} from '@nestjs/common';
import { Throttle } from '@nestjs/throttler';
import {
  formSubmitSchema,
  inboxListQuerySchema,
  integrationSettingsSchema,
  leadFormSchema,
  socialReplySchema,
  type FormSubmissionDto,
  type IntegrationSettings,
  type LeadFormDto,
  type MetaStatusDto,
  type PublicFormDto,
  type SocialMessageDto,
  type SocialThreadDto,
} from '@fluggi/contracts';
import type { Request, Response } from 'express';
import type { z } from 'zod';
import type { AuthContext, RequestMeta } from '../../core/auth/auth-context';
import {
  CrossOrigin,
  CurrentUser,
  Public,
  ReqMeta,
  RequirePermission,
} from '../../core/auth/decorators';
import { UuidPipe } from '../../core/http/uuid.pipe';
import { zod } from '../../core/http/zod.pipe';
import { FormsService } from './forms.service';
import { InboxService } from './inbox.service';
import { IntakeService } from './intake.service';
import { MetaService } from './meta.service';

/** Настройки интеграций: формы для сайта, Instagram/Facebook (только CEO). */
@Controller()
export class IntegrationsController {
  constructor(
    private readonly forms: FormsService,
    private readonly intake: IntakeService,
    private readonly meta: MetaService,
  ) {}

  @Get('lead-forms')
  @RequirePermission('settings.manage', 'ALL')
  list(): Promise<LeadFormDto[]> {
    return this.forms.list();
  }

  @Post('lead-forms')
  @RequirePermission('settings.manage', 'ALL')
  create(
    @CurrentUser() auth: AuthContext,
    @Body(zod(leadFormSchema)) body: z.output<typeof leadFormSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<LeadFormDto> {
    return this.forms.save(auth, null, body, meta);
  }

  @Put('lead-forms/:id')
  @RequirePermission('settings.manage', 'ALL')
  update(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Body(zod(leadFormSchema)) body: z.output<typeof leadFormSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<LeadFormDto> {
    return this.forms.save(auth, id, body, meta);
  }

  @Delete('lead-forms/:id')
  @HttpCode(204)
  @RequirePermission('settings.manage', 'ALL')
  remove(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @ReqMeta() meta: RequestMeta,
  ): Promise<void> {
    return this.forms.remove(auth, id, meta);
  }

  @Get('lead-forms/:id/submissions')
  @RequirePermission('settings.manage', 'ALL')
  submissions(@Param('id', UuidPipe) id: string): Promise<FormSubmissionDto[]> {
    return this.forms.submissions(id);
  }

  @Get('settings/integrations')
  @RequirePermission('settings.manage', 'ALL')
  settings(): Promise<IntegrationSettings> {
    return this.intake.settings();
  }

  @Put('settings/integrations')
  @RequirePermission('settings.manage', 'ALL')
  saveSettings(
    @CurrentUser() auth: AuthContext,
    @Body(zod(integrationSettingsSchema)) body: IntegrationSettings,
  ): Promise<IntegrationSettings> {
    return this.intake.saveSettings(body, auth.userId);
  }

  @Get('integrations/meta')
  @RequirePermission('settings.manage', 'ALL')
  metaStatus(): Promise<MetaStatusDto> {
    return this.meta.status();
  }
}

/** «Входящие»: Директ и комментарии Instagram/Facebook. */
@Controller('inbox')
export class InboxController {
  constructor(private readonly inbox: InboxService) {}

  @Get()
  @RequirePermission('lead.read')
  list(
    @CurrentUser() auth: AuthContext,
    @Query(zod(inboxListQuerySchema)) q: z.output<typeof inboxListQuerySchema>,
  ) {
    return this.inbox.list(auth, q);
  }

  @Get(':id/messages')
  @RequirePermission('lead.read')
  messages(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
  ): Promise<SocialMessageDto[]> {
    return this.inbox.messages(auth, id);
  }

  @Post(':id/reply')
  @HttpCode(200)
  @RequirePermission('lead.update')
  reply(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Body(zod(socialReplySchema)) body: z.output<typeof socialReplySchema>,
  ): Promise<SocialMessageDto[]> {
    return this.inbox.reply(auth, id, body);
  }

  @Post(':id/lead')
  @HttpCode(200)
  @RequirePermission('lead.create')
  lead(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
  ): Promise<SocialThreadDto> {
    return this.inbox.createLead(auth, id);
  }
}

const esc = (s: string) =>
  s.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]!);

/** Публичные адреса: форма для сайта и webhook Meta. Без входа, без проверки Origin. */
@Controller('public')
@Public()
@CrossOrigin()
export class PublicIntakeController {
  constructor(
    private readonly forms: FormsService,
    private readonly meta: MetaService,
  ) {}

  @Get('forms/:key')
  form(@Param('key') key: string): Promise<PublicFormDto> {
    return this.forms.publicForm(key);
  }

  /**
   * Заявка: JSON { data, utm, page } (наша форма) или обычная HTML-форма / webhook
   * плагина WordPress (поля плоско: name=…&phone=…&utm_source=…).
   */
  @Post('forms/:key')
  @HttpCode(200)
  @Throttle({ default: { limit: 10, ttl: 60_000 } })
  async submit(
    @Param('key') key: string,
    @Body() raw: Record<string, unknown>,
    @Req() req: Request,
    @Res({ passthrough: true }) res: Response,
  ) {
    const flat = !raw || typeof raw.data !== 'object';
    const body = flat
      ? {
          data: Object.fromEntries(
            Object.entries(raw ?? {}).filter(
              ([k, v]) =>
                typeof v === 'string' && !k.startsWith('utm_') && !['website', 'page'].includes(k),
            ),
          ),
          utm: Object.fromEntries(
            Object.entries(raw ?? {}).filter(
              ([k, v]) => k.startsWith('utm_') && typeof v === 'string',
            ),
          ),
          page: (raw?.page as string | undefined) ?? req.get('referer'),
          website: raw?.website as string | undefined,
        }
      : raw;
    const input = zod(formSubmitSchema).transform(body);
    const result = await this.forms.submit(key, input, req.ip ?? null);
    // Обычная HTML-форма без JavaScript — показываем страницу «Спасибо»
    if (
      flat &&
      req.is('application/x-www-form-urlencoded') &&
      req.accepts(['json', 'html']) === 'html'
    ) {
      res.setHeader('Content-Type', 'text/html; charset=utf-8');
      const back = req.get('referer');
      return `<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Спасибо</title><body style="font-family:system-ui;max-width:480px;margin:15vh auto;padding:0 16px;text-align:center"><h2>${esc(result.message)}</h2>${back ? `<p><a href="${esc(back)}">Вернуться на сайт</a></p>` : ''}</body>`;
    }
    return result;
  }

  @Get('meta/webhook')
  @Header('Content-Type', 'text/plain')
  verify(@Query() q: Record<string, string>): string {
    return this.meta.verify(q['hub.mode'], q['hub.verify_token'], q['hub.challenge']);
  }

  @Post('meta/webhook')
  @HttpCode(200)
  async webhook(@Req() req: Request & { rawBody?: Buffer }, @Body() body: unknown) {
    this.meta.checkSignature(req.rawBody, req.get('x-hub-signature-256'));
    await this.meta.handle(body as Parameters<MetaService['handle']>[0]);
    return { ok: true };
  }
}
