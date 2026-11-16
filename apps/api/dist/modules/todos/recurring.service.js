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
var RecurringTodosService_1;
Object.defineProperty(exports, "__esModule", { value: true });
exports.RecurringTodosService = void 0;
const common_1 = require("@nestjs/common");
const contracts_1 = require("@fluggi/contracts");
const domain_1 = require("@fluggi/domain");
const db_1 = require("@fluggi/db");
const serialize_1 = require("../../core/http/serialize");
const app_exception_1 = require("../../core/http/app.exception");
const prisma_service_1 = require("../../core/prisma/prisma.service");
const notifications_service_1 = require("../notifications/notifications.service");
const todos_service_1 = require("./todos.service");
const include = {
    owner: { select: { id: true, fullName: true } },
};
/** Срок дела по правилу — конец рабочего дня по Ташкенту. */
const DUE_TIME = '18:00';
const dateLabel = (d) => d.split('-').reverse().join('.');
/**
 * Регулярные дела (налоговый календарь и т.п.): за N дней до срока создаётся дело
 * исполнителю, уведомление — в CRM и Telegram.
 */
let RecurringTodosService = RecurringTodosService_1 = class RecurringTodosService {
    prisma;
    todos;
    notifications;
    logger = new common_1.Logger(RecurringTodosService_1.name);
    constructor(prisma, todos, notifications) {
        this.prisma = prisma;
        this.todos = todos;
        this.notifications = notifications;
    }
    toDto(r, today = (0, domain_1.companyDate)(new Date())) {
        const due = (0, domain_1.nextDue)(r, today);
        return {
            id: r.id,
            title: r.title,
            description: r.description,
            kind: r.kind,
            priority: r.priority,
            owner: { id: r.owner.id, name: r.owner.fullName },
            frequency: r.frequency,
            dayOfMonth: r.dayOfMonth,
            month: r.month,
            remindDaysBefore: r.remindDaysBefore,
            isActive: r.isActive,
            nextDue: due,
            nextTitle: (0, domain_1.fillPeriod)(r.title, due),
        };
    }
    where(auth) {
        return { deletedAt: null, OR: [{ ownerId: auth.userId }, { createdById: auth.userId }] };
    }
    async list(auth) {
        const rows = await this.prisma.recurringTodo.findMany({
            where: this.where(auth),
            include,
            orderBy: [{ frequency: 'asc' }, { dayOfMonth: 'asc' }, { createdAt: 'asc' }],
        });
        return rows
            .map((r) => this.toDto(r))
            .sort((a, b) => a.nextDue.localeCompare(b.nextDue) || a.title.localeCompare(b.title));
    }
    data(input) {
        return {
            title: input.title,
            description: input.description ?? null,
            kind: input.kind,
            priority: input.priority,
            frequency: input.frequency,
            dayOfMonth: input.dayOfMonth,
            month: input.frequency === 'MONTHLY' ? null : (input.month ?? 1),
            remindDaysBefore: input.remindDaysBefore,
            isActive: input.isActive,
        };
    }
    async create(auth, input) {
        const ownerId = input.ownerId ?? auth.userId;
        // Регулярные дела другим сотрудникам ставит только CEO; остальные — себе
        if (ownerId !== auth.userId && auth.roleCode !== 'CEO')
            throw (0, app_exception_1.forbidden)('Регулярные дела можно ставить только себе');
        await this.todos.assertAssignable(auth, ownerId);
        const r = await this.prisma.recurringTodo.create({
            data: { ...this.data(input), ownerId, createdById: auth.userId },
            include,
        });
        return this.toDto(r);
    }
    async update(auth, id, input) {
        const before = await this.prisma.recurringTodo.findFirst({
            where: { AND: [this.where(auth), { id }] },
        });
        if (!before)
            throw (0, app_exception_1.notFound)('Регулярное дело');
        const ownerId = input.ownerId ?? before.ownerId;
        if (ownerId !== before.ownerId)
            await this.todos.assertAssignable(auth, ownerId);
        const r = await this.prisma.recurringTodo.update({
            where: { id },
            data: { ...this.data(input), ownerId },
            include,
        });
        return this.toDto(r);
    }
    async remove(auth, id) {
        const r = await this.prisma.recurringTodo.findFirst({
            where: { AND: [this.where(auth), { id }] },
        });
        if (!r)
            throw (0, app_exception_1.notFound)('Регулярное дело');
        await this.prisma.recurringTodo.update({
            where: { id },
            data: { deletedAt: new Date(), isActive: false },
        });
    }
    /** Налоговый календарь IT-Park одним нажатием; уже добавленные пункты не дублируются. */
    async addTaxCalendar(auth) {
        if (auth.roleCode !== 'CEO')
            throw (0, app_exception_1.forbidden)('Налоговый календарь доступен только CEO');
        const existing = await this.prisma.recurringTodo.findMany({
            where: { ownerId: auth.userId, deletedAt: null },
            select: { title: true },
        });
        const have = new Set(existing.map((e) => e.title));
        for (const item of contracts_1.TAX_CALENDAR) {
            if (have.has(item.title))
                continue;
            await this.prisma.recurringTodo.create({
                data: {
                    title: item.title,
                    description: item.description ?? null,
                    kind: item.kind ?? 'REPORT',
                    priority: item.priority ?? 'HIGH',
                    frequency: item.frequency,
                    dayOfMonth: Number(item.dayOfMonth),
                    month: item.frequency === 'MONTHLY' ? null : Number(item.month ?? 1),
                    remindDaysBefore: Number(item.remindDaysBefore ?? 3),
                    ownerId: auth.userId,
                    createdById: auth.userId,
                },
            });
        }
        return this.list(auth);
    }
    /** Задача планировщика: создать дела, срок которых наступает в ближайшие N дней. */
    async generate(now = new Date()) {
        const today = (0, domain_1.companyDate)(now);
        const rules = await this.prisma.recurringTodo.findMany({
            where: { isActive: true, deletedAt: null, owner: { status: 'ACTIVE', deletedAt: null } },
        });
        let created = 0;
        for (const r of rules) {
            const due = (0, domain_1.nextDue)(r, today);
            if ((0, domain_1.addDays)(today, r.remindDaysBefore) < due)
                continue;
            const title = (0, domain_1.fillPeriod)(r.title, due);
            try {
                await this.prisma.todo.create({
                    data: {
                        title,
                        description: r.description,
                        kind: r.kind,
                        priority: r.priority,
                        dueAt: (0, domain_1.atTashkent)(due, DUE_TIME),
                        ownerId: r.ownerId,
                        creatorId: r.createdById,
                        recurringId: r.id,
                        recurringDue: (0, serialize_1.parseDate)(due),
                    },
                });
            }
            catch (err) {
                if (err instanceof db_1.Prisma.PrismaClientKnownRequestError && err.code === 'P2002')
                    continue;
                throw err;
            }
            created += 1;
            await this.notifications.notify([r.ownerId], {
                type: 'todo.recurring',
                title: `📅 ${title}`,
                body: `Срок: ${dateLabel(due)}`,
                link: '/todos',
            });
        }
        if (created)
            this.logger.log(`Recurring todos created: ${created}`);
        return { created };
    }
};
exports.RecurringTodosService = RecurringTodosService;
exports.RecurringTodosService = RecurringTodosService = RecurringTodosService_1 = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [prisma_service_1.PrismaService,
        todos_service_1.TodosService,
        notifications_service_1.NotificationsService])
], RecurringTodosService);
//# sourceMappingURL=recurring.service.js.map