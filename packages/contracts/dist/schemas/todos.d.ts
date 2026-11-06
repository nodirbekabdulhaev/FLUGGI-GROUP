import { z } from 'zod';
import { type Priority } from '../enums';
import type { NamedRef } from './references';
import type { NumberedRef } from './sales';
export declare const TODO_KINDS: readonly ["TASK", "CALL", "EMAIL", "MEETING", "PAYMENT", "REPORT"];
export type TodoKind = (typeof TODO_KINDS)[number];
export declare const TODO_STATUSES: readonly ["OPEN", "DONE", "CANCELLED"];
export type TodoStatus = (typeof TODO_STATUSES)[number];
export declare const createTodoSchema: z.ZodObject<{
    title: z.ZodString;
    description: z.ZodOptional<z.ZodNullable<z.ZodString>>;
    kind: z.ZodDefault<z.ZodEnum<{
        PAYMENT: "PAYMENT";
        TASK: "TASK";
        CALL: "CALL";
        EMAIL: "EMAIL";
        MEETING: "MEETING";
        REPORT: "REPORT";
    }>>;
    priority: z.ZodDefault<z.ZodEnum<{
        LOW: "LOW";
        MEDIUM: "MEDIUM";
        HIGH: "HIGH";
        URGENT: "URGENT";
    }>>;
    dueAt: z.ZodOptional<z.ZodNullable<z.ZodISODateTime>>;
    ownerId: z.ZodOptional<z.ZodUUID>;
    clientId: z.ZodOptional<z.ZodNullable<z.ZodUUID>>;
    dealId: z.ZodOptional<z.ZodNullable<z.ZodUUID>>;
    leadId: z.ZodOptional<z.ZodNullable<z.ZodUUID>>;
}, z.core.$strip>;
export type CreateTodoInput = z.input<typeof createTodoSchema>;
export declare const updateTodoSchema: z.ZodObject<{
    description: z.ZodOptional<z.ZodOptional<z.ZodNullable<z.ZodString>>>;
    kind: z.ZodOptional<z.ZodDefault<z.ZodEnum<{
        PAYMENT: "PAYMENT";
        TASK: "TASK";
        CALL: "CALL";
        EMAIL: "EMAIL";
        MEETING: "MEETING";
        REPORT: "REPORT";
    }>>>;
    priority: z.ZodOptional<z.ZodDefault<z.ZodEnum<{
        LOW: "LOW";
        MEDIUM: "MEDIUM";
        HIGH: "HIGH";
        URGENT: "URGENT";
    }>>>;
    dueAt: z.ZodOptional<z.ZodOptional<z.ZodNullable<z.ZodISODateTime>>>;
    ownerId: z.ZodOptional<z.ZodOptional<z.ZodUUID>>;
    clientId: z.ZodOptional<z.ZodOptional<z.ZodNullable<z.ZodUUID>>>;
    dealId: z.ZodOptional<z.ZodOptional<z.ZodNullable<z.ZodUUID>>>;
    leadId: z.ZodOptional<z.ZodOptional<z.ZodNullable<z.ZodUUID>>>;
    title: z.ZodOptional<z.ZodString>;
}, z.core.$strip>;
export type UpdateTodoInput = z.input<typeof updateTodoSchema>;
export declare const todoListQuerySchema: z.ZodObject<{
    view: z.ZodDefault<z.ZodEnum<{
        all: "all";
        mine: "mine";
        assigned: "assigned";
    }>>;
    status: z.ZodOptional<z.ZodEnum<{
        OPEN: "OPEN";
        DONE: "DONE";
        CANCELLED: "CANCELLED";
    }>>;
    clientId: z.ZodOptional<z.ZodUUID>;
    dealId: z.ZodOptional<z.ZodUUID>;
    page: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
    pageSize: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
}, z.core.$strip>;
export type TodoListQuery = z.input<typeof todoListQuerySchema>;
export interface TodoDto {
    id: string;
    number: string;
    title: string;
    description: string | null;
    kind: TodoKind;
    priority: Priority;
    status: TodoStatus;
    dueAt: string | null;
    overdue: boolean;
    owner: NamedRef;
    creator: NamedRef;
    client: NamedRef | null;
    deal: NumberedRef | null;
    lead: NumberedRef | null;
    recurring: boolean;
    completedAt: string | null;
    createdAt: string;
    can: {
        update: boolean;
    };
}
/** Панель «Список дел»: мои открытые дела и мои задачи по проектам. */
/** Панель «Список дел» — только личные дела пользователя. */
export interface TodoDockDto {
    todos: TodoDto[];
}
export declare const RECURRENCE_FREQUENCIES: readonly ["MONTHLY", "QUARTERLY", "YEARLY"];
export type RecurrenceFrequency = (typeof RECURRENCE_FREQUENCIES)[number];
export declare const recurringTodoSchema: z.ZodObject<{
    title: z.ZodString;
    description: z.ZodOptional<z.ZodNullable<z.ZodString>>;
    kind: z.ZodDefault<z.ZodEnum<{
        PAYMENT: "PAYMENT";
        TASK: "TASK";
        CALL: "CALL";
        EMAIL: "EMAIL";
        MEETING: "MEETING";
        REPORT: "REPORT";
    }>>;
    priority: z.ZodDefault<z.ZodEnum<{
        LOW: "LOW";
        MEDIUM: "MEDIUM";
        HIGH: "HIGH";
        URGENT: "URGENT";
    }>>;
    ownerId: z.ZodOptional<z.ZodUUID>;
    frequency: z.ZodEnum<{
        MONTHLY: "MONTHLY";
        QUARTERLY: "QUARTERLY";
        YEARLY: "YEARLY";
    }>;
    dayOfMonth: z.ZodCoercedNumber<unknown>;
    month: z.ZodOptional<z.ZodNullable<z.ZodCoercedNumber<unknown>>>;
    remindDaysBefore: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
    isActive: z.ZodDefault<z.ZodBoolean>;
}, z.core.$strip>;
export type RecurringTodoInput = z.input<typeof recurringTodoSchema>;
export interface RecurringTodoDto {
    id: string;
    title: string;
    description: string | null;
    kind: TodoKind;
    priority: Priority;
    owner: NamedRef;
    frequency: RecurrenceFrequency;
    dayOfMonth: number;
    month: number | null;
    remindDaysBefore: number;
    isActive: boolean;
    /** Ближайший срок YYYY-MM-DD и название с подставленным периодом */
    nextDue: string;
    nextTitle: string;
}
/**
 * Налоговый календарь резидента IT-Park (по списку CEO). Названия — шаблоны:
 * {прошлый_месяц}, {прошлый_квартал}, {прошлый_год}, {год} подставляются от срока.
 */
export declare const TAX_CALENDAR: Omit<RecurringTodoInput, 'ownerId'>[];
export declare const chatMessageSchema: z.ZodObject<{
    body: z.ZodString;
}, z.core.$strip>;
export declare const directChatSchema: z.ZodObject<{
    userId: z.ZodUUID;
}, z.core.$strip>;
export declare const chatMessagesQuerySchema: z.ZodObject<{
    before: z.ZodOptional<z.ZodISODateTime>;
    limit: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
}, z.core.$strip>;
export interface ChatMessageDto {
    id: string;
    conversationId: string;
    author: NamedRef;
    body: string;
    createdAt: string;
    mine: boolean;
}
export interface ConversationDto {
    id: string;
    /** Собеседник (личная переписка) */
    peer: NamedRef & {
        role: string;
    };
    lastMessage: {
        body: string;
        createdAt: string;
        mine: boolean;
    } | null;
    unread: number;
}
export interface ChatContactDto extends NamedRef {
    role: string;
    team: string | null;
}
