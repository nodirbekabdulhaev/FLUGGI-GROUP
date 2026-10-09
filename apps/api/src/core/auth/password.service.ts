import { randomBytes } from 'node:crypto';
import { Injectable } from '@nestjs/common';
import { hash, verify } from '@node-rs/argon2';

// Параметры argon2id по рекомендации OWASP (19 MiB, 2 итерации).
const OPTIONS = { memoryCost: 19456, timeCost: 2, parallelism: 1 } as const;

@Injectable()
export class PasswordService {
  /** Хэш для выравнивания времени ответа, когда пользователь не найден. */
  private dummyHash: Promise<string> | undefined;

  hash(password: string): Promise<string> {
    return hash(password, OPTIONS);
  }

  async verify(passwordHash: string | null, password: string): Promise<boolean> {
    if (!passwordHash) {
      this.dummyHash ??= hash('dummy-password-for-timing', OPTIONS);
      await verify(await this.dummyHash, password).catch(() => false);
      return false;
    }
    return verify(passwordHash, password).catch(() => false);
  }

  /** Временный пароль: 14 символов, гарантированно буквы и цифры. */
  generateTemporary(): string {
    const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    const bytes = randomBytes(12);
    let body = '';
    for (const b of bytes) body += alphabet[b % alphabet.length];
    return `${body}${2 + (bytes[0]! % 8)}x`;
  }
}
