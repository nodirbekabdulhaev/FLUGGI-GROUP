"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.updateTeamSchema = exports.createTeamSchema = void 0;
const zod_1 = require("zod");
exports.createTeamSchema = zod_1.z.object({
    name: zod_1.z.string().trim().min(2, 'Укажите название').max(120),
    headId: zod_1.z.uuid().nullish(),
});
exports.updateTeamSchema = exports.createTeamSchema.partial();
//# sourceMappingURL=teams.js.map