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
  employeeRatesSchema,
  financeCategorySchema,
  financeSettingsSchema,
  otherIncomeListQuerySchema,
  otherIncomeSchema,
  tariffSchema,
  updateCostLineSchema,
  workItemSchema,
  type EmployeeRateDto,
  type FinanceCategoryDto,
  type FinanceSettings,
  type OtherIncomeDto,
  type Paginated,
  type ProjectCostLineDto,
  type TariffDto,
  type WorkItemDto,
} from '@fluggi/contracts';
import { z } from 'zod';
import type { AuthContext, RequestMeta } from '../../core/auth/auth-context';
import {
  AuthenticatedOnly,
  CurrentUser,
  ReqMeta,
  RequirePermission,
} from '../../core/auth/decorators';
import { UuidPipe } from '../../core/http/uuid.pipe';
import { zod } from '../../core/http/zod.pipe';
import { CostLinesService } from '../finance/cost-lines.service';
import { OverheadService } from '../finance/overhead.service';
import { CatalogService } from './catalog.service';

const kindQuery = z.object({ kind: z.enum(['EXPENSE', 'INCOME']).optional() });
const tariffQuery = z.object({
  serviceId: z.uuid().optional(),
  all: z
    .enum(['true', 'false'])
    .optional()
    .transform((v) => v === 'true'),
});

/** Справочники финансов, тарифы и себестоимость (ТЗ: тарификация, ценообразование). */
@Controller()
export class CatalogController {
  constructor(
    private readonly catalog: CatalogService,
    private readonly overhead: OverheadService,
    private readonly costLines: CostLinesService,
  ) {}

  // ── Категории доходов и расходов
  @Get('finance-categories')
  @AuthenticatedOnly()
  categories(@Query(zod(kindQuery)) q: z.output<typeof kindQuery>): Promise<FinanceCategoryDto[]> {
    return this.catalog.categories(q.kind);
  }

  @Post('finance-categories')
  @RequirePermission('reference.manage', 'ALL')
  createCategory(
    @CurrentUser() auth: AuthContext,
    @Body(zod(financeCategorySchema)) body: z.output<typeof financeCategorySchema>,
    @ReqMeta() meta: RequestMeta,
  ) {
    return this.catalog.createCategory(auth, body, meta);
  }

  @Put('finance-categories/:id')
  @RequirePermission('reference.manage', 'ALL')
  updateCategory(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Body(zod(financeCategorySchema)) body: z.output<typeof financeCategorySchema>,
    @ReqMeta() meta: RequestMeta,
  ) {
    return this.catalog.updateCategory(auth, id, body, meta);
  }

  @Delete('finance-categories/:id')
  @HttpCode(204)
  @RequirePermission('reference.manage', 'ALL')
  deleteCategory(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @ReqMeta() meta: RequestMeta,
  ) {
    return this.catalog.deleteCategory(auth, id, meta);
  }

  // ── Прочие поступления (CEO)
  @Get('other-incomes')
  @RequirePermission('finance.company.read', 'ALL')
  incomes(
    @Query(zod(otherIncomeListQuerySchema)) q: z.output<typeof otherIncomeListQuerySchema>,
  ): Promise<Paginated<OtherIncomeDto>> {
    return this.catalog.incomes(q);
  }

  @Post('other-incomes')
  @RequirePermission('finance.company.read', 'ALL')
  createIncome(
    @CurrentUser() auth: AuthContext,
    @Body(zod(otherIncomeSchema)) body: z.output<typeof otherIncomeSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<OtherIncomeDto> {
    return this.catalog.saveIncome(auth, null, body, meta);
  }

  @Put('other-incomes/:id')
  @RequirePermission('finance.company.read', 'ALL')
  updateIncome(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Body(zod(otherIncomeSchema)) body: z.output<typeof otherIncomeSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<OtherIncomeDto> {
    return this.catalog.saveIncome(auth, id, body, meta);
  }

  @Delete('other-incomes/:id')
  @HttpCode(204)
  @RequirePermission('finance.company.read', 'ALL')
  deleteIncome(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @ReqMeta() meta: RequestMeta,
  ) {
    return this.catalog.deleteIncome(auth, id, meta);
  }

  // ── Финансовые настройки (делитель накладных)
  @Get('settings/finance')
  @RequirePermission('finance.company.read', 'ALL')
  financeSettings(): Promise<FinanceSettings> {
    return this.overhead.settings();
  }

  @Put('settings/finance')
  @RequirePermission('finance.company.read', 'ALL')
  saveFinanceSettings(
    @CurrentUser() auth: AuthContext,
    @Body(zod(financeSettingsSchema)) body: FinanceSettings,
  ): Promise<FinanceSettings> {
    return this.overhead.saveSettings(body, auth.userId);
  }

  // ── Единицы работ
  @Get('work-items')
  @AuthenticatedOnly()
  workItems(): Promise<WorkItemDto[]> {
    return this.catalog.workItems();
  }

  @Post('work-items')
  @RequirePermission('reference.manage', 'ALL')
  createWorkItem(
    @CurrentUser() auth: AuthContext,
    @Body(zod(workItemSchema)) body: z.output<typeof workItemSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<WorkItemDto> {
    return this.catalog.saveWorkItem(auth, null, body, meta);
  }

  @Put('work-items/:id')
  @RequirePermission('reference.manage', 'ALL')
  updateWorkItem(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Body(zod(workItemSchema)) body: z.output<typeof workItemSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<WorkItemDto> {
    return this.catalog.saveWorkItem(auth, id, body, meta);
  }

  // ── Личные ставки сотрудника (карточка сотрудника)
  @Get('users/:id/rates')
  @AuthenticatedOnly()
  rates(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
  ): Promise<EmployeeRateDto[]> {
    return this.catalog.employeeRates(auth, id);
  }

  @Put('users/:id/rates')
  @RequirePermission('payroll.manage', 'ALL')
  saveRates(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Body(zod(employeeRatesSchema)) body: z.output<typeof employeeRatesSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<EmployeeRateDto[]> {
    return this.catalog.saveEmployeeRates(auth, id, body, meta);
  }

  // ── Тарифы
  @Get('tariffs')
  @AuthenticatedOnly()
  tariffs(
    @CurrentUser() auth: AuthContext,
    @Query(zod(tariffQuery)) q: z.output<typeof tariffQuery>,
  ): Promise<TariffDto[]> {
    return this.catalog.tariffs(auth, q);
  }

  @Post('tariffs')
  @RequirePermission('reference.manage', 'ALL')
  createTariff(
    @CurrentUser() auth: AuthContext,
    @Body(zod(tariffSchema)) body: z.output<typeof tariffSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<TariffDto> {
    return this.catalog.saveTariff(auth, null, body, meta);
  }

  @Put('tariffs/:id')
  @RequirePermission('reference.manage', 'ALL')
  updateTariff(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Body(zod(tariffSchema)) body: z.output<typeof tariffSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<TariffDto> {
    return this.catalog.saveTariff(auth, id, body, meta);
  }

  // ── Себестоимость проекта по тарифу
  @Get('projects/:id/cost-lines')
  @AuthenticatedOnly()
  costLinesList(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
  ): Promise<ProjectCostLineDto[]> {
    return this.costLines.list(auth, id);
  }

  @Patch('cost-lines/:id')
  @AuthenticatedOnly()
  costLineUpdate(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Body(zod(updateCostLineSchema)) body: z.output<typeof updateCostLineSchema>,
  ): Promise<ProjectCostLineDto> {
    return this.costLines.update(auth, id, body);
  }

  @Post('cost-lines/:id/accrue')
  @HttpCode(200)
  @AuthenticatedOnly()
  costLineAccrue(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @ReqMeta() meta: RequestMeta,
  ): Promise<ProjectCostLineDto> {
    return this.costLines.accrue(auth, id, meta);
  }

  @Post('cost-lines/:id/cancel')
  @HttpCode(204)
  @AuthenticatedOnly()
  costLineCancel(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
  ): Promise<void> {
    return this.costLines.cancel(auth, id);
  }
}
