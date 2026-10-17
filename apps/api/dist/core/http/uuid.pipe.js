"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.UuidPipe = void 0;
const app_exception_1 = require("./app.exception");
const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
/** Некорректный id в URL → 404, чтобы не отдавать 500 от Postgres. */
class UuidPipe {
    transform(value) {
        if (!UUID_RE.test(value))
            throw (0, app_exception_1.notFound)();
        return value;
    }
}
exports.UuidPipe = UuidPipe;
//# sourceMappingURL=uuid.pipe.js.map