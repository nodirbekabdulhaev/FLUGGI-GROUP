import { z } from 'zod';
import {
  EXECUTOR_SPECIALTIES,
  PRIORITIES,
  PROJECT_MEMBER_STATUSES,
  PROJECT_STATUSES,
  TASK_STATUSES,
  type Currency,
  type ExecutorSpecialty,
  type Priority,
  type ProjectMemberStatus,
  type ProjectStatus,
  type RoleCode,
  type TaskStatus,
} from '../enums';
import { paginationQuerySchema } from './common';
import { dateOnly, dateTime } from './fields';
import type { NamedRef } from './references';
import type { NumberedRef } from './sales';

// ─────────────────────────── Проекты ───────────────────────────

export const PROJECT_VIEWS = ['all', 'active', 'overdue', 'completed'] as const;
export type ProjectView = (typeof PROJECT_VIEWS)[number];

export const projectListQuerySchema = paginationQuerySchema.extend({
  view: z.enum(PROJECT_VIEWS).default('all'),
  status: z.enum(PROJECT_STATUSES).optional(),
  ropId: z.uuid().optional(),
  clientId: z.uuid().optional(),
  dealId: z.uuid().optional(),
  directionId: z.uuid().optional(),
  q: z.string().trim().max(100).optional(),
});
export type ProjectListQuery = z.input<typeof projectListQuerySchema>;

const optDate = dateOnly.nullish();

export const updateProjectSchema = z
  .object({
    name: z.string().trim().min(1, 'Укажите название').max(200),
    description: z.string().trim().max(5000).nullable(),
    priority: z.enum(PRIORITIES),
    startDate: optDate,
    deadline: optDate,
    ropId: z.uuid(),
    /** Направление бизнеса (меняет только тот, у кого project.update на всю компанию) */
    directionId: z.uuid().nullable(),
  })
  .partial()
  .refine((v) => !v.startDate || !v.deadline || v.startDate <= v.deadline, {
    message: 'Дедлайн раньше даты начала',
    path: ['deadline'],
  });
export type UpdateProjectInput = z.input<typeof updateProjectSchema>;

export const projectStatusSchema = z.object({
  status: z.enum(PROJECT_STATUSES),
  comment: z.string().trim().max(1000).optional(),
});
export type ProjectStatusInput = z.input<typeof projectStatusSchema>;

export const applyTemplateSchema = z.object({ templateId: z.uuid('Выберите шаблон') });

export interface TaskCountsDto {
  total: number;
  done: number;
  overdue: number;
}

export interface ProjectDto {
  id: string;
  number: string;
  name: string;
  client: NamedRef;
  /** null — у пользователя нет доступа к сделке (исполнитель). */
  deal: NumberedRef | null;
  rop: NamedRef;
  manager: NamedRef;
  status: ProjectStatus;
  priority: Priority;
  /** Стоимость видна только тем, у кого есть доступ к финансам/оплатам (ТЗ §3.4). */
  price: string | null;
  currency: Currency;
  startDate: string | null;
  deadline: string | null;
  overdueDays: number;
  description: string | null;
  template: NamedRef | null;
  /** Направление бизнеса: IT, Медиа, Маркетинг */
  direction: NamedRef | null;
  tasks: TaskCountsDto;
  completedAt: string | null;
  createdAt: string;
}

export interface ProjectPermissionsDto {
  /** Менять поля и статус проекта. */
  canUpdate: boolean;
  /** Назначать исполнителей. */
  canAssign: boolean;
  /** Создавать задачи. */
  canCreateTasks: boolean;
}

export interface ProjectDetailDto extends ProjectDto {
  members: ProjectMemberDto[];
  can: ProjectPermissionsDto;
}

// ─────────────────────────── Команда ───────────────────────────

export const addMemberSchema = z.object({
  userId: z.uuid('Выберите сотрудника'),
  role: z.enum(EXECUTOR_SPECIALTIES).nullish(),
  workloadPct: z.coerce.number().int().min(0).max(100).nullish(),
  deadline: optDate,
});
export type AddMemberInput = z.input<typeof addMemberSchema>;

export const updateMemberSchema = z
  .object({
    role: z.enum(EXECUTOR_SPECIALTIES).nullable(),
    workloadPct: z.coerce.number().int().min(0).max(100).nullable(),
    deadline: dateOnly.nullable(),
    status: z.enum(PROJECT_MEMBER_STATUSES),
  })
  .partial();
export type UpdateMemberInput = z.input<typeof updateMemberSchema>;

export interface ProjectMemberDto {
  id: string;
  user: NamedRef;
  role: ExecutorSpecialty | null;
  workloadPct: number | null;
  deadline: string | null;
  status: ProjectMemberStatus;
  assignedAt: string;
  tasks: TaskCountsDto;
}

// ─────────────────────────── Задачи ───────────────────────────

export const TASK_VIEWS = ['all', 'today', 'overdue', 'in_progress', 'review', 'done'] as const;
export type TaskView = (typeof TASK_VIEWS)[number];

export const taskListQuerySchema = paginationQuerySchema.extend({
  view: z.enum(TASK_VIEWS).default('all'),
  projectId: z.uuid().optional(),
  assigneeId: z.uuid().optional(),
  /** Только задачи, где я ответственный. */
  mine: z
    .enum(['true', 'false'])
    .optional()
    .transform((v) => v === 'true'),
  status: z.enum(TASK_STATUSES).optional(),
  q: z.string().trim().max(100).optional(),
});
export type TaskListQuery = z.input<typeof taskListQuerySchema>;

const taskFields = {
  title: z.string().trim().min(1, 'Укажите название').max(300),
  description: z.string().trim().max(10_000).nullish(),
  assigneeId: z.uuid('Выберите исполнителя'),
  priority: z.enum(PRIORITIES).default('MEDIUM'),
  startDate: optDate,
  deadline: dateTime.nullish(),
};

export const createTaskSchema = z.object({ projectId: z.uuid('Выберите проект'), ...taskFields });
export type CreateTaskInput = z.input<typeof createTaskSchema>;

export const updateTaskSchema = z
  .object({
    title: taskFields.title,
    description: z.string().trim().max(10_000).nullable(),
    assigneeId: z.uuid('Выберите исполнителя'),
    priority: z.enum(PRIORITIES),
    startDate: dateOnly.nullable(),
    deadline: dateTime.nullable(),
    progressPct: z.coerce.number().int().min(0).max(100),
  })
  .partial();
export type UpdateTaskInput = z.input<typeof updateTaskSchema>;

/** Перемещение карточки Kanban: новый статус и место в колонке. */
export const moveTaskSchema = z.object({
  status: z.enum(TASK_STATUSES),
  /** id карточки, перед которой встала задача; null — в конец колонки. */
  beforeId: z.uuid().nullish(),
});
export type MoveTaskInput = z.input<typeof moveTaskSchema>;

export const taskCommentSchema = z.object({
  body: z.string().trim().min(1, 'Напишите комментарий').max(5000),
});

export interface TaskDto {
  id: string;
  number: string;
  project: NumberedRef;
  title: string;
  description: string | null;
  assignee: NamedRef;
  creator: NamedRef;
  /** Исполнитель — РОП «на время»: задача из шаблона ждёт исполнителя этой роли. */
  waitingForRole: boolean;
  templateRole: ExecutorSpecialty | null;
  priority: Priority;
  status: TaskStatus;
  startDate: string | null;
  deadline: string | null;
  /** Дней просрочки (0 — не просрочена). */
  overdueDays: number;
  progressPct: number;
  sortOrder: number;
  reworkCount: number;
  startedAt: string | null;
  completedAt: string | null;
  commentsCount: number;
  createdAt: string;
  /** Может ли текущий пользователь менять поля задачи (не только статус). */
  canEdit: boolean;
}

export interface TaskCommentDto {
  id: string;
  author: NamedRef;
  body: string;
  createdAt: string;
}

export interface TaskHistoryDto {
  id: string;
  from: TaskStatus | null;
  to: TaskStatus;
  changedBy: NamedRef;
  createdAt: string;
}

export interface TaskDetailDto extends TaskDto {
  comments: TaskCommentDto[];
  history: TaskHistoryDto[];
}

// ─────────────────────────── Шаблоны ───────────────────────────

export const taskTemplateSchema = z.object({
  title: z.string().trim().min(1, 'Укажите задачу').max(300),
  description: z.string().trim().max(5000).nullish(),
  role: z.enum(EXECUTOR_SPECIALTIES).nullish(),
  startOffsetDays: z.coerce.number().int().min(0).max(365).default(0),
  durationDays: z.coerce.number().int().min(1).max(365).default(1),
  priority: z.enum(PRIORITIES).default('MEDIUM'),
});
export type TaskTemplateInput = z.input<typeof taskTemplateSchema>;

export const upsertProjectTemplateSchema = z.object({
  name: z.string().trim().min(1, 'Укажите название').max(200),
  serviceId: z.uuid().nullish(),
  description: z.string().trim().max(2000).nullish(),
  isActive: z.boolean().default(true),
  tasks: z.array(taskTemplateSchema).min(1, 'Добавьте хотя бы одну задачу').max(100),
});
export type UpsertProjectTemplateInput = z.input<typeof upsertProjectTemplateSchema>;

export interface TaskTemplateDto {
  id: string;
  title: string;
  description: string | null;
  role: ExecutorSpecialty | null;
  startOffsetDays: number;
  durationDays: number;
  priority: Priority;
}

export interface ProjectTemplateDto {
  id: string;
  name: string;
  service: NamedRef | null;
  description: string | null;
  isActive: boolean;
  tasks: TaskTemplateDto[];
}

/** Кандидат в команду проекта. */
export interface MemberCandidateDto {
  id: string;
  name: string;
  role: RoleCode;
  specialty: ExecutorSpecialty | null;
}
