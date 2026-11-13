import { z } from 'zod';

/** Необязательная строка: пустая строка из формы → undefined (create) / null (update). */
export const optText = (max = 200) =>
  z
    .string()
    .trim()
    .max(max)
    .optional()
    .transform((v) => (v ? v : undefined));

export const nullableText = (max = 200) =>
  z
    .string()
    .trim()
    .max(max)
    .nullable()
    .transform((v) => (v ? v : null));

/** Деньги: строка-decimal с 2 знаками, не отрицательная. Принимает и число. */
export const moneySchema = z
  .union([z.string(), z.number()])
  .transform((v) => String(v).replace(/\s/g, '').replace(',', '.'))
  .pipe(z.string().regex(/^\d{1,16}(\.\d{1,2})?$/, 'Некорректная сумма'));

export const optMoney = z
  .union([z.string(), z.number()])
  .optional()
  .transform((v) => (v === undefined || v === '' ? undefined : v))
  .pipe(moneySchema.optional());

export const dateOnly = z.iso.date('Некорректная дата');
export const dateTime = z.iso.datetime({ offset: true, message: 'Некорректные дата и время' });

export const phoneText = z
  .string()
  .trim()
  .max(30)
  .regex(/^[+0-9\s\-()]*$/, 'Некорректный телефон')
  .optional()
  .transform((v) => (v ? v : undefined));

export const emailText = z
  .string()
  .trim()
  .toLowerCase()
  .max(254)
  .optional()
  .transform((v) => (v ? v : undefined))
  .pipe(z.email('Некорректный email').optional());
