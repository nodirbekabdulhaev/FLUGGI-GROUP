import { Module } from '@nestjs/common';
import { ContractsModule } from '../contracts/contracts.module';
import { ProposalsModule } from '../proposals/proposals.module';
import { DocumentsController } from './documents.controller';
import { DocumentsService } from './documents.service';

@Module({
  imports: [ProposalsModule, ContractsModule],
  controllers: [DocumentsController],
  providers: [DocumentsService],
})
export class DocumentsModule {}
