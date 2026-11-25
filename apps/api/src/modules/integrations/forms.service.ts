import { randomBytes } from 'node:crypto';
import { Injectable } from '@nestjs/common';
import {
  formatNumber,
  type FormField,
  type FormSubmissionDto,
  type formSubmitSchema,
  type LeadFormDto,
  type leadFormSchema,
  type PublicFormDto,
} from '@fluggi/contracts';
import type { LeadForm, Prisma } from '@fluggi/db';
import type { z } from 'zod';
import type { AuthContext, RequestMeta } from '../../core/auth/auth-context';
import { AuditService } from '../../core/audit/audit.service';
import { businessRule, notFound } from '../../core/http/app.exception';
import { PrismaService } from '../../core/prisma/prisma.service';
import { IntakeService } from './intake.service';

type FormInput = z.output<typeof leadFormSchema>;
type SubmitInput = z.output<typeof formSubmitSchema>;

/** Слишком быстрая отправка после показа формы — признак бота. */
const MIN_FILL_MS = 1500;

/** Ключи полей, которые ложатся в поля лида. */
const LEAD_KEYS = ['name', 'phone', 'email', 'company', 'message', 'instagram'] as const;

/**
 * Формы для сайта: CEO собирает форму в CRM, вставляет код на сайт (WordPress),
 * заявки становятся лидами (источник «Сайт»), повторные — отмечаются в найденном лиде.
 */
@Injectable()
export class FormsService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly audit: AuditService,
    private readonly intake: IntakeService,
  ) {}

  private async toDto(f: LeadForm & { owner: { id: string; fullName: string } | null }) {
    const [submissions, leads, last] = await Promise.all([
      this.prisma.formSubmission.count({ where: { formId: f.id } }),
      this.prisma.formSubmission.count({ where: { formId: f.id, result: 'LEAD_CREATED' } }),
      this.prisma.formSubmission.findFirst({
        where: { formId: f.id },
        orderBy: { createdAt: 'desc' },
        select: { createdAt: true },
      }),
    ]);
    return {
      id: f.id,
      key: f.key,
      name: f.name,
      title: f.title,
      description: f.description,
      buttonText: f.buttonText,
      successMessage: f.successMessage,
      fields: f.fields as unknown as FormField[],
      serviceId: f.serviceId,
      sourceId: f.sourceId,
      owner: f.owner ? { id: f.owner.id, name: f.owner.fullName } : null,
      teamId: f.teamId,
      isActive: f.isActive,
      submissions,
      leads,
      lastSubmissionAt: last?.createdAt.toISOString() ?? null,
      createdAt: f.createdAt.toISOString(),
    } satisfies LeadFormDto;
  }

  private readonly include = { owner: { select: { id: true, fullName: true } } } as const;

  async list(): Promise<LeadFormDto[]> {
    const rows = await this.prisma.leadForm.findMany({
      where: { deletedAt: null },
      include: this.include,
      orderBy: { createdAt: 'asc' },
    });
    return Promise.all(rows.map((r) => this.toDto(r)));
  }

  async save(
    auth: AuthContext,
    id: string | null,
    input: FormInput,
    meta: RequestMeta,
  ): Promise<LeadFormDto> {
    const data = {
      name: input.name,
      title: input.title,
      description: input.description ?? null,
      buttonText: input.buttonText,
      successMessage: input.successMessage,
      fields: input.fields as unknown as Prisma.InputJsonValue,
      serviceId: input.serviceId ?? null,
      sourceId: input.sourceId ?? null,
      ownerId: input.ownerId ?? null,
      teamId: input.teamId ?? null,
      isActive: input.isActive,
    };
    if (id && !(await this.prisma.leadForm.findFirst({ where: { id, deletedAt: null } })))
      throw notFound('Форма');
    const row = await this.prisma.$transaction(async (tx) => {
      const f = id
        ? await tx.leadForm.update({ where: { id }, data, include: this.include })
        : await tx.leadForm.create({
            data: { ...data, key: randomBytes(9).toString('base64url') },
            include: this.include,
          });
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: id ? 'lead_form.update' : 'lead_form.create',
        entityType: 'lead_form',
        entityId: f.id,
        changes: { name: { old: null, new: f.name }, isActive: { old: null, new: f.isActive } },
        meta,
      });
      return f;
    });
    return this.toDto(row);
  }

  async remove(auth: AuthContext, id: string, meta: RequestMeta) {
    const f = await this.prisma.leadForm.findFirst({ where: { id, deletedAt: null } });
    if (!f) throw notFound('Форма');
    await this.prisma.$transaction(async (tx) => {
      await tx.leadForm.update({ where: { id }, data: { deletedAt: new Date(), isActive: false } });
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: 'lead_form.delete',
        entityType: 'lead_form',
        entityId: id,
        changes: { name: { old: f.name, new: null } },
        meta,
      });
    });
  }

  async submissions(id: string): Promise<FormSubmissionDto[]> {
    const rows = await this.prisma.formSubmission.findMany({
      where: { formId: id },
      include: { lead: { select: { id: true, number: true, title: true } } },
      orderBy: { createdAt: 'desc' },
      take: 100,
    });
    return rows.map((r) => ({
      id: r.id,
      data: r.data as Record<string, string>,
      utm: (r.utm as Record<string, string> | null) ?? null,
      page: r.page,
      result: r.result,
      lead: r.lead
        ? { id: r.lead.id, number: formatNumber('L', r.lead.number), name: r.lead.title }
        : null,
      createdAt: r.createdAt.toISOString(),
    }));
  }

  // ─────────────── Публичная часть (без входа) ───────────────

  private async active(key: string) {
    const f = await this.prisma.leadForm.findFirst({
      where: { key, isActive: true, deletedAt: null },
    });
    if (!f) throw notFound('Форма');
    return f;
  }

  async publicForm(key: string): Promise<PublicFormDto> {
    const f = await this.active(key);
    return {
      key: f.key,
      title: f.title,
      description: f.description,
      buttonText: f.buttonText,
      successMessage: f.successMessage,
      fields: f.fields as unknown as FormField[],
    };
  }

  /** Заявка с сайта. Боты получают «успех», но лид не создаётся. */
  async submit(
    key: string,
    input: SubmitInput,
    ip: string | null,
  ): Promise<{ ok: true; message: string }> {
    const form = await this.active(key);
    const fields = form.fields as unknown as FormField[];
    const data: Record<string, string> = {};
    for (const f of fields) {
      const v = (input.data[f.key] ?? '').trim();
      if (f.required && !v)
        throw businessRule('Заполните обязательные поля', [
          { path: `data.${f.key}`, message: `Заполните «${f.label}»` },
        ]);
      if (v && f.type === 'phone' && (v.replace(/\D/g, '').length < 7 || v.length > 30))
        throw businessRule('Проверьте телефон', [
          { path: `data.${f.key}`, message: 'Похоже, номер указан с ошибкой' },
        ]);
      if (v && f.type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v))
        throw businessRule('Проверьте email', [
          { path: `data.${f.key}`, message: 'Похоже, email указан с ошибкой' },
        ]);
      if (v && f.type === 'select' && f.options?.length && !f.options.includes(v))
        throw businessRule('Выберите вариант из списка', [
          { path: `data.${f.key}`, message: 'Выберите вариант из списка' },
        ]);
      if (v) data[f.key] = v.slice(0, 4000);
    }
    const utm = input.utm && Object.keys(input.utm).length ? input.utm : null;
    const record = (result: 'LEAD_CREATED' | 'DUPLICATE' | 'SPAM', leadId: string | null) =>
      this.prisma.formSubmission.create({
        data: {
          formId: form.id,
          leadId,
          data: data as Prisma.InputJsonValue,
          utm: (utm ?? undefined) as Prisma.InputJsonValue | undefined,
          page: input.page?.slice(0, 1000) ?? null,
          ip,
          result,
        },
      });

    const tooFast = input.renderedAt !== undefined && Date.now() - input.renderedAt < MIN_FILL_MS;
    if (input.website || tooFast) {
      await record('SPAM', null);
      return { ok: true, message: form.successMessage };
    }

    const by = (type: FormField['type'], k: string) =>
      data[k] ?? data[fields.find((f) => f.type === type)?.key ?? ''] ?? null;
    const name = data.name ?? null;
    const company = data.company ?? null;
    const extra = fields
      .filter((f) => !LEAD_KEYS.includes(f.key as (typeof LEAD_KEYS)[number]) && data[f.key])
      .filter((f) => f.type !== 'phone' && f.type !== 'email')
      .map((f) => `${f.label}: ${data[f.key]}`);
    const utmLine = utm
      ? Object.entries(utm)
          .map(([k, v]) => `${k}=${v}`)
          .join(', ')
      : null;
    const comment = [
      data.message,
      ...extra,
      `Форма: ${form.name}`,
      input.page ? `Страница: ${input.page.slice(0, 300)}` : null,
      utmLine ? `UTM: ${utmLine}` : null,
    ]
      .filter(Boolean)
      .join('\n');

    const res = await this.intake.intake({
      title: `${company ?? name ?? 'Заявка с сайта'} — ${form.name}`,
      contactName: name,
      companyName: company,
      phone: by('phone', 'phone'),
      email: by('email', 'email'),
      instagram: data.instagram ?? null,
      comment,
      sourceCode: 'WEBSITE',
      sourceId: form.sourceId,
      serviceId: form.serviceId,
      ownerId: form.ownerId,
      teamId: form.teamId,
      channel: `Форма «${form.name}»`,
    });
    await record(res.duplicate ? 'DUPLICATE' : 'LEAD_CREATED', res.leadId);
    return { ok: true, message: form.successMessage };
  }
}
