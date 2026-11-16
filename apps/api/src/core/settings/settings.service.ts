import { Global, Injectable, Module } from '@nestjs/common';
import {
  automationSettingsSchema,
  companySettingsSchema,
  DEFAULT_AUTOMATION_SETTINGS,
  DEFAULT_COMPANY_SETTINGS,
  type AutomationSettings,
  type CompanySettings,
} from '@fluggi/contracts';
import type { Prisma } from '@fluggi/db';
import { PrismaService } from '../prisma/prisma.service';

const KEY = 'automation';
const COMPANY_KEY = 'company';
const TTL_MS = 30_000;

/** Системные настройки (таблица settings) с кэшем на 30 секунд. */
@Injectable()
export class SettingsService {
  private cache: { at: number; value: AutomationSettings } | null = null;

  constructor(private readonly prisma: PrismaService) {}

  async automation(): Promise<AutomationSettings> {
    if (this.cache && Date.now() - this.cache.at < TTL_MS) return this.cache.value;
    const row = await this.prisma.setting.findUnique({ where: { key: KEY } });
    const parsed = automationSettingsSchema.safeParse({
      ...DEFAULT_AUTOMATION_SETTINGS,
      ...((row?.value as object | null) ?? {}),
    });
    const value = parsed.success ? parsed.data : DEFAULT_AUTOMATION_SETTINGS;
    this.cache = { at: Date.now(), value };
    return value;
  }

  async saveAutomation(value: AutomationSettings, userId: string): Promise<AutomationSettings> {
    await this.prisma.setting.upsert({
      where: { key: KEY },
      update: { value: value as unknown as Prisma.InputJsonValue, updatedById: userId },
      create: { key: KEY, value: value as unknown as Prisma.InputJsonValue, updatedById: userId },
    });
    this.cache = null;
    return this.automation();
  }

  /** Реквизиты компании для пакета бухгалтеру. */
  async company(): Promise<CompanySettings> {
    const row = await this.prisma.setting.findUnique({ where: { key: COMPANY_KEY } });
    const parsed = companySettingsSchema.safeParse({
      ...DEFAULT_COMPANY_SETTINGS,
      ...((row?.value as object | null) ?? {}),
    });
    return parsed.success ? parsed.data : DEFAULT_COMPANY_SETTINGS;
  }

  async saveCompany(value: CompanySettings, userId: string): Promise<CompanySettings> {
    await this.prisma.setting.upsert({
      where: { key: COMPANY_KEY },
      update: { value: value as unknown as Prisma.InputJsonValue, updatedById: userId },
      create: {
        key: COMPANY_KEY,
        value: value as unknown as Prisma.InputJsonValue,
        updatedById: userId,
      },
    });
    return this.company();
  }

  /** Сбросить кэш (тесты, ручные изменения в БД). */
  invalidate() {
    this.cache = null;
  }
}

@Global()
@Module({ providers: [SettingsService], exports: [SettingsService] })
export class SettingsModule {}
