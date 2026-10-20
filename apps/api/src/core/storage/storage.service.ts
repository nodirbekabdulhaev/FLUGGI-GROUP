import { createHash, randomUUID } from 'node:crypto';
import { createReadStream } from 'node:fs';
import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';
import type { Readable } from 'node:stream';
import { GetObjectCommand, PutObjectCommand, S3Client } from '@aws-sdk/client-s3';
import { Injectable, Logger } from '@nestjs/common';

/**
 * Хранилище файлов (ТЗ §52). Файлы — вне PostgreSQL:
 *  - STORAGE_DRIVER=local (по умолчанию в разработке) — папка STORAGE_LOCAL_DIR;
 *  - STORAGE_DRIVER=s3 — любое S3-совместимое хранилище (Beget S3, MinIO, AWS).
 * Ключи генерируются сервером, имя пользователя в путь не попадает (нет path traversal).
 */
@Injectable()
export class StorageService {
  private readonly logger = new Logger(StorageService.name);
  private readonly driver = process.env.STORAGE_DRIVER === 's3' ? 's3' : 'local';
  private readonly dir = path.resolve(
    process.env.STORAGE_LOCAL_DIR || path.join(process.cwd(), 'storage'),
  );
  private readonly bucket = process.env.STORAGE_BUCKET ?? '';
  private s3?: S3Client;

  private client(): S3Client {
    this.s3 ??= new S3Client({
      endpoint: process.env.STORAGE_ENDPOINT,
      region: process.env.STORAGE_REGION || 'ru-1',
      forcePathStyle: true,
      credentials: {
        accessKeyId: process.env.STORAGE_ACCESS_KEY ?? '',
        secretAccessKey: process.env.STORAGE_SECRET_KEY ?? '',
      },
    });
    return this.s3;
  }

  newKey(prefix: string, ext: string): string {
    const d = new Date();
    return `${prefix}/${d.getUTCFullYear()}/${String(d.getUTCMonth() + 1).padStart(2, '0')}/${randomUUID()}.${ext}`;
  }

  checksum(buf: Buffer): string {
    return createHash('sha256').update(buf).digest('hex');
  }

  async put(key: string, body: Buffer, contentType: string): Promise<void> {
    if (this.driver === 's3') {
      await this.client().send(
        new PutObjectCommand({
          Bucket: this.bucket,
          Key: key,
          Body: body,
          ContentType: contentType,
        }),
      );
      return;
    }
    const target = this.localPath(key);
    await mkdir(path.dirname(target), { recursive: true });
    await writeFile(target, body, { flag: 'wx' });
  }

  async get(key: string): Promise<Readable> {
    if (this.driver === 's3') {
      const res = await this.client().send(new GetObjectCommand({ Bucket: this.bucket, Key: key }));
      return res.Body as Readable;
    }
    return createReadStream(this.localPath(key));
  }

  private localPath(key: string): string {
    const target = path.resolve(this.dir, key);
    if (!target.startsWith(this.dir + path.sep)) throw new Error('Invalid storage key');
    return target;
  }

  onModuleInit() {
    this.logger.log(
      `Storage driver: ${this.driver}${this.driver === 'local' ? ` (${this.dir})` : ` (bucket ${this.bucket})`}`,
    );
  }
}
