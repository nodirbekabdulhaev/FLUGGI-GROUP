// Выполняется в каждом тестовом worker'е до импорта приложения.
process.env.DATABASE_URL = process.env.TEST_DATABASE_URL;
process.env.NODE_ENV = 'test';
process.env.RATE_LIMIT_DISABLED = 'true';
process.env.LOG_LEVEL = 'silent';
process.env.APP_URL = 'http://localhost:3000';
process.env.AUTH_SECRET ??= 'test-secret-test-secret-test-secret-test-secret';
process.env.OUTBOX_IN_API = 'false';
process.env.STORAGE_LOCAL_DIR = require('node:path').join(
  require('node:os').tmpdir(),
  'fluggi-test-storage',
);
process.env.SCHEDULER_ENABLED = 'false';
