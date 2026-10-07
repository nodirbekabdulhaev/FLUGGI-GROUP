import { z } from 'zod';

const envSchema = z.object({
  NODE_ENV: z.enum(['development', 'test', 'production']).default('development'),
  PORT: z.coerce.number().int().positive().default(4000),
  DATABASE_URL: z.string().min(1, 'DATABASE_URL обязателен'),
  AUTH_SECRET: z.string().min(32, 'AUTH_SECRET должен быть не короче 32 символов'),
  APP_URL: z.url().default('http://localhost:3000'),
  SESSION_TTL_DAYS: z.coerce.number().int().min(1).max(90).default(7),
  LOG_LEVEL: z.enum(['fatal', 'error', 'warn', 'info', 'debug', 'trace', 'silent']).default('info'),
  /** Значение для express `trust proxy` (за Nginx / Next.js rewrite). */
  TRUST_PROXY: z.string().default('loopback, linklocal, uniquelocal'),
  /** Отключает rate limit (только для тестов). */
  /** Telegram-бот (ТЗ §53). Без токена уведомления только в CRM. */
  TELEGRAM_BOT_TOKEN: z
    .string()
    .optional()
    .transform((v) => v || undefined),
  TELEGRAM_BOT_USERNAME: z
    .string()
    .optional()
    .transform((v) => v?.replace(/^@/, '') || undefined),
  TELEGRAM_WEBHOOK_SECRET: z
    .string()
    .optional()
    .transform((v) => v || undefined),
  /** polling — бот сам забирает сообщения (локально, без домена); webhook — Telegram присылает их на /api/v1/telegram/webhook */
  TELEGRAM_MODE: z.enum(['polling', 'webhook']).default('polling'),
  TELEGRAM_API_BASE: z.url().default('https://api.telegram.org'),
  /** Планировщик (напоминания, отчёты). false — выключить (тесты, отдельный API без worker). */
  SCHEDULER_ENABLED: z
    .enum(['true', 'false'])
    .default('true')
    .transform((v) => v === 'true'),
  RATE_LIMIT_DISABLED: z
    .enum(['true', 'false'])
    .default('false')
    .transform((v) => v === 'true'),
});

export type Env = z.infer<typeof envSchema>;

let cached: Env | undefined;

export function loadEnv(): Env {
  if (cached) return cached;
  const parsed = envSchema.safeParse(process.env);
  if (!parsed.success) {
    const issues = parsed.error.issues.map((i) => `  ${i.path.join('.')}: ${i.message}`).join('\n');
    throw new Error(`Некорректные переменные окружения:\n${issues}`);
  }
  cached = parsed.data;
  return cached;
}

/** Для тестов: сбросить кэш после изменения process.env. */
export function resetEnvCache() {
  cached = undefined;
}
