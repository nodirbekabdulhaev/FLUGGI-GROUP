import { PipeTransform } from '@nestjs/common';
import { notFound } from './app.exception';

const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

/** Некорректный id в URL → 404, чтобы не отдавать 500 от Postgres. */
export class UuidPipe implements PipeTransform<string, string> {
  transform(value: string): string {
    if (!UUID_RE.test(value)) throw notFound();
    return value;
  }
}
