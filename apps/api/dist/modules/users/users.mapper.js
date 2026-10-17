"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.userInclude = void 0;
exports.toUserDto = toUserDto;
exports.userInclude = {
    role: true,
    team: true,
    employee: true,
    directions: { include: { direction: { select: { id: true, name: true } } } },
};
function toUserDto(u) {
    return {
        id: u.id,
        email: u.email,
        fullName: u.fullName,
        phone: u.phone,
        status: u.status,
        locale: u.locale,
        position: u.employee?.position ?? null,
        specialty: u.employee?.specialty ?? null,
        role: { code: u.role.code, name: u.role.name },
        team: u.team && !u.team.deletedAt ? { id: u.team.id, name: u.team.name } : null,
        directions: u.directions.map((d) => d.direction),
        telegramLinked: u.telegramChatId !== null,
        lastLoginAt: u.lastLoginAt?.toISOString() ?? null,
        createdAt: u.createdAt.toISOString(),
    };
}
//# sourceMappingURL=users.mapper.js.map