import type { UserDto } from '@fluggi/contracts';
import type { Prisma } from '@fluggi/db';

export const userInclude = {
  role: true,
  team: true,
  employee: true,
} satisfies Prisma.UserInclude;

export type UserWithRelations = Prisma.UserGetPayload<{ include: typeof userInclude }>;

export function toUserDto(u: UserWithRelations): UserDto {
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
    telegramLinked: u.telegramChatId !== null,
    lastLoginAt: u.lastLoginAt?.toISOString() ?? null,
    createdAt: u.createdAt.toISOString(),
  };
}
