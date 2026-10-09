import { createApp } from './app.factory';
import { loadEnv } from './config/env';

async function bootstrap() {
  const app = await createApp();
  await app.listen(loadEnv().PORT);
}

void bootstrap();
