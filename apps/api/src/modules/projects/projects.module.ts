import { Module } from '@nestjs/common';
import { ProjectAccessService } from './project-access.service';
import { ProjectsController } from './projects.controller';
import { ProjectsService } from './projects.service';
import { TasksController } from './tasks.controller';
import { TasksService } from './tasks.service';
import { TemplatesController } from './templates.controller';
import { TemplatesService } from './templates.service';

@Module({
  controllers: [ProjectsController, TasksController, TemplatesController],
  providers: [ProjectAccessService, ProjectsService, TasksService, TemplatesService],
  exports: [ProjectAccessService, TemplatesService],
})
export class ProjectsModule {}
