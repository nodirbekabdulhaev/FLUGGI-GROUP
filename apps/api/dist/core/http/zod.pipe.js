"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.zod = exports.ZodPipe = void 0;
const app_exception_1 = require("./app.exception");
/** Валидация body/query/params Zod-схемой из @fluggi/contracts. */
class ZodPipe {
    schema;
    constructor(schema) {
        this.schema = schema;
    }
    transform(value) {
        const result = this.schema.safeParse(value);
        if (result.success)
            return result.data;
        throw new app_exception_1.AppException('VALIDATION_ERROR', 'Проверьте правильность заполнения полей', result.error.issues.map((i) => ({
            path: i.path.map(String).join('.'),
            message: i.message,
        })));
    }
}
exports.ZodPipe = ZodPipe;
const zod = (schema) => new ZodPipe(schema);
exports.zod = zod;
//# sourceMappingURL=zod.pipe.js.map