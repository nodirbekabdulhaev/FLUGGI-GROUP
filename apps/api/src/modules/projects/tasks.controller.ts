import { Body, Controller, Delete, Get, HttpCode, Param, Patch, Post, Query } from '@nestjs/common';
import {
  createTaskSchema,
  moveTaskSchema,
  taskCommentSchema,
  taskListQuerySchema,
  updateTaskSchema,
  type Paginated,
  type TaskCommentDto,
  type TaskDetailDto,
  type TaskDto,
} from '@fluggi/contracts';
import type { z } from 'zod';
import type { AuthContext, RequestMeta } from '../../core/auth/auth-context';
import { CurrentUser, ReqMeta, RequirePermission } from '../../core/auth/decorators';
import { UuidPipe } from '../../core/http/uuid.pipe';
import { zod } from '../../core/http/zod.pipe';
import { TasksService } from './tasks.service';

/** Задачи (ТЗ §22–24). */
@Controller('tasks')
export class TasksController {
  constructor(private readonly tasks: TasksService) {}

  @Get()
  @RequirePermission('task.read')
  list(
    @CurrentUser() auth: AuthContext,
    @Query(zod(taskListQuerySchema)) q: z.output<typeof taskListQuerySchema>,
  ): Promise<Paginated<TaskDto>> {
    return this.tasks.list(auth, q);
  }

  @Get(':id')
  @RequirePermission('task.read')
  get(@CurrentUser() auth: AuthContext, @Param('id', UuidPipe) id: string): Promise<TaskDetailDto> {
    return this.tasks.get(auth, id);
  }

  @Post()
  @RequirePermission('task.create')
  create(
    @CurrentUser() auth: AuthContext,
    @Body(zod(createTaskSchema)) body: z.output<typeof createTaskSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<TaskDetailDto> {
    return this.tasks.create(auth, body, meta);
  }

  @Patch(':id')
  @RequirePermission('task.update')
  update(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Body(zod(updateTaskSchema)) body: z.output<typeof updateTaskSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<TaskDetailDto> {
    return this.tasks.update(auth, id, body, meta);
  }

  @Post(':id/move')
  @HttpCode(200)
  @RequirePermission('task.update')
  move(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Body(zod(moveTaskSchema)) body: z.output<typeof moveTaskSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<TaskDetailDto> {
    return this.tasks.move(auth, id, body, meta);
  }

  @Delete(':id')
  @HttpCode(204)
  @RequirePermission('task.update')
  remove(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @ReqMeta() meta: RequestMeta,
  ): Promise<void> {
    return this.tasks.remove(auth, id, meta);
  }

  @Post(':id/comments')
  @RequirePermission('task.read')
  comment(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Body(zod(taskCommentSchema)) body: z.output<typeof taskCommentSchema>,
  ): Promise<TaskCommentDto> {
    return this.tasks.comment(auth, id, body.body);
  }
}
