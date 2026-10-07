import { Module } from '@nestjs/common';
import { OverdueRunner, OverdueScanner } from './overdue.runner';

/** Подключается и в API, и в worker (как и обработка outbox). */
@Module({ providers: [OverdueScanner, OverdueRunner], exports: [OverdueScanner] })
export class OverdueModule {}
