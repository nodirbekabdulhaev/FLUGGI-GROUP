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
Object.defineProperty(exports, "__esModule", { value: true });
exports.IdempotencyInterceptor = void 0;
const common_1 = require("@nestjs/common");
const db_1 = require("@fluggi/db");
const rxjs_1 = require("rxjs");
const app_exception_1 = require("../http/app.exception");
const prisma_service_1 = require("../prisma/prisma.service");
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
let IdempotencyInterceptor = class IdempotencyInterceptor {
    prisma;
    constructor(prisma) {
        this.prisma = prisma;
    }
    intercept(context, next) {
        const req = context.switchToHttp().getRequest();
        const res = context.switchToHttp().getResponse();
        const raw = req.get(HEADER);
        if (req.method !== 'POST' || !raw || !KEY_RE.test(raw) || !req.auth)
            return next.handle();
        const userId = req.auth.userId;
        const key = `${userId}:${raw}`;
        const route = `${req.method} ${req.path}`;
        const reserve = this.prisma.idempotencyKey
            .create({ data: { key, userId, route, statusCode: PENDING, response: db_1.Prisma.JsonNull } })
            .then(() => null)
            .catch(async (err) => {
            if (err instanceof db_1.Prisma.PrismaClientKnownRequestError && err.code === 'P2002') {
                return this.prisma.idempotencyKey.findUnique({ where: { key } });
            }
            throw err;
        });
        return (0, rxjs_1.from)(reserve).pipe((0, rxjs_1.switchMap)((saved) => {
            if (saved) {
                if (saved.route !== route)
                    throw new app_exception_1.AppException('CONFLICT', 'Ключ идемпотентности уже использован');
                if (saved.statusCode === PENDING)
                    throw new app_exception_1.AppException('CONFLICT', 'Запрос уже выполняется');
                res.status(saved.statusCode);
                res.setHeader('Idempotent-Replay', 'true');
                return (0, rxjs_1.of)(saved.response);
            }
            return next.handle().pipe((0, rxjs_1.switchMap)((body) => (0, rxjs_1.from)(this.prisma.idempotencyKey.update({
                where: { key },
                data: {
                    statusCode: res.statusCode,
                    response: (body ?? null),
                },
            })).pipe((0, rxjs_1.map)(() => body))), (0, rxjs_1.catchError)((err) => (0, rxjs_1.from)(this.prisma.idempotencyKey.delete({ where: { key } }).catch(() => undefined)).pipe((0, rxjs_1.switchMap)(() => (0, rxjs_1.throwError)(() => err)))));
        }));
    }
};
exports.IdempotencyInterceptor = IdempotencyInterceptor;
exports.IdempotencyInterceptor = IdempotencyInterceptor = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [prisma_service_1.PrismaService])
], IdempotencyInterceptor);
//# sourceMappingURL=idempotency.interceptor.js.map