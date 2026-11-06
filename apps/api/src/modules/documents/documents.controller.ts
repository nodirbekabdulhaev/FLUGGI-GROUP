import { Body, Controller, Get, Param, Put, Query, Res, StreamableFile } from '@nestjs/common';
import {
  clientRequisitesSchema,
  DOCUMENT_FORMATS,
  documentSettingsSchema,
  type ClientRequisites,
  type DocumentCheckDto,
  type DocumentSettings,
} from '@fluggi/contracts';
import type { Response } from 'express';
import { z } from 'zod';
import type { AuthContext, RequestMeta } from '../../core/auth/auth-context';
import { CurrentUser, ReqMeta, RequirePermission } from '../../core/auth/decorators';
import { UuidPipe } from '../../core/http/uuid.pipe';
import { zod } from '../../core/http/zod.pipe';
import type { DocModel } from './doc-model';
import { DocumentsService } from './documents.service';
import { docxBuffer } from './render-docx';
import { renderHtml } from './render-html';
import { pdfBuffer } from './render-pdf';
import { renderTxt } from './render-txt';

const formatQuery = z.object({
  format: z.enum(DOCUMENT_FORMATS).default('html'),
  download: z.enum(['true', 'false']).optional(),
});
type FormatQuery = z.output<typeof formatQuery>;

const TRANSLIT: Record<string, string> = { Д: 'D', Г: 'G', К: 'K', П: 'P' };
/**
 * Имя файла латиницей: «ДГ-0001» → «DG-0001». Только filename="…" — с filename*=UTF-8''
 * Chromium в части сборок отдаёт имя «download».
 */
const asciiName = (name: string) =>
  name.replace(/[^\x20-\x7e]/g, (ch) => TRANSLIT[ch] ?? '_').replace(/["\\]/g, '_');

const TYPES = {
  html: 'text/html; charset=utf-8',
  txt: 'text/plain; charset=utf-8',
  pdf: 'application/pdf',
  docx: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
} as const;

/** Генерация КП и договора с закреплённым дизайном: HTML (просмотр), PDF, Word, TXT. */
@Controller()
export class DocumentsController {
  constructor(private readonly docs: DocumentsService) {}

  private async send(model: DocModel, q: FormatQuery, res: Response) {
    const body =
      q.format === 'html'
        ? Buffer.from(renderHtml(model))
        : q.format === 'txt'
          ? Buffer.from(renderTxt(model))
          : q.format === 'pdf'
            ? await pdfBuffer(model)
            : await docxBuffer(model);
    const name = `${model.fileName}.${q.format}`;
    res.setHeader('Content-Type', TYPES[q.format]);
    res.setHeader(
      'Content-Disposition',
      `${q.download === 'true' ? 'attachment' : 'inline'}; filename="${asciiName(name)}"`,
    );
    res.setHeader('Cache-Control', 'no-store');
    return new StreamableFile(body);
  }

  @Get('documents/proposals/:id')
  @RequirePermission('proposal.read')
  async proposal(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Query(zod(formatQuery)) q: FormatQuery,
    @Res({ passthrough: true }) res: Response,
  ) {
    return this.send(await this.docs.proposalModel(auth, id), q, res);
  }

  @Get('documents/contracts/:id')
  @RequirePermission('contract.read')
  async contract(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Query(zod(formatQuery)) q: FormatQuery,
    @Res({ passthrough: true }) res: Response,
  ) {
    return this.send(await this.docs.contractModel(auth, id), q, res);
  }

  @Get('documents/proposals/:id/check')
  @RequirePermission('proposal.read')
  checkProposal(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
  ): Promise<DocumentCheckDto> {
    return this.docs.check(auth, 'proposals', id);
  }

  @Get('documents/contracts/:id/check')
  @RequirePermission('contract.read')
  checkContract(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
  ): Promise<DocumentCheckDto> {
    return this.docs.check(auth, 'contracts', id);
  }

  @Get('settings/documents')
  @RequirePermission('settings.manage', 'ALL')
  settings(): Promise<DocumentSettings> {
    return this.docs.documentSettings();
  }

  @Put('settings/documents')
  @RequirePermission('settings.manage', 'ALL')
  saveSettings(
    @CurrentUser() auth: AuthContext,
    @Body(zod(documentSettingsSchema)) body: DocumentSettings,
    @ReqMeta() meta: RequestMeta,
  ): Promise<DocumentSettings> {
    return this.docs.saveDocumentSettings(auth, body, meta);
  }

  @Put('clients/:id/requisites')
  @RequirePermission('client.update')
  saveRequisites(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Body(zod(clientRequisitesSchema)) body: ClientRequisites,
    @ReqMeta() meta: RequestMeta,
  ): Promise<ClientRequisites> {
    return this.docs.saveClientRequisites(auth, id, body, meta);
  }
}
