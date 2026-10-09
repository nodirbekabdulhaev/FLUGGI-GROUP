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
exports.FilesService = exports.toFileDto = void 0;
const common_1 = require("@nestjs/common");
const contracts_1 = require("@fluggi/contracts");
const audit_service_1 = require("../../core/audit/audit.service");
const app_exception_1 = require("../../core/http/app.exception");
const prisma_service_1 = require("../../core/prisma/prisma.service");
const file_signature_1 = require("../../core/storage/file-signature");
const storage_service_1 = require("../../core/storage/storage.service");
const crm_access_service_1 = require("../crm/crm-access.service");
const project_access_service_1 = require("../projects/project-access.service");
const toFileDto = (f) => ({
    id: f.id,
    originalName: f.originalName,
    mimeType: f.mimeType,
    sizeBytes: f.sizeBytes,
    category: f.category,
    uploadedBy: { id: f.uploadedBy.id, name: f.uploadedBy.fullName },
    createdAt: f.createdAt.toISOString(),
});
exports.toFileDto = toFileDto;
/** Безопасное имя для заголовка Content-Disposition. */
const safeName = (name) => name.replace(/[\r\n"\\/]/g, '_').slice(0, 200);
let FilesService = class FilesService {
    prisma;
    storage;
    access;
    audit;
    projects;
    constructor(prisma, storage, access, audit, projects) {
        this.prisma = prisma;
        this.storage = storage;
        this.access = access;
        this.audit = audit;
        this.projects = projects;
    }
    /**
     * Файлы проекта и задач (ТЗ §22, §3.4): загружать и смотреть может каждый, кто видит
     * задачу (исполнитель — свои задачи) или проект. Возвращает projectId.
     */
    async projectTarget(auth, t) {
        if (t.taskId)
            return (await this.projects.task(auth, t.taskId)).projectId;
        return (await this.projects.project(auth, t.projectId)).id;
    }
    /** Удалить файл проекта/задачи может автор файла или руководитель проекта. */
    async assertCanRemoveProjectFile(auth, f) {
        await this.projectTarget(auth, f);
        if (f.uploadedById === auth.userId)
            return;
        if (!(await this.projects.can(auth, f.projectId, 'project.update')))
            throw (0, app_exception_1.notFound)('Файл');
    }
    /** Сделка, к которой относится файл: права на файл = права на сделку. */
    async dealOf(t) {
        if (t.dealId)
            return t.dealId;
        if (t.contractId)
            return (await this.prisma.contract.findUniqueOrThrow({ where: { id: t.contractId } })).dealId;
        if (t.proposalId)
            return (await this.prisma.proposal.findUniqueOrThrow({ where: { id: t.proposalId } })).dealId;
        if (t.paymentId)
            return (await this.prisma.payment.findUniqueOrThrow({ where: { id: t.paymentId } })).dealId;
        throw (0, app_exception_1.businessRule)('Укажите, к чему относится файл');
    }
    async upload(auth, file, target, category, meta) {
        if (!file)
            throw new app_exception_1.AppException('VALIDATION_ERROR', 'Файл не получен');
        if (file.size > contracts_1.MAX_FILE_BYTES)
            throw new app_exception_1.AppException('VALIDATION_ERROR', 'Файл больше 25 МБ');
        const exts = contracts_1.ALLOWED_FILE_TYPES[file.mimetype];
        const ext = file.originalname.split('.').pop()?.toLowerCase() ?? '';
        if (!exts || !exts.includes(ext)) {
            throw new app_exception_1.AppException('VALIDATION_ERROR', 'Недопустимый тип файла. Разрешены PDF, изображения, DOCX, XLSX, ZIP');
        }
        if (!(0, file_signature_1.signatureMatches)(file.mimetype, (0, file_signature_1.detectMime)(file.buffer))) {
            throw new app_exception_1.AppException('VALIDATION_ERROR', 'Содержимое файла не соответствует его типу');
        }
        let dealId = null;
        let projectId = null;
        if (target.projectId || target.taskId) {
            projectId = await this.projectTarget(auth, target);
        }
        else {
            dealId = await this.dealOf(target).catch(() => {
                throw (0, app_exception_1.notFound)('Запись');
            });
            await this.access.deal(auth, dealId, 'deal.read');
            // Загружать может тот, кто может менять сделку или договор.
            if (!auth.permissions['deal.update'] && !auth.permissions['contract.update'])
                throw (0, app_exception_1.notFound)('Сделка');
        }
        const key = this.storage.newKey(projectId ? 'projects' : 'deals', ext);
        await this.storage.put(key, file.buffer, file.mimetype);
        return this.prisma.$transaction(async (tx) => {
            const saved = await tx.storedFile.create({
                data: {
                    storageKey: key,
                    originalName: safeName(file.originalname),
                    mimeType: file.mimetype,
                    sizeBytes: file.size,
                    checksum: this.storage.checksum(file.buffer),
                    category,
                    uploadedById: auth.userId,
                    dealId,
                    projectId,
                    taskId: target.taskId,
                    contractId: target.contractId,
                    proposalId: target.proposalId,
                    paymentId: target.paymentId,
                },
                include: { uploadedBy: { select: { id: true, fullName: true } } },
            });
            await this.audit.log(tx, {
                actorId: auth.userId,
                action: 'file.upload',
                entityType: 'file',
                entityId: saved.id,
                changes: {
                    name: { old: null, new: saved.originalName },
                    size: { old: null, new: saved.sizeBytes },
                },
                meta,
            });
            return (0, exports.toFileDto)(saved);
        });
    }
    async open(auth, id) {
        const f = await this.prisma.storedFile.findFirst({ where: { id, deletedAt: null } });
        if (!f)
            throw (0, app_exception_1.notFound)('Файл');
        if (f.dealId)
            await this.access.deal(auth, f.dealId, 'deal.read');
        else if (f.projectId)
            await this.projectTarget(auth, f);
        else
            throw (0, app_exception_1.notFound)('Файл');
        return {
            file: f,
            stream: await this.storage.get(f.storageKey),
            name: safeName(f.originalName),
        };
    }
    async list(auth, q) {
        let where;
        if (q.taskId) {
            await this.projectTarget(auth, { taskId: q.taskId });
            where = { taskId: q.taskId };
        }
        else if (q.projectId) {
            await this.projectTarget(auth, { projectId: q.projectId });
            where = { projectId: q.projectId };
        }
        else if (q.dealId) {
            await this.access.deal(auth, q.dealId, 'deal.read');
            where = { dealId: q.dealId };
        }
        else
            throw (0, app_exception_1.businessRule)('Укажите, чьи файлы показать');
        const files = await this.prisma.storedFile.findMany({
            where: { ...where, deletedAt: null },
            include: { uploadedBy: { select: { id: true, fullName: true } } },
            orderBy: { createdAt: 'desc' },
        });
        return files.map(exports.toFileDto);
    }
    async remove(auth, id, meta) {
        const f = await this.prisma.storedFile.findFirst({ where: { id, deletedAt: null } });
        if (!f)
            throw (0, app_exception_1.notFound)('Файл');
        if (f.dealId)
            await this.access.deal(auth, f.dealId, 'deal.update');
        else if (f.projectId)
            await this.assertCanRemoveProjectFile(auth, f);
        else
            throw (0, app_exception_1.notFound)('Файл');
        await this.prisma.$transaction(async (tx) => {
            await tx.storedFile.update({ where: { id }, data: { deletedAt: new Date() } });
            await this.audit.log(tx, {
                actorId: auth.userId,
                action: 'file.delete',
                entityType: 'file',
                entityId: id,
                changes: { name: { old: f.originalName, new: null } },
                meta,
            });
        });
    }
};
exports.FilesService = FilesService;
exports.FilesService = FilesService = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [prisma_service_1.PrismaService,
        storage_service_1.StorageService,
        crm_access_service_1.CrmAccessService,
        audit_service_1.AuditService,
        project_access_service_1.ProjectAccessService])
], FilesService);
//# sourceMappingURL=files.service.js.map