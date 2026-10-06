import { Injectable } from '@nestjs/common';
import {
  ALLOWED_FILE_TYPES,
  MAX_FILE_BYTES,
  type FileCategory,
  type FileDto,
} from '@fluggi/contracts';
import type { StoredFile } from '@fluggi/db';
import type { AuthContext, RequestMeta } from '../../core/auth/auth-context';
import { AuditService } from '../../core/audit/audit.service';
import { AppException, businessRule, notFound } from '../../core/http/app.exception';
import { PrismaService } from '../../core/prisma/prisma.service';
import { detectMime, signatureMatches } from '../../core/storage/file-signature';
import { StorageService } from '../../core/storage/storage.service';
import { CrmAccessService } from '../crm/crm-access.service';

export interface UploadTarget {
  dealId?: string;
  contractId?: string;
  proposalId?: string;
  paymentId?: string;
}

export const toFileDto = (
  f: StoredFile & { uploadedBy: { id: string; fullName: string } },
): FileDto => ({
  id: f.id,
  originalName: f.originalName,
  mimeType: f.mimeType,
  sizeBytes: f.sizeBytes,
  category: f.category,
  uploadedBy: { id: f.uploadedBy.id, name: f.uploadedBy.fullName },
  createdAt: f.createdAt.toISOString(),
});

/** Безопасное имя для заголовка Content-Disposition. */
const safeName = (name: string) => name.replace(/[\r\n"\\/]/g, '_').slice(0, 200);

@Injectable()
export class FilesService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly storage: StorageService,
    private readonly access: CrmAccessService,
    private readonly audit: AuditService,
  ) {}

  /** Сделка, к которой относится файл: права на файл = права на сделку. */
  private async dealOf(t: UploadTarget): Promise<string> {
    if (t.dealId) return t.dealId;
    if (t.contractId)
      return (await this.prisma.contract.findUniqueOrThrow({ where: { id: t.contractId } })).dealId;
    if (t.proposalId)
      return (await this.prisma.proposal.findUniqueOrThrow({ where: { id: t.proposalId } })).dealId;
    if (t.paymentId)
      return (await this.prisma.payment.findUniqueOrThrow({ where: { id: t.paymentId } })).dealId;
    throw businessRule('Укажите, к чему относится файл');
  }

  async upload(
    auth: AuthContext,
    file: { originalname: string; mimetype: string; size: number; buffer: Buffer } | undefined,
    target: UploadTarget,
    category: FileCategory,
    meta: RequestMeta,
  ): Promise<FileDto> {
    if (!file) throw new AppException('VALIDATION_ERROR', 'Файл не получен');
    if (file.size > MAX_FILE_BYTES) throw new AppException('VALIDATION_ERROR', 'Файл больше 25 МБ');
    const exts = ALLOWED_FILE_TYPES[file.mimetype];
    const ext = file.originalname.split('.').pop()?.toLowerCase() ?? '';
    if (!exts || !exts.includes(ext)) {
      throw new AppException(
        'VALIDATION_ERROR',
        'Недопустимый тип файла. Разрешены PDF, изображения, DOCX, XLSX, ZIP',
      );
    }
    if (!signatureMatches(file.mimetype, detectMime(file.buffer))) {
      throw new AppException('VALIDATION_ERROR', 'Содержимое файла не соответствует его типу');
    }
    const dealId = await this.dealOf(target).catch(() => {
      throw notFound('Запись');
    });
    await this.access.deal(auth, dealId, 'deal.read');
    // Загружать может тот, кто может менять сделку или договор.
    if (!auth.permissions['deal.update'] && !auth.permissions['contract.update'])
      throw notFound('Сделка');

    const key = this.storage.newKey('deals', ext);
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
      return toFileDto(saved);
    });
  }

  async open(auth: AuthContext, id: string) {
    const f = await this.prisma.storedFile.findFirst({ where: { id, deletedAt: null } });
    if (!f || !f.dealId) throw notFound('Файл');
    await this.access.deal(auth, f.dealId, 'deal.read');
    return {
      file: f,
      stream: await this.storage.get(f.storageKey),
      name: safeName(f.originalName),
    };
  }

  async list(auth: AuthContext, dealId: string): Promise<FileDto[]> {
    await this.access.deal(auth, dealId, 'deal.read');
    const files = await this.prisma.storedFile.findMany({
      where: { dealId, deletedAt: null },
      include: { uploadedBy: { select: { id: true, fullName: true } } },
      orderBy: { createdAt: 'desc' },
    });
    return files.map(toFileDto);
  }

  async remove(auth: AuthContext, id: string, meta: RequestMeta): Promise<void> {
    const f = await this.prisma.storedFile.findFirst({ where: { id, deletedAt: null } });
    if (!f || !f.dealId) throw notFound('Файл');
    await this.access.deal(auth, f.dealId, 'deal.update');
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
}
