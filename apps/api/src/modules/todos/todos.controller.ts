import {
  Body,
  Controller,
  Delete,
  Get,
  HttpCode,
  Param,
  Patch,
  Post,
  Put,
  Query,
} from '@nestjs/common';
import {
  createTodoSchema,
  recurringTodoSchema,
  todoListQuerySchema,
  updateTodoSchema,
  type Paginated,
  type RecurringTodoDto,
  type TodoDockDto,
  type TodoDto,
} from '@fluggi/contracts';
import type { z } from 'zod';
import type { AuthContext } from '../../core/auth/auth-context';
import { AuthenticatedOnly, CurrentUser } from '../../core/auth/decorators';
import { UuidPipe } from '../../core/http/uuid.pipe';
import { zod } from '../../core/http/zod.pipe';
import { RecurringTodosService } from './recurring.service';
import { TodosService } from './todos.service';

/**
 * Личные дела и регулярные дела. Доступны каждому сотруднику для себя;
 * поручить другому — по праву task.create (проверка в сервисе).
 */
@Controller()
export class TodosController {
  constructor(
    private readonly todos: TodosService,
    private readonly recurring: RecurringTodosService,
  ) {}

  @Get('todos')
  @AuthenticatedOnly()
  list(
    @CurrentUser() auth: AuthContext,
    @Query(zod(todoListQuerySchema)) q: z.output<typeof todoListQuerySchema>,
  ): Promise<Paginated<TodoDto>> {
    return this.todos.list(auth, q);
  }

  @Get('todos/dock')
  @AuthenticatedOnly()
  dock(@CurrentUser() auth: AuthContext): Promise<TodoDockDto> {
    return this.todos.dock(auth);
  }

  @Post('todos')
  @AuthenticatedOnly()
  create(
    @CurrentUser() auth: AuthContext,
    @Body(zod(createTodoSchema)) body: z.output<typeof createTodoSchema>,
  ): Promise<TodoDto> {
    return this.todos.create(auth, body);
  }

  @Patch('todos/:id')
  @AuthenticatedOnly()
  update(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Body(zod(updateTodoSchema)) body: z.output<typeof updateTodoSchema>,
  ): Promise<TodoDto> {
    return this.todos.update(auth, id, body);
  }

  @Post('todos/:id/complete')
  @HttpCode(200)
  @AuthenticatedOnly()
  complete(@CurrentUser() auth: AuthContext, @Param('id', UuidPipe) id: string): Promise<TodoDto> {
    return this.todos.setStatus(auth, id, 'DONE');
  }

  @Post('todos/:id/reopen')
  @HttpCode(200)
  @AuthenticatedOnly()
  reopen(@CurrentUser() auth: AuthContext, @Param('id', UuidPipe) id: string): Promise<TodoDto> {
    return this.todos.setStatus(auth, id, 'OPEN');
  }

  @Delete('todos/:id')
  @HttpCode(204)
  @AuthenticatedOnly()
  remove(@CurrentUser() auth: AuthContext, @Param('id', UuidPipe) id: string): Promise<void> {
    return this.todos.remove(auth, id);
  }

  @Get('recurring-todos')
  @AuthenticatedOnly()
  recurringList(@CurrentUser() auth: AuthContext): Promise<RecurringTodoDto[]> {
    return this.recurring.list(auth);
  }

  @Post('recurring-todos')
  @AuthenticatedOnly()
  recurringCreate(
    @CurrentUser() auth: AuthContext,
    @Body(zod(recurringTodoSchema)) body: z.output<typeof recurringTodoSchema>,
  ): Promise<RecurringTodoDto> {
    return this.recurring.create(auth, body);
  }

  @Post('recurring-todos/tax-calendar')
  @HttpCode(200)
  @AuthenticatedOnly()
  taxCalendar(@CurrentUser() auth: AuthContext): Promise<RecurringTodoDto[]> {
    return this.recurring.addTaxCalendar(auth);
  }

  @Put('recurring-todos/:id')
  @AuthenticatedOnly()
  recurringUpdate(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Body(zod(recurringTodoSchema)) body: z.output<typeof recurringTodoSchema>,
  ): Promise<RecurringTodoDto> {
    return this.recurring.update(auth, id, body);
  }

  @Delete('recurring-todos/:id')
  @HttpCode(204)
  @AuthenticatedOnly()
  recurringRemove(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
  ): Promise<void> {
    return this.recurring.remove(auth, id);
  }
}
