import { Injectable } from '@nestjs/common';
import {
  clientRequisitesSchema,
  documentSettingsSchema,
  type ClientRequisites,
  type CompanySettings,
  type DocumentCheckDto,
  type DocumentSettings,
  type ProposalDto,
} from '@fluggi/contracts';
import type { Prisma } from '@fluggi/db';
import { amountInWords } from '@fluggi/domain';
import type { AuthContext, RequestMeta } from '../../core/auth/auth-context';
import { AuditService } from '../../core/audit/audit.service';
import { PrismaService } from '../../core/prisma/prisma.service';
import { SettingsService } from '../../core/settings/settings.service';
import { ContractsService } from '../contracts/contracts.service';
import { CrmAccessService } from '../crm/crm-access.service';
import { ProposalsService } from '../proposals/proposals.service';
import {
  amount,
  longDate,
  moneyText,
  shortDate,
  templateBlocks,
  type Block,
  type DocModel,
  type Party,
  type TableTotal,
} from './doc-model';

const KEY = 'documents';
const EMPTY_REQ: ClientRequisites = clientRequisitesSchema.parse({});

/** «Иванов Иван Иванович» → «Иванов И.И.» для подписи. */
export function shortName(full: string): string {
  const [last, ...rest] = full.trim().split(/\s+/);
  if (!last) return '';
  return [last, rest.map((p) => `${p[0]}.`).join('')].filter(Boolean).join(' ');
}

const join = (parts: (string | null | undefined)[], sep = ' · ') =>
  parts
    .map((p) => p?.trim())
    .filter(Boolean)
    .join(sep);

interface ItemLine {
  description: string;
  serviceName: string | null;
  tariffName: string | null;
  tariffDescription: string | null;
  quantity: string;
  unitPrice: string;
  discountPct: string;
  total: string;
}

@Injectable()
export class DocumentsService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly settings: SettingsService,
    private readonly audit: AuditService,
    private readonly proposals: ProposalsService,
    private readonly contracts: ContractsService,
    private readonly access: CrmAccessService,
  ) {}

  // ─────────────── Настройки документов ───────────────

  async documentSettings(): Promise<DocumentSettings> {
    const row = await this.prisma.setting.findUnique({ where: { key: KEY } });
    const parsed = documentSettingsSchema.safeParse((row?.value as object | null) ?? {});
    return parsed.success ? parsed.data : documentSettingsSchema.parse({});
  }

  async saveDocumentSettings(auth: AuthContext, value: DocumentSettings, meta: RequestMeta) {
    const json = value as unknown as Prisma.InputJsonValue;
    await this.prisma.$transaction(async (tx) => {
      await tx.setting.upsert({
        where: { key: KEY },
        update: { value: json, updatedById: auth.userId },
        create: { key: KEY, value: json, updatedById: auth.userId },
      });
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: 'settings.documents',
        entityType: 'setting',
        entityId: null,
        changes: { city: { old: null, new: value.city } },
        meta,
      });
    });
    return this.documentSettings();
  }

  // ─────────────── Реквизиты клиента ───────────────

  async saveClientRequisites(
    auth: AuthContext,
    clientId: string,
    input: ClientRequisites,
    meta: RequestMeta,
  ) {
    const before = await this.access.client(auth, clientId, 'client.update');
    await this.prisma.$transaction(async (tx) => {
      await tx.client.update({
        where: { id: clientId },
        data: { requisites: input as unknown as Prisma.InputJsonValue },
      });
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: 'client.requisites',
        entityType: 'client',
        entityId: clientId,
        changes: { requisites: { old: before.requisites ?? null, new: input } },
        meta,
      });
    });
    return input;
  }

  private async clientParty(clientId: string) {
    const c = await this.prisma.client.findUniqueOrThrow({ where: { id: clientId } });
    const parsed = clientRequisitesSchema.safeParse(c.requisites ?? {});
    const req = parsed.success ? parsed.data : EMPTY_REQ;
    return { client: c, req };
  }

  // ─────────────── Общие части ───────────────

  private brand(co: CompanySettings) {
    return {
      name: co.name || co.legalName || 'Fluggi',
      contacts: join([co.phone, co.email, co.website, co.address]),
    };
  }

  private companyParty(co: CompanySettings): Party {
    return {
      role: 'Исполнитель',
      name: co.legalName || co.name || '________',
      lines: this.reqLines({
        address: co.address,
        inn: co.inn,
        oked: co.oked,
        vatCode: co.vatCode,
        bank: co.bank,
        mfo: co.mfo,
        account: co.account,
        phone: co.phone,
      }),
      position: co.directorPosition || 'Директор',
      signer: shortName(co.director) || '________',
    };
  }

  private reqLines(
    r: Partial<
      Record<'address' | 'inn' | 'oked' | 'vatCode' | 'bank' | 'mfo' | 'account' | 'phone', string>
    >,
  ) {
    const rows: [string, string | undefined][] = [
      ['Адрес', r.address],
      ['ИНН', r.inn],
      ['ОКЭД', r.oked],
      ['Рег. код НДС', r.vatCode],
      ['Банк', r.bank],
      ['МФО', r.mfo],
      ['Р/с', r.account],
      ['Тел.', r.phone],
    ];
    return rows.filter((x): x is [string, string] => Boolean(x[1]?.trim()));
  }

  private itemsTable(items: ItemLine[], currency: string, totals: TableTotal[]): Block {
    return {
      t: 'table',
      head: [
        '№',
        'Услуга',
        'Кол-во',
        `Цена, ${currency === 'UZS' ? 'сум' : currency}`,
        `Сумма, ${currency === 'UZS' ? 'сум' : currency}`,
      ],
      align: ['left', 'left', 'right', 'right', 'right'],
      widths: [0.06, 0.5, 0.1, 0.17, 0.17],
      rows: items.map((i, idx) => [
        String(idx + 1),
        [
          i.description,
          join([i.tariffName ? `Тариф «${i.tariffName}»` : null, i.tariffDescription], ': '),
          Number(i.discountPct) ? `скидка ${Number(i.discountPct)}%` : '',
        ]
          .filter(Boolean)
          .join('\n'),
        Number(i.quantity).toLocaleString('ru-RU'),
        amount(i.unitPrice),
        amount(i.total),
      ]),
      totals,
    };
  }

  private async itemLines(proposalId: string): Promise<ItemLine[]> {
    const items = await this.prisma.proposalItem.findMany({
      where: { proposalId },
      include: {
        service: { select: { nameRu: true } },
        tariff: { select: { name: true, description: true } },
      },
      orderBy: { sort: 'asc' },
    });
    return items.map((i) => ({
      description: i.description,
      serviceName: i.service?.nameRu ?? null,
      tariffName: i.tariff?.name ?? null,
      tariffDescription: i.tariff?.description ?? null,
      quantity: i.quantity.toString(),
      unitPrice: i.unitPrice.toFixed(2),
      discountPct: i.discountPct.toString(),
      total: i.total.toFixed(2),
    }));
  }

  // ─────────────── Коммерческое предложение ───────────────

  async proposalModel(auth: AuthContext, id: string): Promise<DocModel> {
    const p: ProposalDto = await this.proposals.get(auth, id);
    const [co, ds, { client, req }, items] = await Promise.all([
      this.settings.company(),
      this.documentSettings(),
      this.clientParty(p.client.id),
      this.itemLines(p.id),
    ]);
    const cur = p.currency;
    const totals: TableTotal[] = [{ label: 'Сумма', value: moneyText(p.subtotal, cur) }];
    if (Number(p.discountAmount))
      totals.push({ label: 'Скидка', value: `− ${moneyText(p.discountAmount, cur)}` });
    totals.push({ label: 'Итого', value: moneyText(p.total, cur), strong: true });

    const blocks: Block[] = [
      {
        t: 'title',
        text: 'Коммерческое предложение',
        sub: `${p.number} от ${shortDate(p.updatedAt)}`,
      },
      {
        t: 'kv',
        rows: [
          ['Для', req.legalName || client.name],
          ['Проект', p.title],
          ['Менеджер', p.manager.name],
          ...(p.validUntil
            ? ([['Действительно до', shortDate(p.validUntil)]] as [string, string][])
            : []),
        ],
      },
    ];
    if (ds.proposalIntro.trim()) blocks.push({ t: 'p', text: ds.proposalIntro.trim() });
    if (p.description) blocks.push({ t: 'p', text: p.description });
    blocks.push(this.itemsTable(items, cur, totals));
    blocks.push({ t: 'p', text: `Итого к оплате: ${amountInWords(p.total, cur)}.` });
    const terms: [string, string][] = [];
    if (p.implementationTerm) terms.push(['Срок реализации', p.implementationTerm]);
    if (p.paymentTerms) terms.push(['Условия оплаты', p.paymentTerms]);
    if (terms.length) blocks.push({ t: 'h', text: 'Условия' }, { t: 'kv', rows: terms });
    if (ds.proposalNote.trim()) blocks.push({ t: 'p', text: ds.proposalNote.trim() });
    blocks.push({
      t: 'sign',
      company: co.legalName || co.name || 'Fluggi',
      position: co.directorPosition || 'Директор',
      signer: shortName(co.director) || '________',
    });
    return {
      fileName: `${p.number}`,
      title: `Коммерческое предложение ${p.number}`,
      brand: this.brand(co),
      blocks,
    };
  }

  // ─────────────── Договор ───────────────

  async contractModel(auth: AuthContext, id: string): Promise<DocModel> {
    const c = await this.contracts.get(auth, id);
    const [co, ds, { client, req }, proposal] = await Promise.all([
      this.settings.company(),
      this.documentSettings(),
      this.clientParty(c.client.id),
      c.proposal
        ? this.prisma.proposal.findUnique({
            where: { id: c.proposal.id },
            select: {
              id: true,
              implementationTerm: true,
              paymentTerms: true,
              currency: true,
              total: true,
            },
          })
        : null,
    ]);
    const items = proposal ? await this.itemLines(proposal.id) : [];
    const cur = c.currency;
    const lines: ItemLine[] =
      items.length && proposal?.currency === cur
        ? items
        : [
            {
              description: items.length
                ? items.map((i) => i.description).join('; ')
                : 'Услуги по договору',
              serviceName: null,
              tariffName: null,
              tariffDescription: null,
              quantity: '1',
              unitPrice: c.amount,
              discountPct: '0',
              total: c.amount,
            },
          ];
    const itemsSum = lines.reduce((s, i) => s + Number(i.total), 0);
    const totals: TableTotal[] = [
      { label: 'Итого по договору', value: moneyText(c.amount, cur), strong: true },
    ];
    if (Math.abs(itemsSum - Number(c.amount)) > 0.009)
      totals.unshift({ label: 'Сумма по позициям', value: moneyText(itemsSum, cur) });

    const signer = (genitive: string, position: string, director: string) =>
      genitive || join([position.toLowerCase(), director], ' ');
    const values: Record<string, string> = {
      'contract.number': c.number,
      'contract.date': longDate(c.contractDate),
      'contract.amount': moneyText(c.amount, cur),
      'contract.amountWords': amountInWords(c.amount, cur),
      city: ds.city,
      'company.name': co.legalName || co.name,
      'company.signer': signer(co.signerGenitive, co.directorPosition, co.director),
      'company.director': co.director,
      'company.position': co.directorPosition,
      'company.basis': co.basis,
      'client.name': req.legalName || client.name,
      'client.signer': signer(req.signerGenitive, req.directorPosition, req.director),
      'client.director': req.director,
      'client.position': req.directorPosition,
      'client.basis': req.basis,
      services: lines.map((i) => i.description).join('; '),
      term: proposal?.implementationTerm ?? '',
      paymentTerms: proposal?.paymentTerms ?? '',
    };
    const parties: Block = {
      t: 'parties',
      parties: [
        this.companyParty(co),
        {
          role: 'Заказчик',
          name: req.legalName || client.name,
          lines: this.reqLines({ ...req, phone: req.phone || client.phone || '' }),
          position: req.directorPosition || 'Директор',
          signer: shortName(req.director) || '________',
        },
      ],
    };
    const blocks = templateBlocks(
      ds.contractTemplate,
      values,
      { services: this.itemsTable(lines, cur, totals), parties },
      { t: 'meta', left: ds.city, right: longDate(c.contractDate) },
    );
    return {
      fileName: `Dogovor_${c.number}`,
      title: `Договор ${c.number}`,
      brand: this.brand(co),
      blocks,
    };
  }

  /** Каких реквизитов не хватает — показываем перед скачиванием. */
  async check(
    auth: AuthContext,
    kind: 'proposals' | 'contracts',
    id: string,
  ): Promise<DocumentCheckDto> {
    const co = await this.settings.company();
    const missing: string[] = [];
    if (!co.name && !co.legalName)
      missing.push('Название компании (Настройки → Автоматизация → Реквизиты)');
    if (kind === 'contracts') {
      const c = await this.contracts.get(auth, id);
      if (!co.inn) missing.push('ИНН компании');
      if (!co.bank || !co.mfo || !co.account) missing.push('Банк, МФО и расчётный счёт компании');
      if (!co.director) missing.push('Директор компании');
      if (!co.signerGenitive)
        missing.push('Компания: «в лице …» в родительном падеже (директора Иванова И.И.)');
      const { req } = await this.clientParty(c.client.id);
      if (!req.legalName)
        missing.push('Юридическое название клиента (карточка клиента → Реквизиты)');
      if (!req.inn) missing.push('ИНН / ПИНФЛ клиента');
      if (!req.director) missing.push('Подписант клиента');
      if (!req.signerGenitive) missing.push('Клиент: «в лице …» в родительном падеже');
    } else {
      await this.proposals.get(auth, id);
    }
    return { missing };
  }
}
