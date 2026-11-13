import { Module } from '@nestjs/common';
import { FinanceModule } from '../finance/finance.module';
import { CatalogController } from './catalog.controller';
import { CatalogService } from './catalog.service';

@Module({ imports: [FinanceModule], controllers: [CatalogController], providers: [CatalogService] })
export class CatalogModule {}
