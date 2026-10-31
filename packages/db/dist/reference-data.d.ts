/**
 * Обязательные справочники CRM (ТЗ §7, §9, §39, §61). Используются seed'ом и тестами.
 * Повторный запуск не перезаписывает изменения, сделанные в интерфейсе.
 */
import type { PrismaClient } from '@prisma/client';
export declare const SERVICES: [string, string][];
export declare const SOURCES: [string, string][];
export declare const LOSS_REASONS: [string, string][];
export declare const STAGES: {
    code: string;
    entity: 'LEAD' | 'DEAL';
    name: string;
    probability: number;
    color: string;
}[];
type Specialty = 'SMM' | 'DESIGNER' | 'VIDEOGRAPHER' | 'EDITOR' | 'TARGETOLOGIST' | 'DEVELOPER' | 'PHOTOGRAPHER' | 'COPYWRITER' | 'MOBILOGRAPHER' | 'BRANDFACE';
/** [название задачи, роль исполнителя, начало (дней от старта проекта), длительность (дней)] */
export declare const PROJECT_TEMPLATES: {
    service: string;
    name: string;
    tasks: [string, Specialty | null, number, number][];
}[];
/** Направления бизнеса группы и услуги каждого направления. */
export declare const DIRECTIONS: {
    code: string;
    name: string;
    services: string[];
}[];
export declare function seedReferences(prisma: PrismaClient): Promise<{
    services: number;
    sources: number;
    lossReasons: number;
    stages: number;
}>;
export declare const FINANCE_CATEGORIES: [string, 'EXPENSE' | 'INCOME', string, string, boolean][];
/** Единицы работ и базовые ставки (пример CEO; личные ставки — в карточке сотрудника). */
export declare const WORK_ITEMS: {
    code: string;
    name: string;
    unit: string;
    specialty: Specialty;
    defaultRate: number;
    currency: 'UZS' | 'USD';
}[];
type TariffSeed = {
    service: string;
    name: string;
    description: string;
    price: number;
    currency: 'UZS' | 'USD';
    sort: number;
    items: {
        kind: 'PIECE' | 'FIXED';
        workItem?: string;
        quantity?: number;
        specialty?: Specialty;
        amount?: number;
        currency?: 'UZS' | 'USD';
        label?: string;
    }[];
};
/** Тарифы — пример из ТЗ (CEO меняет цены и состав в настройках). */
export declare const TARIFFS: TariffSeed[];
export {};
