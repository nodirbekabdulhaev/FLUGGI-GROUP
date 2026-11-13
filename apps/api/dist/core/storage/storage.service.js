"use strict";
var __decorate = (this && this.__decorate) || function (decorators, target, key, desc) {
    var c = arguments.length, r = c < 3 ? target : desc === null ? desc = Object.getOwnPropertyDescriptor(target, key) : desc, d;
    if (typeof Reflect === "object" && typeof Reflect.decorate === "function") r = Reflect.decorate(decorators, target, key, desc);
    else for (var i = decorators.length - 1; i >= 0; i--) if (d = decorators[i]) r = (c < 3 ? d(r) : c > 3 ? d(target, key, r) : d(target, key)) || r;
    return c > 3 && r && Object.defineProperty(target, key, r), r;
};
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
var StorageService_1;
Object.defineProperty(exports, "__esModule", { value: true });
exports.StorageService = void 0;
const node_crypto_1 = require("node:crypto");
const node_fs_1 = require("node:fs");
const promises_1 = require("node:fs/promises");
const node_path_1 = __importDefault(require("node:path"));
const client_s3_1 = require("@aws-sdk/client-s3");
const common_1 = require("@nestjs/common");
/**
 * Хранилище файлов (ТЗ §52). Файлы — вне PostgreSQL:
 *  - STORAGE_DRIVER=local (по умолчанию в разработке) — папка STORAGE_LOCAL_DIR;
 *  - STORAGE_DRIVER=s3 — любое S3-совместимое хранилище (Beget S3, MinIO, AWS).
 * Ключи генерируются сервером, имя пользователя в путь не попадает (нет path traversal).
 */
let StorageService = StorageService_1 = class StorageService {
    logger = new common_1.Logger(StorageService_1.name);
    driver = process.env.STORAGE_DRIVER === 's3' ? 's3' : 'local';
    dir = node_path_1.default.resolve(process.env.STORAGE_LOCAL_DIR || node_path_1.default.join(process.cwd(), 'storage'));
    bucket = process.env.STORAGE_BUCKET ?? '';
    s3;
    client() {
        this.s3 ??= new client_s3_1.S3Client({
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
    newKey(prefix, ext) {
        const d = new Date();
        return `${prefix}/${d.getUTCFullYear()}/${String(d.getUTCMonth() + 1).padStart(2, '0')}/${(0, node_crypto_1.randomUUID)()}.${ext}`;
    }
    checksum(buf) {
        return (0, node_crypto_1.createHash)('sha256').update(buf).digest('hex');
    }
    async put(key, body, contentType) {
        if (this.driver === 's3') {
            await this.client().send(new client_s3_1.PutObjectCommand({
                Bucket: this.bucket,
                Key: key,
                Body: body,
                ContentType: contentType,
            }));
            return;
        }
        const target = this.localPath(key);
        await (0, promises_1.mkdir)(node_path_1.default.dirname(target), { recursive: true });
        await (0, promises_1.writeFile)(target, body, { flag: 'wx' });
    }
    async get(key) {
        if (this.driver === 's3') {
            const res = await this.client().send(new client_s3_1.GetObjectCommand({ Bucket: this.bucket, Key: key }));
            return res.Body;
        }
        return (0, node_fs_1.createReadStream)(this.localPath(key));
    }
    localPath(key) {
        const target = node_path_1.default.resolve(this.dir, key);
        if (!target.startsWith(this.dir + node_path_1.default.sep))
            throw new Error('Invalid storage key');
        return target;
    }
    onModuleInit() {
        this.logger.log(`Storage driver: ${this.driver}${this.driver === 'local' ? ` (${this.dir})` : ` (bucket ${this.bucket})`}`);
    }
};
exports.StorageService = StorageService;
exports.StorageService = StorageService = StorageService_1 = __decorate([
    (0, common_1.Injectable)()
], StorageService);
//# sourceMappingURL=storage.service.js.map