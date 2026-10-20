"use strict";
var __decorate = (this && this.__decorate) || function (decorators, target, key, desc) {
    var c = arguments.length, r = c < 3 ? target : desc === null ? desc = Object.getOwnPropertyDescriptor(target, key) : desc, d;
    if (typeof Reflect === "object" && typeof Reflect.decorate === "function") r = Reflect.decorate(decorators, target, key, desc);
    else for (var i = decorators.length - 1; i >= 0; i--) if (d = decorators[i]) r = (c < 3 ? d(r) : c > 3 ? d(target, key, r) : d(target, key)) || r;
    return c > 3 && r && Object.defineProperty(target, key, r), r;
};
var __metadata = (this && this.__metadata) || function (k, v) {
    if (typeof Reflect === "object" && typeof Reflect.metadata === "function") return Reflect.metadata(k, v);
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.IntakeService = exports.igKey = exports.phoneKey = void 0;
const common_1 = require("@nestjs/common");
const contracts_1 = require("@fluggi/contracts");
const prisma_service_1 = require("../../core/prisma/prisma.service");
const activity_service_1 = require("../crm/activity.service");
const crm_access_service_1 = require("../crm/crm-access.service");
const leads_service_1 = require("../leads/leads.service");
const notifications_service_1 = require("../notifications/notifications.service");
const SETTINGS_KEY = 'integrations';
/** Телефон для сравнения: последние 9 цифр (+998 90 123-45-67 → 901234567). */
const phoneKey = (v) => {
    const d = (v ?? '').replace(/\D/g, '');
    return d.length >= 7 ? d.slice(-9) : null;
};
exports.phoneKey = phoneKey;
/** Instagram для сравнения: без @, ссылки и регистра. */
const igKey = (v) => (v ?? '')
    .trim()
    .replace(/^https?:\/\/(www\.)?instagram\.com\//i, '')
    .replace(/^@/, '')
    .replace(/\/.*$/, '')
    .toLowerCase() || null;
exports.igKey = igKey;
/**
 * Приём заявок из внешних каналов (форма сайта, Instagram, таргет):
 * поиск дубля среди открытых лидов, выбор ответственного, создание лида.
 */
let IntakeService = class IntakeService {
    prisma;
    leads;
    activity;
    notifications;
    access;
    constructor(prisma, leads, activity, notifications, access) {
        this.prisma = prisma;
        this.leads = leads;
        this.activity = activity;
        this.notifications = notifications;
        this.access = access;
    }
    async settings() {
        const row = await this.prisma.setting.findUnique({ where: { key: SETTINGS_KEY } });
        const parsed = contracts_1.integrationSettingsSchema.safeParse(row?.value ?? {});
        return parsed.success ? parsed.data : contracts_1.integrationSettingsSchema.parse({});
    }
    async saveSettings(value, userId) {
        const json = value;
        await this.prisma.setting.upsert({
            where: { key: SETTINGS_KEY },
            update: { value: json, updatedById: userId },
            create: { key: SETTINGS_KEY, value: json, updatedById: userId },
        });
        return this.settings();
    }
    /**
     * Ответственный: указанный менеджер/РОП, иначе менеджер отдела с наименьшим числом открытых
     * лидов (в отделе без менеджеров — его РОП), иначе любой менеджер/РОП компании.
     */
    async pickOwner(ownerId, teamId) {
        // Ответственный за лид — только менеджер или РОП; CEO получает уведомление
        if (ownerId) {
            const u = await this.prisma.user.findFirst({
                where: {
                    id: ownerId,
                    status: 'ACTIVE',
                    deletedAt: null,
                    role: { code: { in: ['MANAGER', 'ROP'] } },
                },
            });
            if (u)
                return u.id;
        }
        if (teamId) {
            const inTeam = await this.prisma.user.count({
                where: { status: 'ACTIVE', deletedAt: null, teamId, role: { code: 'MANAGER' } },
            });
            if (!inTeam) {
                const team = await this.prisma.team.findUnique({ where: { id: teamId } });
                if (team?.headId)
                    return team.headId;
            }
        }
        try {
            return await this.access.leastLoadedOwner(teamId);
        }
        catch {
            // Нет ни одного менеджера и РОП — заявка не теряется: CEO
            const ceo = await this.prisma.user.findFirstOrThrow({
                where: { status: 'ACTIVE', deletedAt: null, role: { code: 'CEO' } },
                orderBy: { createdAt: 'asc' },
            });
            return ceo.id;
        }
    }
    /** Открытый лид с тем же телефоном или Instagram. */
    async findDuplicate(phone, instagram) {
        const p = (0, exports.phoneKey)(phone);
        const ig = (0, exports.igKey)(instagram);
        if (!p && !ig)
            return null;
        // Сравнение в БД по цифрам: «+998 90 123-45-67» и «901234567» — один номер
        // Цифры номера: убираем типичные разделители (+, пробел, дефис, скобки, точка)
        const ids = await this.prisma.$queryRaw `
      SELECT id FROM leads
      WHERE status = 'OPEN' AND deleted_at IS NULL AND (
        (${p} IS NOT NULL AND RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
          COALESCE(phone, ''), '+', ''), ' ', ''), '-', ''), '(', ''), ')', ''), '.', ''), 9) = ${p})
        OR (${ig} IS NOT NULL AND LOWER(TRIM(LEADING '@' FROM COALESCE(instagram, ''))) = ${ig})
      )
      ORDER BY created_at DESC
      LIMIT 20`;
        if (!ids.length)
            return null;
        const candidates = await this.prisma.lead.findMany({
            where: { id: { in: ids.map((r) => r.id) } },
            orderBy: { createdAt: 'desc' },
        });
        return (candidates.find((l) => (p && (0, exports.phoneKey)(l.phone) === p) || (ig && (0, exports.igKey)(l.instagram) === ig)) ??
            null);
    }
    /** Новый лид или отметка «повторная заявка» в найденном. */
    async intake(input) {
        const dup = await this.findDuplicate(input.phone, input.instagram);
        if (dup) {
            await this.prisma.$transaction((tx) => this.activity.log(tx, {
                type: 'lead.inbound_repeat',
                actorId: null,
                leadId: dup.id,
                payload: { channel: input.channel, text: input.comment?.slice(0, 1000) ?? null },
            }));
            await this.notifications.notify([dup.ownerId], {
                type: 'lead.inbound_repeat',
                title: 'Повторная заявка',
                body: `${dup.title}: ${input.channel}${input.comment ? ` — ${input.comment.slice(0, 200)}` : ''}`,
                link: `/sales/leads/${dup.id}`,
            }, null);
            return { leadId: dup.id, duplicate: true };
        }
        const ownerId = await this.pickOwner(input.ownerId, input.teamId);
        const lead = await this.leads.createInbound({ ...input, ownerId });
        return { leadId: lead.id, duplicate: false };
    }
};
exports.IntakeService = IntakeService;
exports.IntakeService = IntakeService = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [prisma_service_1.PrismaService,
        leads_service_1.LeadsService,
        activity_service_1.ActivityService,
        notifications_service_1.NotificationsService,
        crm_access_service_1.CrmAccessService])
], IntakeService);
//# sourceMappingURL=intake.service.js.map