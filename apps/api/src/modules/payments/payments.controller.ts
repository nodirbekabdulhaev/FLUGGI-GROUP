import { Body, Controller, Get, HttpCode, Param, Post, Query } from '@nestjs/common';
import {
  confirmPaymentSchema,
  createPaymentSchema,
  paymentListQuerySchema,
  refundPaymentSchema,
  type ConfirmPaymentResult,
  type DealMoneyDto,
  type Paginated,
  type PaymentDto,
} from '@fluggi/contracts';
import type { z } from 'zod';
import type { AuthContext, RequestMeta } from '../../core/auth/auth-context';
import { CurrentUser, ReqMeta, RequirePermission } from '../../core/auth/decorators';
import { UuidPipe } from '../../core/http/uuid.pipe';
import { zod } from '../../core/http/zod.pipe';
import { PaymentsService } from './payments.service';

@Controller()
export class PaymentsController {
  constructor(private readonly payments: PaymentsService) {}

  @Get('payments')
  @RequirePermission('payment.read')
  list(
    @CurrentUser() auth: AuthContext,
    @Query(zod(paymentListQuerySchema)) q: z.output<typeof paymentListQuerySchema>,
  ): Promise<Paginated<PaymentDto>> {
    return this.payments.list(auth, q);
  }

  @Get('payments/:id')
  @RequirePermission('payment.read')
  get(@CurrentUser() auth: AuthContext, @Param('id', UuidPipe) id: string): Promise<PaymentDto> {
    return this.payments.get(auth, id);
  }

  @Get('deals/:id/money')
  @RequirePermission('payment.read')
  money(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
  ): Promise<DealMoneyDto> {
    return this.payments.dealMoney(auth, id);
  }

  @Post('payments')
  @RequirePermission('payment.create')
  create(
    @CurrentUser() auth: AuthContext,
    @Body(zod(createPaymentSchema)) body: z.output<typeof createPaymentSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<PaymentDto> {
    return this.payments.create(auth, body, meta);
  }

  @Post('payments/:id/confirm')
  @HttpCode(200)
  @RequirePermission('payment.confirm')
  confirm(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Body(zod(confirmPaymentSchema)) body: z.output<typeof confirmPaymentSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<ConfirmPaymentResult> {
    return this.payments.confirm(auth, id, body.paidAt ? new Date(body.paidAt) : undefined, meta);
  }

  @Post('payments/:id/refund')
  @RequirePermission('payment.refund')
  refund(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Body(zod(refundPaymentSchema)) body: z.output<typeof refundPaymentSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<PaymentDto> {
    return this.payments.refund(auth, id, body, meta);
  }

  @Post('payments/:id/cancel')
  @HttpCode(200)
  @RequirePermission('payment.create')
  cancel(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @ReqMeta() meta: RequestMeta,
  ): Promise<PaymentDto> {
    return this.payments.cancel(auth, id, meta);
  }
}
