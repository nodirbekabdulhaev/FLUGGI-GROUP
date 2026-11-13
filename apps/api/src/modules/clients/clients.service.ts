import { Injectable } from '@nestjs/common';
import {
  clientRequisitesSchema,
  formatNumber,
  type ClientDetailDto,
  type ClientDto,
  type ClientListQuery,
  type ContactDto,
  type contactSchema,
  type createClientSchema,
  type Paginated,
  type updateClientSchema,
} from '@fluggi/contracts';
import type { Contact, Prisma } from '@fluggi/db';
import type { z } from 'zod';
import type { AuthContext, RequestMeta } from '../../core/auth/auth-context';
import { AuditService, diffFields } from '../../core/audit/audit.service';
import { businessRule, notFound } from '../../core/http/app.exception';
import { PrismaService } from '../../core/prisma/prisma.service';
import { ActivityService } from '../crm/activity.service';
import { CrmAccessService } from '../crm/crm-access.service';
import { dealInclude, toDealDto } from '../deals/deals.mapper';

const clientInclude = {
  owner: { select: { id: true, fullName: true } },
  team: { select: { id: true, name: true } },
  source: { select: { id: true, nameRu: true } },
  deals: { where: { deletedAt: null }, select: { status: true } },
} satisfies Prisma.ClientInclude;

type ClientRow = Prisma.ClientGetPayload<{ include: typeof clientInclude }>;

const toClientDto = (c: ClientRow): ClientDto => ({
  id: c.id,
  number: formatNumber('C', c.number),
  name: c.name,
  type: c.type,
  industry: c.industry,
  phone: c.phone,
  email: c.email,
  telegram: c.telegram,
  website: c.website,
  city: c.city,
  country: c.country,
  owner: { id: c.owner.id, name: c.owner.fullName },
  team: c.team,
  source: c.source ? { id: c.source.id, name: c.source.nameRu } : null,
  health: c.health,
  comment: c.comment,
  dealsCount: c.deals.length,
  openDealsCount: c.deals.filter((d) => d.status === 'OPEN').length,
  createdAt: c.createdAt.toISOString(),
});

const toContactDto = (c: Contact): ContactDto => ({
  id: c.id,
  fullName: c.fullName,
  position: c.position,
  phone: c.phone,
  telegram: c.telegram,
  whatsapp: c.whatsapp,
  instagram: c.instagram,
  email: c.email,
  isPrimary: c.isPrimary,
});

@Injectable()
export class ClientsService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly access: CrmAccessService,
    private readonly activity: ActivityService,
    private readonly audit: AuditService,
  ) {}

  async list(
    auth: AuthContext,
    q: ClientListQuery & { page: number; pageSize: number },
  ): Promise<Paginated<ClientDto>> {
    const and: Prisma.ClientWhereInput[] = [this.access.clientWhere(auth)];
    if (q.q) {
      and.push({
        OR: [
          { name: { contains: q.q } },
          { phone: { contains: q.q } },
          { telegram: { contains: q.q } },
          {
            contacts: {
              some: {
                OR: [{ fullName: { contains: q.q } }, { phone: { contains: q.q } }],
              },
            },
          },
        ],
      });
    }
    if (q.ownerId) and.push({ ownerId: q.ownerId });
    if (q.teamId) and.push({ teamId: q.teamId });
    if (q.type) and.push({ type: q.type });
    const where = { AND: and };
    const [items, total] = await Promise.all([
      this.prisma.client.findMany({
        where,
        include: clientInclude,
        orderBy: { createdAt: 'desc' },
        skip: (q.page - 1) * q.pageSize,
        take: q.pageSize,
      }),
      this.prisma.client.count({ where }),
    ]);
    return { items: items.map(toClientDto), total, page: q.page, pageSize: q.pageSize };
  }

  async get(auth: AuthContext, id: string): Promise<ClientDetailDto> {
    await this.access.client(auth, id);
    const client = await this.prisma.client.findUniqueOrThrow({
      where: { id },
      include: {
        ...clientInclude,
        contacts: { orderBy: [{ isPrimary: 'desc' }, { fullName: 'asc' }] },
      },
    });
    // Сделки клиента — только те, что видны пользователю.
    const deals = auth.permissions['deal.read']
      ? await this.prisma.deal.findMany({
          where: { AND: [this.access.dealWhere(auth), { clientId: id }] },
          include: dealInclude,
          orderBy: { createdAt: 'desc' },
        })
      : [];
    return {
      ...toClientDto(client),
      contacts: client.contacts.map(toContactDto),
      deals: deals.map(toDealDto),
      requisites: client.requisites
        ? (clientRequisitesSchema.safeParse(client.requisites).data ?? null)
        : null,
    };
  }

  async create(
    auth: AuthContext,
    input: z.output<typeof createClientSchema>,
    meta: RequestMeta,
  ): Promise<ClientDetailDto> {
    const owner = await this.access.assignableOwner(auth, input.ownerId, 'client.create');
    const { contact, ownerId: _ignored, ...fields } = input;
    const client = await this.prisma.$transaction(async (tx) => {
      const created = await tx.client.create({
        data: {
          ...fields,
          ownerId: owner.id,
          teamId: owner.teamId,
          contacts: contact ? { create: { ...contact, isPrimary: true } } : undefined,
        },
      });
      await this.activity.log(tx, {
        type: 'client.created',
        actorId: auth.userId,
        clientId: created.id,
        payload: { name: created.name },
      });
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: 'client.create',
        entityType: 'client',
        entityId: created.id,
        changes: { name: { old: null, new: created.name } },
        meta,
      });
      return created;
    });
    return this.get(auth, client.id);
  }

  async update(
    auth: AuthContext,
    id: string,
    input: z.output<typeof updateClientSchema>,
    meta: RequestMeta,
  ): Promise<ClientDetailDto> {
    const before = await this.access.client(auth, id, 'client.update');
    await this.prisma.$transaction(async (tx) => {
      const after = await tx.client.update({ where: { id }, data: input });
      const changes = diffFields(before, after, [
        'name',
        'type',
        'phone',
        'email',
        'telegram',
        'website',
        'city',
        'industry',
      ]);
      if (changes) {
        await this.activity.log(tx, {
          type: 'client.updated',
          actorId: auth.userId,
          clientId: id,
          payload: { changes },
        });
        await this.audit.log(tx, {
          actorId: auth.userId,
          action: 'client.update',
          entityType: 'client',
          entityId: id,
          changes,
          meta,
        });
      }
    });
    return this.get(auth, id);
  }

  async addContact(
    auth: AuthContext,
    clientId: string,
    input: z.output<typeof contactSchema>,
    meta: RequestMeta,
  ): Promise<ContactDto> {
    await this.access.client(auth, clientId, 'client.update');
    return this.prisma.$transaction(async (tx) => {
      if (input.isPrimary)
        await tx.contact.updateMany({ where: { clientId }, data: { isPrimary: false } });
      const c = await tx.contact.create({ data: { ...input, clientId } });
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: 'contact.create',
        entityType: 'contact',
        entityId: c.id,
        changes: { fullName: { old: null, new: c.fullName } },
        meta,
      });
      return toContactDto(c);
    });
  }

  async updateContact(
    auth: AuthContext,
    id: string,
    input: z.output<typeof contactSchema>,
    meta: RequestMeta,
  ): Promise<ContactDto> {
    const contact = await this.prisma.contact.findUnique({ where: { id } });
    if (!contact) throw notFound('Контакт');
    await this.access.client(auth, contact.clientId, 'client.update');
    return this.prisma.$transaction(async (tx) => {
      if (input.isPrimary)
        await tx.contact.updateMany({
          where: { clientId: contact.clientId, id: { not: id } },
          data: { isPrimary: false },
        });
      const after = await tx.contact.update({ where: { id }, data: input });
      const changes = diffFields(contact, after, [
        'fullName',
        'position',
        'phone',
        'telegram',
        'email',
        'isPrimary',
      ]);
      if (changes) {
        await this.audit.log(tx, {
          actorId: auth.userId,
          action: 'contact.update',
          entityType: 'contact',
          entityId: id,
          changes,
          meta,
        });
      }
      return toContactDto(after);
    });
  }

  async removeContact(auth: AuthContext, id: string, meta: RequestMeta): Promise<void> {
    const contact = await this.prisma.contact.findUnique({ where: { id } });
    if (!contact) throw notFound('Контакт');
    await this.access.client(auth, contact.clientId, 'client.update');
    if (await this.prisma.deal.count({ where: { contactId: id } })) {
      throw businessRule('Контакт указан в сделке — сначала замените его там');
    }
    await this.prisma.$transaction(async (tx) => {
      await tx.contact.delete({ where: { id } });
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: 'contact.delete',
        entityType: 'contact',
        entityId: id,
        changes: { fullName: { old: contact.fullName, new: null } },
        meta,
      });
    });
  }
}
