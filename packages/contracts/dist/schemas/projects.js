"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.upsertProjectTemplateSchema = exports.taskTemplateSchema = exports.taskCommentSchema = exports.moveTaskSchema = exports.updateTaskSchema = exports.createTaskSchema = exports.taskListQuerySchema = exports.TASK_VIEWS = exports.updateMemberSchema = exports.addMemberSchema = exports.applyTemplateSchema = exports.projectStatusSchema = exports.updateProjectSchema = exports.projectListQuerySchema = exports.PROJECT_VIEWS = void 0;
const zod_1 = require("zod");
const enums_1 = require("../enums");
const common_1 = require("./common");
const fields_1 = require("./fields");
// ─────────────────────────── Проекты ───────────────────────────
exports.PROJECT_VIEWS = ['all', 'active', 'overdue', 'completed'];
exports.projectListQuerySchema = common_1.paginationQuerySchema.extend({
    view: zod_1.z.enum(exports.PROJECT_VIEWS).default('all'),
    status: zod_1.z.enum(enums_1.PROJECT_STATUSES).optional(),
    ropId: zod_1.z.uuid().optional(),
    clientId: zod_1.z.uuid().optional(),
    dealId: zod_1.z.uuid().optional(),
    directionId: zod_1.z.uuid().optional(),
    q: zod_1.z.string().trim().max(100).optional(),
});
const optDate = fields_1.dateOnly.nullish();
exports.updateProjectSchema = zod_1.z
    .object({
    name: zod_1.z.string().trim().min(1, 'Укажите название').max(200),
    description: zod_1.z.string().trim().max(5000).nullable(),
    priority: zod_1.z.enum(enums_1.PRIORITIES),
    startDate: optDate,
    deadline: optDate,
    ropId: zod_1.z.uuid(),
    /** Направление бизнеса (меняет только тот, у кого project.update на всю компанию) */
    directionId: zod_1.z.uuid().nullable(),
})
    .partial()
    .refine((v) => !v.startDate || !v.deadline || v.startDate <= v.deadline, {
    message: 'Дедлайн раньше даты начала',
    path: ['deadline'],
});
exports.projectStatusSchema = zod_1.z.object({
    status: zod_1.z.enum(enums_1.PROJECT_STATUSES),
    comment: zod_1.z.string().trim().max(1000).optional(),
});
exports.applyTemplateSchema = zod_1.z.object({ templateId: zod_1.z.uuid('Выберите шаблон') });
// ─────────────────────────── Команда ───────────────────────────
exports.addMemberSchema = zod_1.z.object({
    userId: zod_1.z.uuid('Выберите сотрудника'),
    role: zod_1.z.enum(enums_1.EXECUTOR_SPECIALTIES).nullish(),
    workloadPct: zod_1.z.coerce.number().int().min(0).max(100).nullish(),
    deadline: optDate,
});
exports.updateMemberSchema = zod_1.z
    .object({
    role: zod_1.z.enum(enums_1.EXECUTOR_SPECIALTIES).nullable(),
    workloadPct: zod_1.z.coerce.number().int().min(0).max(100).nullable(),
    deadline: fields_1.dateOnly.nullable(),
    status: zod_1.z.enum(enums_1.PROJECT_MEMBER_STATUSES),
})
    .partial();
// ─────────────────────────── Задачи ───────────────────────────
exports.TASK_VIEWS = ['all', 'today', 'overdue', 'in_progress', 'review', 'done'];
exports.taskListQuerySchema = common_1.paginationQuerySchema.extend({
    view: zod_1.z.enum(exports.TASK_VIEWS).default('all'),
    projectId: zod_1.z.uuid().optional(),
    assigneeId: zod_1.z.uuid().optional(),
    /** Только задачи, где я ответственный. */
    mine: zod_1.z
        .enum(['true', 'false'])
        .optional()
        .transform((v) => v === 'true'),
    status: zod_1.z.enum(enums_1.TASK_STATUSES).optional(),
    q: zod_1.z.string().trim().max(100).optional(),
});
const taskFields = {
    title: zod_1.z.string().trim().min(1, 'Укажите название').max(300),
    description: zod_1.z.string().trim().max(10_000).nullish(),
    assigneeId: zod_1.z.uuid('Выберите исполнителя'),
    priority: zod_1.z.enum(enums_1.PRIORITIES).default('MEDIUM'),
    startDate: optDate,
    deadline: fields_1.dateTime.nullish(),
};
exports.createTaskSchema = zod_1.z.object({ projectId: zod_1.z.uuid('Выберите проект'), ...taskFields });
exports.updateTaskSchema = zod_1.z
    .object({
    title: taskFields.title,
    description: zod_1.z.string().trim().max(10_000).nullable(),
    assigneeId: zod_1.z.uuid('Выберите исполнителя'),
    priority: zod_1.z.enum(enums_1.PRIORITIES),
    startDate: fields_1.dateOnly.nullable(),
    deadline: fields_1.dateTime.nullable(),
    progressPct: zod_1.z.coerce.number().int().min(0).max(100),
})
    .partial();
/** Перемещение карточки Kanban: новый статус и место в колонке. */
exports.moveTaskSchema = zod_1.z.object({
    status: zod_1.z.enum(enums_1.TASK_STATUSES),
    /** id карточки, перед которой встала задача; null — в конец колонки. */
    beforeId: zod_1.z.uuid().nullish(),
});
exports.taskCommentSchema = zod_1.z.object({
    body: zod_1.z.string().trim().min(1, 'Напишите комментарий').max(5000),
});
// ─────────────────────────── Шаблоны ───────────────────────────
exports.taskTemplateSchema = zod_1.z.object({
    title: zod_1.z.string().trim().min(1, 'Укажите задачу').max(300),
    description: zod_1.z.string().trim().max(5000).nullish(),
    role: zod_1.z.enum(enums_1.EXECUTOR_SPECIALTIES).nullish(),
    startOffsetDays: zod_1.z.coerce.number().int().min(0).max(365).default(0),
    durationDays: zod_1.z.coerce.number().int().min(1).max(365).default(1),
    priority: zod_1.z.enum(enums_1.PRIORITIES).default('MEDIUM'),
});
exports.upsertProjectTemplateSchema = zod_1.z.object({
    name: zod_1.z.string().trim().min(1, 'Укажите название').max(200),
    serviceId: zod_1.z.uuid().nullish(),
    description: zod_1.z.string().trim().max(2000).nullish(),
    isActive: zod_1.z.boolean().default(true),
    tasks: zod_1.z.array(exports.taskTemplateSchema).min(1, 'Добавьте хотя бы одну задачу').max(100),
});
//# sourceMappingURL=projects.js.map