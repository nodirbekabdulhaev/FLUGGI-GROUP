import { execSync } from 'node:child_process';
import path from 'node:path';

/** Применяет миграции к тестовой БД один раз перед интеграционными тестами. */
export default function setup() {
  const url = process.env.TEST_DATABASE_URL;
  if (!url) throw new Error('TEST_DATABASE_URL не задан');
  if (url === process.env.DATABASE_URL) {
    throw new Error('TEST_DATABASE_URL должен отличаться от DATABASE_URL — тесты очищают БД');
  }
  const opts = {
    cwd: path.resolve(__dirname, '../../../../packages/db'),
    env: { ...process.env, DATABASE_URL: url },
    stdio: 'inherit' as const,
  };
  execSync('pnpm exec prisma migrate deploy', opts);
  // Триггеры неизменяемости (prisma/protect.sql) — тесты проверяют защиту на уровне БД
  execSync(
    'pnpm exec prisma db execute --schema prisma/schema.prisma --file prisma/protect.sql',
    opts,
  );
}
