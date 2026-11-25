import { Body, Controller, HttpCode, Logger, Post } from '@nestjs/common';
import { z } from 'zod';
import type { AuthContext } from '../../core/auth/auth-context';
import { AuthenticatedOnly, CurrentUser } from '../../core/auth/decorators';
import { zod } from '../../core/http/zod.pipe';

const clientErrorSchema = z.object({
  widget: z.string().trim().max(40),
  message: z.string().max(1000),
  stack: z.string().max(4000).optional(),
  path: z.string().max(300).optional(),
  userAgent: z.string().max(300).optional(),
});

/**
 * Ошибки интерфейса (чат, «Список дел»): браузер присылает текст ошибки, сервер пишет его в лог —
 * так причину видно в логе API, даже если у пользователя она не воспроизводится у нас.
 */
@Controller('client-errors')
export class ClientErrorsController {
  private readonly logger = new Logger('ClientError');

  @Post()
  @HttpCode(204)
  @AuthenticatedOnly()
  report(
    @CurrentUser() auth: AuthContext,
    @Body(zod(clientErrorSchema)) body: z.output<typeof clientErrorSchema>,
  ) {
    this.logger.warn(
      `[${body.widget}] ${body.message} | user=${auth.userId} path=${body.path ?? ''} ua=${body.userAgent ?? ''}\n${body.stack ?? ''}`,
    );
  }
}
