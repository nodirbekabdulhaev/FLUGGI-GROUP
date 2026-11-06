"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.emailText = exports.phoneText = exports.dateTime = exports.dateOnly = exports.optMoney = exports.moneySchema = exports.nullableText = exports.optText = void 0;
const zod_1 = require("zod");
/** Необязательная строка: пустая строка из формы → undefined (create) / null (update). */
const optText = (max = 200) => zod_1.z
    .string()
    .trim()
    .max(max)
    .optional()
    .transform((v) => (v ? v : undefined));
exports.optText = optText;
const nullableText = (max = 200) => zod_1.z
    .string()
    .trim()
    .max(max)
    .nullable()
    .transform((v) => (v ? v : null));
exports.nullableText = nullableText;
/** Деньги: строка-decimal с 2 знаками, не отрицательная. Принимает и число. */
exports.moneySchema = zod_1.z
    .union([zod_1.z.string(), zod_1.z.number()])
    .transform((v) => String(v).replace(/\s/g, '').replace(',', '.'))
    .pipe(zod_1.z.string().regex(/^\d{1,16}(\.\d{1,2})?$/, 'Некорректная сумма'));
exports.optMoney = zod_1.z
    .union([zod_1.z.string(), zod_1.z.number()])
    .optional()
    .transform((v) => (v === undefined || v === '' ? undefined : v))
    .pipe(exports.moneySchema.optional());
exports.dateOnly = zod_1.z.iso.date('Некорректная дата');
exports.dateTime = zod_1.z.iso.datetime({ offset: true, message: 'Некорректные дата и время' });
exports.phoneText = zod_1.z
    .string()
    .trim()
    .max(30)
    .regex(/^[+0-9\s\-()]*$/, 'Некорректный телефон')
    .optional()
    .transform((v) => (v ? v : undefined));
exports.emailText = zod_1.z
    .string()
    .trim()
    .toLowerCase()
    .max(254)
    .optional()
    .transform((v) => (v ? v : undefined))
    .pipe(zod_1.z.email('Некорректный email').optional());
//# sourceMappingURL=fields.js.map