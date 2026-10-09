"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.updateRolePermissionsSchema = void 0;
const zod_1 = require("zod");
const enums_1 = require("../enums");
const permissions_1 = require("../permissions");
exports.updateRolePermissionsSchema = zod_1.z.object({
    // partialRecord: в Zod 4 z.record с enum-ключом требует все ключи.
    permissions: zod_1.z.partialRecord(zod_1.z.enum(permissions_1.PERMISSION_CODES), zod_1.z.enum(enums_1.SCOPES)),
});
//# sourceMappingURL=roles.js.map