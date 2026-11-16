import swc from 'unplugin-swc';
import { defineConfig } from 'vitest/config';

// SWC нужен, чтобы сохранялись метаданные декораторов (DI в NestJS).
export default defineConfig({
  plugins: [swc.vite({ module: { type: 'es6' } })],
  test: {
    projects: [
      {
        extends: true,
        test: { name: 'unit', include: ['src/**/*.test.ts'], exclude: ['src/**/*.int.test.ts'] },
      },
      {
        extends: true,
        test: {
          name: 'integration',
          include: ['src/**/*.int.test.ts'],
          globalSetup: ['src/test/global-setup.ts'],
          setupFiles: ['src/test/setup-env.ts'],
          fileParallelism: false,
          testTimeout: 20000,
          hookTimeout: 60000,
        },
      },
    ],
  },
});
