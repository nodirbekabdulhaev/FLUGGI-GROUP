/**
 * Fluggi CRM одним процессом — для виртуального хостинга без Docker (Beget + Passenger).
 *
 * Запросы /api/* обрабатывает NestJS, всё остальное — Next.js. Переменные окружения
 * читаются из .env в корне проекта. Фоновые задачи запускает cron: POST /api/v1/internal/cron
 * (см. deploy/beget/README.md).
 *
 * Локально: node deploy/beget/server.js  (порт из PORT, по умолчанию 3000)
 */
'use strict';

const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');

// Passenger по умолчанию перехватывает первый listen(); у нас два сервера (внутренний сокет
// API и основной), поэтому подключаемся к Passenger явно: listen('passenger')
const passenger = typeof PhusionPassenger !== 'undefined';
// eslint-disable-next-line no-undef
if (passenger) PhusionPassenger.configure({ autoInstall: false });

const ROOT = path.resolve(__dirname, '../..');
const envFile = path.join(ROOT, '.env');
if (fs.existsSync(envFile)) process.loadEnvFile(envFile);
process.env.NODE_ENV = 'production';
process.env.TZ = process.env.TZ || 'Asia/Tashkent';
// Под Passenger приложение получает запросы через unix-сокет: адрес клиента берём
// из X-Forwarded-For ближайшего прокси хостинга (значение можно переопределить в .env)
process.env.TRUST_PROXY = process.env.TRUST_PROXY || '1';
// Файлы хранятся в папке проекта, если не задано иное
process.env.STORAGE_LOCAL_DIR = process.env.STORAGE_LOCAL_DIR || path.join(ROOT, 'storage');

async function main() {
  const { createApp } = require(path.join(ROOT, 'apps/api/dist/app.factory.js'));
  const api = await createApp();
  await api.init();
  const handleApi = api.getHttpAdapter().getInstance();

  // Серверные компоненты Next.js спрашивают API (кто вошёл) через unix-сокет этого же
  // процесса: на виртуальном хостинге нет своего TCP-порта для внутренних запросов
  const sockDir = path.join(ROOT, 'tmp');
  fs.mkdirSync(sockDir, { recursive: true });
  // Сокеты завершившихся процессов (Passenger может держать несколько процессов)
  for (const name of fs.readdirSync(sockDir)) {
    const pid = Number(/^api-(\d+)\.sock$/.exec(name)?.[1]);
    if (!pid) continue;
    try {
      process.kill(pid, 0);
    } catch {
      fs.rmSync(path.join(sockDir, name), { force: true });
    }
  }
  const socket = path.join(sockDir, `api-${process.pid}.sock`);
  fs.rmSync(socket, { force: true });
  const internal = http.createServer(handleApi);
  await new Promise((resolve) => internal.listen(socket, resolve));
  process.env.API_SOCKET = socket;

  const next = require(require.resolve('next', { paths: [path.join(ROOT, 'apps/web')] }));
  const web = next({ dev: false, dir: path.join(ROOT, 'apps/web') });
  await web.prepare();
  const handleWeb = web.getRequestHandler();

  const server = http.createServer((req, res) => {
    if (req.url === '/api' || req.url.startsWith('/api/')) handleApi(req, res);
    else handleWeb(req, res);
  });
  const shutdown = () => {
    server.close();
    internal.close();
    fs.rmSync(socket, { force: true });
    void api.close().finally(() => process.exit(0));
  };
  process.once('SIGTERM', shutdown);
  process.once('SIGINT', shutdown);
  const port = passenger ? 'passenger' : Number(process.env.PORT) || 3000;
  server.listen(port, () => console.log(`Fluggi CRM запущена (${port})`));
}

main().catch((err) => {
  console.error('Не удалось запустить Fluggi CRM:', err);
  process.exit(1);
});
