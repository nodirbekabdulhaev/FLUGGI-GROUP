import {
  Body,
  Controller,
  Delete,
  Get,
  HttpCode,
  Param,
  Post,
  Query,
  Res,
  StreamableFile,
  UploadedFile,
  UseInterceptors,
} from '@nestjs/common';
import { FileInterceptor } from '@nestjs/platform-express';
import { FILE_CATEGORIES, MAX_FILE_BYTES, type FileDto } from '@fluggi/contracts';
import type { Response } from 'express';
import { z } from 'zod';
import type { AuthContext, RequestMeta } from '../../core/auth/auth-context';
import { AuthenticatedOnly, CurrentUser, ReqMeta } from '../../core/auth/decorators';
import { UuidPipe } from '../../core/http/uuid.pipe';
import { zod } from '../../core/http/zod.pipe';
import { FilesService } from './files.service';

const uploadBody = z.object({
  dealId: z.uuid().optional(),
  contractId: z.uuid().optional(),
  proposalId: z.uuid().optional(),
  paymentId: z.uuid().optional(),
  projectId: z.uuid().optional(),
  taskId: z.uuid().optional(),
  category: z.enum(FILE_CATEGORIES).default('DOCUMENT'),
});

const listQuery = z.object({
  dealId: z.uuid().optional(),
  projectId: z.uuid().optional(),
  taskId: z.uuid().optional(),
});

/** Файлы сделки, проекта и задач. Права проверяются по записи, к которой относится файл. */
@Controller('files')
export class FilesController {
  constructor(private readonly files: FilesService) {}

  @Post()
  @AuthenticatedOnly()
  @UseInterceptors(FileInterceptor('file', { limits: { fileSize: MAX_FILE_BYTES, files: 1 } }))
  upload(
    @CurrentUser() auth: AuthContext,
    @UploadedFile() file: Express.Multer.File | undefined,
    @Body(zod(uploadBody)) body: z.output<typeof uploadBody>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<FileDto> {
    const { category, ...target } = body;
    return this.files.upload(auth, file, target, category, meta);
  }

  @Get()
  @AuthenticatedOnly()
  list(
    @CurrentUser() auth: AuthContext,
    @Query(zod(listQuery)) q: z.output<typeof listQuery>,
  ): Promise<FileDto[]> {
    return this.files.list(auth, q);
  }

  @Get(':id/download')
  @AuthenticatedOnly()
  async download(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Res({ passthrough: true }) res: Response,
  ): Promise<StreamableFile> {
    const { file, stream, name } = await this.files.open(auth, id);
    res.setHeader('Content-Type', file.mimeType);
    res.setHeader(
      'Content-Disposition',
      `attachment; filename*=UTF-8''${encodeURIComponent(name)}`,
    );
    res.setHeader('X-Content-Type-Options', 'nosniff');
    return new StreamableFile(stream);
  }

  @Delete(':id')
  @HttpCode(204)
  @AuthenticatedOnly()
  remove(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @ReqMeta() meta: RequestMeta,
  ): Promise<void> {
    return this.files.remove(auth, id, meta);
  }
}
