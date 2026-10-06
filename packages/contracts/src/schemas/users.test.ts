import { describe, expect, it } from 'vitest';
import { createUserSchema, updateUserSchema } from './users';

describe('user schemas', () => {
  it('update не подставляет значения по умолчанию', () => {
    expect(updateUserSchema.parse({ fullName: 'Иван Петров' })).toEqual({ fullName: 'Иван Петров' });
  });
  it('update с пустым объектом отклоняется', () => {
    expect(updateUserSchema.safeParse({}).success).toBe(false);
  });
  it('create нормализует email и задаёт локаль', () => {
    const v = createUserSchema.parse({ email: ' Test@Fluggi.UZ ', fullName: 'Тест', roleCode: 'MANAGER' });
    expect(v.email).toBe('test@fluggi.uz');
    expect(v.locale).toBe('ru');
  });
});
