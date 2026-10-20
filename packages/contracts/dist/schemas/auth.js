"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.changePasswordSchema = exports.loginSchema = exports.emailSchema = exports.passwordSchema = exports.PASSWORD_MIN_LENGTH = void 0;
const zod_1 = require("zod");
exports.PASSWORD_MIN_LENGTH = 10;
exports.passwordSchema = zod_1.z
    .string()
    .min(exports.PASSWORD_MIN_LENGTH, `Минимум ${exports.PASSWORD_MIN_LENGTH} символов`)
    .max(128, 'Максимум 128 символов')
    .refine((v) => /[A-Za-zА-Яа-я]/.test(v) && /\d/.test(v), 'Пароль должен содержать буквы и цифры');
exports.emailSchema = zod_1.z
    .string()
    .trim()
    .toLowerCase()
    .pipe(zod_1.z.email('Некорректный email').max(254));
exports.loginSchema = zod_1.z.object({
    email: exports.emailSchema,
    password: zod_1.z.string().min(1, 'Введите пароль').max(128),
});
exports.changePasswordSchema = zod_1.z
    .object({
    currentPassword: zod_1.z.string().min(1, 'Введите текущий пароль').max(128),
    newPassword: exports.passwordSchema,
})
    .refine((v) => v.currentPassword !== v.newPassword, {
    path: ['newPassword'],
    message: 'Новый пароль должен отличаться от текущего',
});
//# sourceMappingURL=auth.js.map