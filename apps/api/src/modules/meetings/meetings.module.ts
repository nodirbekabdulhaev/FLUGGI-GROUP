import { Module } from '@nestjs/common';
import { LeadsModule } from '../leads/leads.module';
import { MeetingsController } from './meetings.controller';
import { MeetingsService } from './meetings.service';

@Module({ imports: [LeadsModule], controllers: [MeetingsController], providers: [MeetingsService] })
export class MeetingsModule {}
