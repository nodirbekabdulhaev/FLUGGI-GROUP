import { defineConfig, devices } from '@playwright/test';

/**
 * E2E против запущенных web (3000) и api (4000) на демо-данных из seed.
 * Локально: pnpm build && pnpm db:seed, затем запустить api и web (см. README) и pnpm test:e2e.
 */
export default defineConfig({
  testDir: './e2e',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [['list']],
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:3000',
    locale: 'ru-RU',
    timezoneId: 'Asia/Tashkent',
    trace: 'retain-on-failure',
  },
  projects: [
    {
      name: 'desktop',
      use: {
        ...devices['Desktop Chrome'],
        launchOptions: { executablePath: process.env.CHROMIUM_PATH },
      },
    },
    {
      name: 'mobile',
      use: { ...devices['Pixel 7'], launchOptions: { executablePath: process.env.CHROMIUM_PATH } },
    },
  ],
});
