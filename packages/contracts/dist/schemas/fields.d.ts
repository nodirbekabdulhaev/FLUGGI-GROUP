import { z } from 'zod';
/** Необязательная строка: пустая строка из формы → undefined (create) / null (update). */
export declare const optText: (max?: number) => z.ZodPipe<z.ZodOptional<z.ZodString>, z.ZodTransform<string | undefined, string | undefined>>;
export declare const nullableText: (max?: number) => z.ZodPipe<z.ZodNullable<z.ZodString>, z.ZodTransform<string | null, string | null>>;
/** Деньги: строка-decimal с 2 знаками, не отрицательная. Принимает и число. */
export declare const moneySchema: z.ZodPipe<z.ZodPipe<z.ZodUnion<readonly [z.ZodString, z.ZodNumber]>, z.ZodTransform<string, string | number>>, z.ZodString>;
export declare const optMoney: z.ZodPipe<z.ZodPipe<z.ZodOptional<z.ZodUnion<readonly [z.ZodString, z.ZodNumber]>>, z.ZodTransform<string | number | undefined, string | number | undefined>>, z.ZodOptional<z.ZodPipe<z.ZodPipe<z.ZodUnion<readonly [z.ZodString, z.ZodNumber]>, z.ZodTransform<string, string | number>>, z.ZodString>>>;
export declare const dateOnly: z.ZodISODate;
export declare const dateTime: z.ZodISODateTime;
export declare const phoneText: z.ZodPipe<z.ZodOptional<z.ZodString>, z.ZodTransform<string | undefined, string | undefined>>;
export declare const emailText: z.ZodPipe<z.ZodPipe<z.ZodOptional<z.ZodString>, z.ZodTransform<string | undefined, string | undefined>>, z.ZodOptional<z.ZodEmail>>;
