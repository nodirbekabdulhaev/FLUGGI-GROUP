import { Global, Module } from '@nestjs/common';
import { ExchangeRateService } from './exchange-rate.service';
import { ReferencesController } from './references.controller';
import { ReferencesService } from './references.service';

@Global()
@Module({
  controllers: [ReferencesController],
  providers: [ReferencesService, ExchangeRateService],
  exports: [ExchangeRateService],
})
export class ReferencesModule {}
