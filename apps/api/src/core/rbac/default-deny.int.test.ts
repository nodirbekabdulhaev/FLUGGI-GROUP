import type { INestApplication } from '@nestjs/common';
import { MetadataScanner, ModulesContainer, Reflector } from '@nestjs/core';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { createTestApp } from '../../test/helpers';
import { AUTHENTICATED_ONLY, IS_PUBLIC, REQUIRED_PERMISSION } from '../auth/decorators';

let app: INestApplication;
beforeAll(async () => ({ app } = await createTestApp()));
afterAll(() => app.close());

describe('RBAC', () => {
  it('каждый endpoint явно объявляет требования к доступу', () => {
    const modules = app.get(ModulesContainer);
    const scanner = new MetadataScanner();
    const reflector = app.get(Reflector);
    const missing: string[] = [];
    const controllers = [...modules.values()].flatMap((m) => [...m.controllers.values()]);
    expect(controllers.length).toBeGreaterThan(3);
    for (const wrapper of controllers) {
      const instance = wrapper.instance as object;
      const proto = Object.getPrototypeOf(instance) as Record<string, unknown>;
      for (const name of scanner.getAllMethodNames(proto)) {
        const handler = proto[name] as () => unknown;
        // Только HTTP-обработчики (методы с маршрутом), а не служебные методы контроллера.
        if (Reflect.getMetadata('path', handler) === undefined) continue;
        const targets = [handler, wrapper.metatype as () => unknown];
        const declared =
          reflector.getAllAndOverride(IS_PUBLIC, targets) ||
          reflector.getAllAndOverride(AUTHENTICATED_ONLY, targets) ||
          reflector.getAllAndOverride(REQUIRED_PERMISSION, targets);
        if (!declared) missing.push(`${wrapper.name}.${name}`);
      }
    }
    expect(missing).toEqual([]);
  });
});
