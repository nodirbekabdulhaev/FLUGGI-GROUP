import { PipeTransform } from '@nestjs/common';
import type { z } from 'zod';
import { AppException } from './app.exception';

/** Валидация body/query/params Zod-схемой из @fluggi/contracts. */
export class ZodPipe<T extends z.ZodType> implements PipeTransform<unknown, z.output<T>> {
  constructor(private readonly schema: T) {}

  transform(value: unknown): z.output<T> {
    const result = this.schema.safeParse(value);
    if (result.success) return result.data;
    throw new AppException(
      'VALIDATION_ERROR',
      'Проверьте правильность заполнения полей',
      result.error.issues.map((i) => ({
        path: i.path.map(String).join('.'),
        message: i.message,
      })),
    );
  }
}

export const zod = <T extends z.ZodType>(schema: T) => new ZodPipe(schema);
