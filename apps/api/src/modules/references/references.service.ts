import { Injectable } from '@nestjs/common';
import type {
  ExchangeRateDto,
  ReferenceItemDto,
  ReferencesDto,
  ServiceDto,
  StageDto,
  setExchangeRateSchema,
  updateStageSchema,
  upsertReferenceItemSchema,
  upsertServiceSchema,
} from '@fluggi/contracts';
import type { DealStage, LeadSource, LossReason, Service } from '@fluggi/db';
import type { z } from 'zod';
import type { AuthContext, RequestMeta } from '../../core/auth/auth-context';
import { AuditService, diffFields } from '../../core/audit/audit.service';
import { notFound } from '../../core/http/app.exception';
import { dec } from '../../core/http/serialize';
import { PrismaService } from '../../core/prisma/prisma.service';

export const toServiceDto = (s: Service): ServiceDto => ({
  id: s.id,
  code: s.code,
  name: s.nameRu,
  nameUz: s.nameUz,
  nameEn: s.nameEn,
  description: s.description,
  basePrice: dec(s.basePrice),
  minPrice: dec(s.minPrice),
  currency: s.currency,
  pricingType: s.pricingType,
  isActive: s.isActive,
  sort: s.sort,
});

const toItem = (r: LeadSource | LossReason): ReferenceItemDto => ({
  id: r.id,
  code: r.code,
  name: r.nameRu,
  nameUz: r.nameUz,
  nameEn: r.nameEn,
  isActive: r.isActive,
  sort: r.sort,
  ...('requiresComment' in r ? { requiresComment: r.requiresComment } : {}),
});

export const toStageDto = (s: DealStage): StageDto => ({
  id: s.id,
  code: s.code,
  entity: s.entity,
  name: s.nameRu,
  sort: s.sort,
  probability: s.probability,
  color: s.color,
});

type RefKind = 'sources' | 'loss-reasons';

@Injectable()
export class ReferencesService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly audit: AuditService,
  ) {}

  async all(): Promise<ReferencesDto> {
    const [services, sources, lossReasons, stages, usd] = await Promise.all([
      this.prisma.service.findMany({ orderBy: [{ sort: 'asc' }, { nameRu: 'asc' }] }),
      this.prisma.leadSource.findMany({ orderBy: [{ sort: 'asc' }, { nameRu: 'asc' }] }),
      this.prisma.lossReason.findMany({ orderBy: [{ sort: 'asc' }, { nameRu: 'asc' }] }),
      this.prisma.dealStage.findMany({ orderBy: { sort: 'asc' } }),
      this.prisma.exchangeRate.findFirst({
        where: { currency: 'USD' },
        orderBy: { date: 'desc' },
        include: { setBy: { select: { id: true, fullName: true } } },
      }),
    ]);
    return {
      services: services.map(toServiceDto),
      sources: sources.map(toItem),
      lossReasons: lossReasons.map(toItem),
      stages: stages.map(toStageDto),
      usdRate: usd ? this.toRateDto(usd) : null,
    };
  }

  private toRateDto(r: {
    id: string;
    date: Date;
    currency: 'UZS' | 'USD';
    rateToUzs: { toString(): string };
    setBy: { id: string; fullName: string } | null;
  }): ExchangeRateDto {
    return {
      id: r.id,
      date: r.date.toISOString().slice(0, 10),
      currency: r.currency,
      rateToUzs: r.rateToUzs.toString(),
      setBy: r.setBy ? { id: r.setBy.id, name: r.setBy.fullName } : null,
    };
  }

  async upsertService(
    auth: AuthContext,
    id: string | null,
    input: z.output<typeof upsertServiceSchema>,
    meta: RequestMeta,
  ): Promise<ServiceDto> {
    const data = {
      code: input.code,
      nameRu: input.name,
      nameUz: input.nameUz ?? null,
      nameEn: input.nameEn ?? null,
      description: input.description ?? null,
      basePrice: input.basePrice ?? null,
      minPrice: input.minPrice ?? null,
      currency: input.currency,
      pricingType: input.pricingType,
      isActive: input.isActive,
      sort: input.sort,
    };
    return this.prisma.$transaction(async (tx) => {
      const before = id ? await tx.service.findUnique({ where: { id } }) : null;
      if (id && !before) throw notFound('Услуга');
      const saved = id
        ? await tx.service.update({ where: { id }, data })
        : await tx.service.create({ data });
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: id ? 'service.update' : 'service.create',
        entityType: 'service',
        entityId: saved.id,
        changes: before
          ? diffFields({ ...toServiceDto(before) }, { ...toServiceDto(saved) }, [
              'name',
              'basePrice',
              'minPrice',
              'currency',
              'pricingType',
              'isActive',
            ])
          : { name: { old: null, new: saved.nameRu } },
        meta,
      });
      return toServiceDto(saved);
    });
  }

  async upsertItem(
    auth: AuthContext,
    kind: RefKind,
    id: string | null,
    input: z.output<typeof upsertReferenceItemSchema>,
    meta: RequestMeta,
  ): Promise<ReferenceItemDto> {
    const data = {
      code: input.code,
      nameRu: input.name,
      nameUz: input.nameUz ?? null,
      nameEn: input.nameEn ?? null,
      isActive: input.isActive,
      sort: input.sort,
    };
    return this.prisma.$transaction(async (tx) => {
      let saved: LeadSource | LossReason;
      if (kind === 'sources') {
        if (id && !(await tx.leadSource.findUnique({ where: { id } }))) throw notFound();
        saved = id
          ? await tx.leadSource.update({ where: { id }, data })
          : await tx.leadSource.create({ data });
      } else {
        const full = { ...data, requiresComment: input.requiresComment ?? false };
        if (id && !(await tx.lossReason.findUnique({ where: { id } }))) throw notFound();
        saved = id
          ? await tx.lossReason.update({ where: { id }, data: full })
          : await tx.lossReason.create({ data: full });
      }
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: `${kind}.${id ? 'update' : 'create'}`,
        entityType: kind,
        entityId: saved.id,
        changes: {
          name: { old: null, new: saved.nameRu },
          isActive: { old: null, new: saved.isActive },
        },
        meta,
      });
      return toItem(saved);
    });
  }

  async updateStage(
    auth: AuthContext,
    id: string,
    input: z.output<typeof updateStageSchema>,
    meta: RequestMeta,
  ): Promise<StageDto> {
    return this.prisma.$transaction(async (tx) => {
      const before = await tx.dealStage.findUnique({ where: { id } });
      if (!before) throw notFound('Этап');
      const saved = await tx.dealStage.update({
        where: { id },
        data: { nameRu: input.name, probability: input.probability, color: input.color },
      });
      const changes = diffFields({ ...toStageDto(before) }, { ...toStageDto(saved) }, [
        'name',
        'probability',
        'color',
      ]);
      if (changes) {
        await this.audit.log(tx, {
          actorId: auth.userId,
          action: 'stage.update',
          entityType: 'stage',
          entityId: id,
          changes,
          meta,
        });
      }
      return toStageDto(saved);
    });
  }

  async setRate(
    auth: AuthContext,
    input: z.output<typeof setExchangeRateSchema>,
    meta: RequestMeta,
  ): Promise<ExchangeRateDto> {
    const date = new Date(`${input.date ?? new Date().toISOString().slice(0, 10)}T00:00:00Z`);
    return this.prisma.$transaction(async (tx) => {
      const before = await tx.exchangeRate.findUnique({
        where: { date_currency: { date, currency: input.currency } },
      });
      const saved = await tx.exchangeRate.upsert({
        where: { date_currency: { date, currency: input.currency } },
        update: { rateToUzs: input.rateToUzs, setById: auth.userId },
        create: {
          date,
          currency: input.currency,
          rateToUzs: input.rateToUzs,
          setById: auth.userId,
        },
        include: { setBy: { select: { id: true, fullName: true } } },
      });
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: 'exchange_rate.set',
        entityType: 'exchange_rate',
        entityId: saved.id,
        changes: {
          [`${input.currency} ${date.toISOString().slice(0, 10)}`]: {
            old: before?.rateToUzs.toString() ?? null,
            new: saved.rateToUzs.toString(),
          },
        },
        meta,
      });
      return this.toRateDto(saved);
    });
  }
}
