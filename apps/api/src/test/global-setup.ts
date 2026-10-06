import { execSync } from 'node:child_process';
import path from 'node:path';

/** Применяет миграции к тестовой БД один раз перед интеграционными тестами. */
export default function setup() {
  const url = process.env.TEST_DATABASE_URL;
  if (!url) throw new Error('TEST_DATABASE_URL не задан');
  if (url === process.env.DATABASE_URL) {
    throw new Error('TEST_DATABASE_URL должен отличаться от DATABASE_URL — тесты очищают БД');
  }
  execSync('pnpm exec prisma migrate deploy', {
    cwd: path.resolve(__dirname, '../../../../packages/db'),
    env: { ...process.env, DATABASE_URL: url },
    stdio: 'inherit',
  });
}
