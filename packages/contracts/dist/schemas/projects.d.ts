import { z } from 'zod';
import { type Currency, type ExecutorSpecialty, type Priority, type ProjectMemberStatus, type ProjectStatus, type RoleCode, type TaskStatus } from '../enums';
import type { NamedRef } from './references';
import type { NumberedRef } from './sales';
export declare const PROJECT_VIEWS: readonly ["all", "active", "overdue", "completed"];
export type ProjectView = (typeof PROJECT_VIEWS)[number];
export declare const projectListQuerySchema: z.ZodObject<{
    page: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
    pageSize: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
    view: z.ZodDefault<z.ZodEnum<{
        all: "all";
        active: "active";
        overdue: "overdue";
        completed: "completed";
    }>>;
    status: z.ZodOptional<z.ZodEnum<{
        PAUSED: "PAUSED";
        NEW: "NEW";
        CANCELLED: "CANCELLED";
        PLANNING: "PLANNING";
        IN_PROGRESS: "IN_PROGRESS";
        REVIEW: "REVIEW";
        WAITING_CLIENT: "WAITING_CLIENT";
        COMPLETED: "COMPLETED";
    }>>;
    ropId: z.ZodOptional<z.ZodUUID>;
    clientId: z.ZodOptional<z.ZodUUID>;
    dealId: z.ZodOptional<z.ZodUUID>;
    directionId: z.ZodOptional<z.ZodUUID>;
    q: z.ZodOptional<z.ZodString>;
}, z.core.$strip>;
export type ProjectListQuery = z.input<typeof projectListQuerySchema>;
export declare const updateProjectSchema: z.ZodObject<{
    name: z.ZodOptional<z.ZodString>;
    description: z.ZodOptional<z.ZodNullable<z.ZodString>>;
    priority: z.ZodOptional<z.ZodEnum<{
        LOW: "LOW";
        MEDIUM: "MEDIUM";
        HIGH: "HIGH";
        URGENT: "URGENT";
    }>>;
    startDate: z.ZodOptional<z.ZodOptional<z.ZodNullable<z.ZodISODate>>>;
    deadline: z.ZodOptional<z.ZodOptional<z.ZodNullable<z.ZodISODate>>>;
    ropId: z.ZodOptional<z.ZodUUID>;
    directionId: z.ZodOptional<z.ZodNullable<z.ZodUUID>>;
}, z.core.$strip>;
export type UpdateProjectInput = z.input<typeof updateProjectSchema>;
export declare const projectStatusSchema: z.ZodObject<{
    status: z.ZodEnum<{
        PAUSED: "PAUSED";
        NEW: "NEW";
        CANCELLED: "CANCELLED";
        PLANNING: "PLANNING";
        IN_PROGRESS: "IN_PROGRESS";
        REVIEW: "REVIEW";
        WAITING_CLIENT: "WAITING_CLIENT";
        COMPLETED: "COMPLETED";
    }>;
    comment: z.ZodOptional<z.ZodString>;
}, z.core.$strip>;
export type ProjectStatusInput = z.input<typeof projectStatusSchema>;
export declare const applyTemplateSchema: z.ZodObject<{
    templateId: z.ZodUUID;
}, z.core.$strip>;
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
export declare const addMemberSchema: z.ZodObject<{
    userId: z.ZodUUID;
    role: z.ZodOptional<z.ZodNullable<z.ZodEnum<{
        SMM: "SMM";
        DESIGNER: "DESIGNER";
        VIDEOGRAPHER: "VIDEOGRAPHER";
        EDITOR: "EDITOR";
        TARGETOLOGIST: "TARGETOLOGIST";
        DEVELOPER: "DEVELOPER";
        PHOTOGRAPHER: "PHOTOGRAPHER";
        COPYWRITER: "COPYWRITER";
        MOBILOGRAPHER: "MOBILOGRAPHER";
        BRANDFACE: "BRANDFACE";
    }>>>;
    workloadPct: z.ZodOptional<z.ZodNullable<z.ZodCoercedNumber<unknown>>>;
    deadline: z.ZodOptional<z.ZodNullable<z.ZodISODate>>;
}, z.core.$strip>;
export type AddMemberInput = z.input<typeof addMemberSchema>;
export declare const updateMemberSchema: z.ZodObject<{
    role: z.ZodOptional<z.ZodNullable<z.ZodEnum<{
        SMM: "SMM";
        DESIGNER: "DESIGNER";
        VIDEOGRAPHER: "VIDEOGRAPHER";
        EDITOR: "EDITOR";
        TARGETOLOGIST: "TARGETOLOGIST";
        DEVELOPER: "DEVELOPER";
        PHOTOGRAPHER: "PHOTOGRAPHER";
        COPYWRITER: "COPYWRITER";
        MOBILOGRAPHER: "MOBILOGRAPHER";
        BRANDFACE: "BRANDFACE";
    }>>>;
    workloadPct: z.ZodOptional<z.ZodNullable<z.ZodCoercedNumber<unknown>>>;
    deadline: z.ZodOptional<z.ZodNullable<z.ZodISODate>>;
    status: z.ZodOptional<z.ZodEnum<{
        ACTIVE: "ACTIVE";
        DONE: "DONE";
        REMOVED: "REMOVED";
    }>>;
}, z.core.$strip>;
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
export declare const TASK_VIEWS: readonly ["all", "today", "overdue", "in_progress", "review", "done"];
export type TaskView = (typeof TASK_VIEWS)[number];
export declare const taskListQuerySchema: z.ZodObject<{
    page: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
    pageSize: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
    view: z.ZodDefault<z.ZodEnum<{
        today: "today";
        all: "all";
        overdue: "overdue";
        in_progress: "in_progress";
        review: "review";
        done: "done";
    }>>;
    projectId: z.ZodOptional<z.ZodUUID>;
    assigneeId: z.ZodOptional<z.ZodUUID>;
    mine: z.ZodPipe<z.ZodOptional<z.ZodEnum<{
        true: "true";
        false: "false";
    }>>, z.ZodTransform<boolean, "true" | "false" | undefined>>;
    status: z.ZodOptional<z.ZodEnum<{
        BLOCKED: "BLOCKED";
        DONE: "DONE";
        CANCELLED: "CANCELLED";
        IN_PROGRESS: "IN_PROGRESS";
        REVIEW: "REVIEW";
        TODO: "TODO";
    }>>;
    q: z.ZodOptional<z.ZodString>;
}, z.core.$strip>;
export type TaskListQuery = z.input<typeof taskListQuerySchema>;
export declare const createTaskSchema: z.ZodObject<{
    title: z.ZodString;
    description: z.ZodOptional<z.ZodNullable<z.ZodString>>;
    assigneeId: z.ZodUUID;
    priority: z.ZodDefault<z.ZodEnum<{
        LOW: "LOW";
        MEDIUM: "MEDIUM";
        HIGH: "HIGH";
        URGENT: "URGENT";
    }>>;
    startDate: z.ZodOptional<z.ZodNullable<z.ZodISODate>>;
    deadline: z.ZodOptional<z.ZodNullable<z.ZodISODateTime>>;
    projectId: z.ZodUUID;
}, z.core.$strip>;
export type CreateTaskInput = z.input<typeof createTaskSchema>;
export declare const updateTaskSchema: z.ZodObject<{
    title: z.ZodOptional<z.ZodString>;
    description: z.ZodOptional<z.ZodNullable<z.ZodString>>;
    assigneeId: z.ZodOptional<z.ZodUUID>;
    priority: z.ZodOptional<z.ZodEnum<{
        LOW: "LOW";
        MEDIUM: "MEDIUM";
        HIGH: "HIGH";
        URGENT: "URGENT";
    }>>;
    startDate: z.ZodOptional<z.ZodNullable<z.ZodISODate>>;
    deadline: z.ZodOptional<z.ZodNullable<z.ZodISODateTime>>;
    progressPct: z.ZodOptional<z.ZodCoercedNumber<unknown>>;
}, z.core.$strip>;
export type UpdateTaskInput = z.input<typeof updateTaskSchema>;
/** Перемещение карточки Kanban: новый статус и место в колонке. */
export declare const moveTaskSchema: z.ZodObject<{
    status: z.ZodEnum<{
        BLOCKED: "BLOCKED";
        DONE: "DONE";
        CANCELLED: "CANCELLED";
        IN_PROGRESS: "IN_PROGRESS";
        REVIEW: "REVIEW";
        TODO: "TODO";
    }>;
    beforeId: z.ZodOptional<z.ZodNullable<z.ZodUUID>>;
}, z.core.$strip>;
export type MoveTaskInput = z.input<typeof moveTaskSchema>;
export declare const taskCommentSchema: z.ZodObject<{
    body: z.ZodString;
}, z.core.$strip>;
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
export declare const taskTemplateSchema: z.ZodObject<{
    title: z.ZodString;
    description: z.ZodOptional<z.ZodNullable<z.ZodString>>;
    role: z.ZodOptional<z.ZodNullable<z.ZodEnum<{
        SMM: "SMM";
        DESIGNER: "DESIGNER";
        VIDEOGRAPHER: "VIDEOGRAPHER";
        EDITOR: "EDITOR";
        TARGETOLOGIST: "TARGETOLOGIST";
        DEVELOPER: "DEVELOPER";
        PHOTOGRAPHER: "PHOTOGRAPHER";
        COPYWRITER: "COPYWRITER";
        MOBILOGRAPHER: "MOBILOGRAPHER";
        BRANDFACE: "BRANDFACE";
    }>>>;
    startOffsetDays: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
    durationDays: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
    priority: z.ZodDefault<z.ZodEnum<{
        LOW: "LOW";
        MEDIUM: "MEDIUM";
        HIGH: "HIGH";
        URGENT: "URGENT";
    }>>;
}, z.core.$strip>;
export type TaskTemplateInput = z.input<typeof taskTemplateSchema>;
export declare const upsertProjectTemplateSchema: z.ZodObject<{
    name: z.ZodString;
    serviceId: z.ZodOptional<z.ZodNullable<z.ZodUUID>>;
    description: z.ZodOptional<z.ZodNullable<z.ZodString>>;
    isActive: z.ZodDefault<z.ZodBoolean>;
    tasks: z.ZodArray<z.ZodObject<{
        title: z.ZodString;
        description: z.ZodOptional<z.ZodNullable<z.ZodString>>;
        role: z.ZodOptional<z.ZodNullable<z.ZodEnum<{
            SMM: "SMM";
            DESIGNER: "DESIGNER";
            VIDEOGRAPHER: "VIDEOGRAPHER";
            EDITOR: "EDITOR";
            TARGETOLOGIST: "TARGETOLOGIST";
            DEVELOPER: "DEVELOPER";
            PHOTOGRAPHER: "PHOTOGRAPHER";
            COPYWRITER: "COPYWRITER";
            MOBILOGRAPHER: "MOBILOGRAPHER";
            BRANDFACE: "BRANDFACE";
        }>>>;
        startOffsetDays: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
        durationDays: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
        priority: z.ZodDefault<z.ZodEnum<{
            LOW: "LOW";
            MEDIUM: "MEDIUM";
            HIGH: "HIGH";
            URGENT: "URGENT";
        }>>;
    }, z.core.$strip>>;
}, z.core.$strip>;
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
