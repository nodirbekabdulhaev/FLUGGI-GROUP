import { Module } from '@nestjs/common';
import { OverdueScanner } from './overdue.runner';

/** Поиск просрочек; запускает планировщик (модуль automation) раз в час. */
@Module({ providers: [OverdueScanner], exports: [OverdueScanner] })
export class OverdueModule {}
