import { passwordSchema } from '@fluggi/contracts';
import { describe, expect, it } from 'vitest';
import { PasswordService } from './password.service';

describe('PasswordService', () => {
  const service = new PasswordService();

  it('временный пароль проходит политику паролей и уникален', () => {
    const passwords = new Set(Array.from({ length: 50 }, () => service.generateTemporary()));
    expect(passwords.size).toBe(50);
    for (const p of passwords) expect(passwordSchema.safeParse(p).success).toBe(true);
  });

  it('argon2id: хэш проверяется, неверный пароль — нет', async () => {
    const hash = await service.hash('Secret12345');
    expect(hash).toMatch(/^\$argon2id\$/);
    expect(await service.verify(hash, 'Secret12345')).toBe(true);
    expect(await service.verify(hash, 'Secret12346')).toBe(false);
    expect(await service.verify(null, 'Secret12345')).toBe(false);
  });
});
