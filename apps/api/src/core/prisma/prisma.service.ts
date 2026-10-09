import { Injectable, OnModuleDestroy, OnModuleInit } from '@nestjs/common';
import { Prisma, PrismaClient } from '@fluggi/db';

/** Поддерживает ли сервер `FOR UPDATE SKIP LOCKED` (MySQL 8.0+, MariaDB 10.6+). */
export function supportsSkipLocked(version: string): boolean {
  const [major = 0, minor = 0] = version.split(/[.-]/).map(Number);
  if (/mariadb/i.test(version)) return major > 10 || (major === 10 && minor >= 6);
  return major >= 8;
}

@Injectable()
export class PrismaService extends PrismaClient implements OnModuleInit, OnModuleDestroy {
  /**
   * Окончание блокирующего SELECT для очередей (outbox, Telegram): на MySQL 8 занятые строки
   * пропускаются, на MySQL 5.7 (виртуальный хостинг) — ожидание блокировки.
   */
  lockRows: Prisma.Sql = Prisma.sql`FOR UPDATE`;

  async onModuleInit() {
    await this.$connect();
    const [row] = await this.$queryRaw<{ v: string }[]>`SELECT VERSION() AS v`;
    if (row && supportsSkipLocked(row.v)) this.lockRows = Prisma.sql`FOR UPDATE SKIP LOCKED`;
  }

  async onModuleDestroy() {
    await this.$disconnect();
  }
}

/** Клиент внутри `prisma.$transaction(async (tx) => …)`. */
export type Tx = Omit<
  PrismaClient,
  '$connect' | '$disconnect' | '$on' | '$transaction' | '$extends'
>;
