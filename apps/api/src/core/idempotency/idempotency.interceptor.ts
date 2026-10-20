import { CallHandler, ExecutionContext, Injectable, NestInterceptor } from '@nestjs/common';
import { Prisma } from '@fluggi/db';
import type { Response } from 'express';
import { catchError, from, map, of, switchMap, throwError, type Observable } from 'rxjs';
import type { AppRequest } from '../auth/auth-context';
import { AppException } from '../http/app.exception';
import { PrismaService } from '../prisma/prisma.service';

const HEADER = 'idempotency-key';
const KEY_RE = /^[A-Za-z0-9_-]{8,100}$/;
/** statusCode = 0 — запрос с этим ключом ещё выполняется. */
const PENDING = 0;

/**
 * Защита от дублей при двойном клике и повторах сети (ТЗ §71).
 * Ключ резервируется ДО выполнения запроса (уникальный PK), поэтому даже два
 * одновременных POST не создадут две записи: второй получит сохранённый ответ
 * или 409, если первый ещё выполняется. При ошибке ключ освобождается.
 */
@Injectable()
export class IdempotencyInterceptor implements NestInterceptor {
  constructor(private readonly prisma: PrismaService) {}

  intercept(context: ExecutionContext, next: CallHandler): Observable<unknown> {
    const req = context.switchToHttp().getRequest<AppRequest>();
    const res = context.switchToHttp().getResponse<Response>();
    const raw = req.get(HEADER);
    if (req.method !== 'POST' || !raw || !KEY_RE.test(raw) || !req.auth) return next.handle();

    const userId = req.auth.userId;
    const key = `${userId}:${raw}`;
    const route = `${req.method} ${req.path}`;

    const reserve = this.prisma.idempotencyKey
      .create({ data: { key, userId, route, statusCode: PENDING, response: Prisma.JsonNull } })
      .then(() => null)
      .catch(async (err: unknown) => {
        if (err instanceof Prisma.PrismaClientKnownRequestError && err.code === 'P2002') {
          return this.prisma.idempotencyKey.findUnique({ where: { key } });
        }
        throw err;
      });

    return from(reserve).pipe(
      switchMap((saved) => {
        if (saved) {
          if (saved.route !== route)
            throw new AppException('CONFLICT', 'Ключ идемпотентности уже использован');
          if (saved.statusCode === PENDING)
            throw new AppException('CONFLICT', 'Запрос уже выполняется');
          res.status(saved.statusCode);
          res.setHeader('Idempotent-Replay', 'true');
          return of(saved.response);
        }
        return next.handle().pipe(
          switchMap((body) =>
            from(
              this.prisma.idempotencyKey.update({
                where: { key },
                data: {
                  statusCode: res.statusCode,
                  response: (body ?? null) as Prisma.InputJsonValue,
                },
              }),
            ).pipe(map(() => body)),
          ),
          catchError((err: unknown) =>
            from(this.prisma.idempotencyKey.delete({ where: { key } }).catch(() => undefined)).pipe(
              switchMap(() => throwError(() => err)),
            ),
          ),
        );
      }),
    );
  }
}
