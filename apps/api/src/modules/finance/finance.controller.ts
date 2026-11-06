import { Body, Controller, Delete, Get, HttpCode, Param, Patch, Post, Query } from '@nestjs/common';
import {
  createExpenseSchema,
  expenseListQuerySchema,
  periodQuerySchema,
  projectProfitQuerySchema,
  updateExpenseSchema,
  type ExpenseDto,
  type FinanceSummaryDto,
  type Paginated,
  type ProjectFinanceDto,
  type ProjectProfitDto,
} from '@fluggi/contracts';
import type { z } from 'zod';
import type { AuthContext, RequestMeta } from '../../core/auth/auth-context';
import { CurrentUser, ReqMeta, RequirePermission } from '../../core/auth/decorators';
import { UuidPipe } from '../../core/http/uuid.pipe';
import { zod } from '../../core/http/zod.pipe';
import { ExpensesService } from './expenses.service';
import { FinanceService } from './finance.service';

/** Финансы (ТЗ §25–27): расходы, карточка проекта, дашборд, прибыльность проектов. */
@Controller()
export class FinanceController {
  constructor(
    private readonly expenses: ExpensesService,
    private readonly finance: FinanceService,
  ) {}

  @Get('expenses')
  @RequirePermission('finance.read')
  list(
    @CurrentUser() auth: AuthContext,
    @Query(zod(expenseListQuerySchema)) q: z.output<typeof expenseListQuerySchema>,
  ): Promise<Paginated<ExpenseDto> & { totalUzs: string }> {
    return this.expenses.list(auth, q);
  }

  @Post('expenses')
  @RequirePermission('expense.create')
  create(
    @CurrentUser() auth: AuthContext,
    @Body(zod(createExpenseSchema)) body: z.output<typeof createExpenseSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<ExpenseDto> {
    return this.expenses.create(auth, body, meta);
  }

  @Patch('expenses/:id')
  @RequirePermission('expense.update')
  update(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Body(zod(updateExpenseSchema)) body: z.output<typeof updateExpenseSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<ExpenseDto> {
    return this.expenses.update(auth, id, body, meta);
  }

  @Delete('expenses/:id')
  @HttpCode(204)
  @RequirePermission('expense.update')
  remove(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @ReqMeta() meta: RequestMeta,
  ): Promise<void> {
    return this.expenses.remove(auth, id, meta);
  }

  @Get('finance/summary')
  @RequirePermission('finance.read')
  summary(
    @CurrentUser() auth: AuthContext,
    @Query(zod(periodQuerySchema)) q: z.output<typeof periodQuerySchema>,
  ): Promise<FinanceSummaryDto> {
    return this.finance.summary(auth, q);
  }

  @Get('finance/projects')
  @RequirePermission('finance.read')
  projects(
    @CurrentUser() auth: AuthContext,
    @Query(zod(projectProfitQuerySchema)) q: z.output<typeof projectProfitQuerySchema>,
  ): Promise<Paginated<ProjectProfitDto>> {
    return this.finance.projectsProfit(auth, q);
  }

  @Get('projects/:id/finance')
  @RequirePermission('finance.read')
  project(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
  ): Promise<ProjectFinanceDto> {
    return this.finance.project(auth, id);
  }
}
