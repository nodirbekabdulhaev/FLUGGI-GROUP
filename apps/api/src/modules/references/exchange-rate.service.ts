import { Injectable } from '@nestjs/common';
import { toUzs } from '@fluggi/domain';
import type { Currency } from '@fluggi/db';
import { businessRule } from '../../core/http/app.exception';
import { PrismaService, type Tx } from '../../core/prisma/prisma.service';

export interface Converted {
  rate: string;
  amountUzs: string;
}

@Injectable()
export class ExchangeRateService {
  constructor(private readonly prisma: PrismaService) {}

  /** Действующий курс на дату (последний установленный не позже даты). */
  async rateFor(currency: Currency, at: Date = new Date(), tx: Tx = this.prisma): Promise<string> {
    if (currency === 'UZS') return '1';
    const rate = await tx.exchangeRate.findFirst({
      where: { currency, date: { lte: at } },
      orderBy: { date: 'desc' },
    });
    if (!rate) {
      throw businessRule('Не задан курс USD. CEO может указать его в «Настройки → Справочники»', [
        { path: 'currency', message: 'Курс USD не задан' },
      ]);
    }
    return rate.rateToUzs.toString();
  }

  async convert(amount: string, currency: Currency, tx: Tx = this.prisma): Promise<Converted> {
    const rate = await this.rateFor(currency, new Date(), tx);
    return { rate, amountUzs: toUzs(amount, currency, rate).toFixed(2) };
  }
}
