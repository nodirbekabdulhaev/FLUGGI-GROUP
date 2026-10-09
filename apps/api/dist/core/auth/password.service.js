"use strict";
var __decorate = (this && this.__decorate) || function (decorators, target, key, desc) {
    var c = arguments.length, r = c < 3 ? target : desc === null ? desc = Object.getOwnPropertyDescriptor(target, key) : desc, d;
    if (typeof Reflect === "object" && typeof Reflect.decorate === "function") r = Reflect.decorate(decorators, target, key, desc);
    else for (var i = decorators.length - 1; i >= 0; i--) if (d = decorators[i]) r = (c < 3 ? d(r) : c > 3 ? d(target, key, r) : d(target, key)) || r;
    return c > 3 && r && Object.defineProperty(target, key, r), r;
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.PasswordService = void 0;
const node_crypto_1 = require("node:crypto");
const common_1 = require("@nestjs/common");
const argon2_1 = require("@node-rs/argon2");
// Параметры argon2id по рекомендации OWASP (19 MiB, 2 итерации).
const OPTIONS = { memoryCost: 19456, timeCost: 2, parallelism: 1 };
let PasswordService = class PasswordService {
    /** Хэш для выравнивания времени ответа, когда пользователь не найден. */
    dummyHash;
    hash(password) {
        return (0, argon2_1.hash)(password, OPTIONS);
    }
    async verify(passwordHash, password) {
        if (!passwordHash) {
            this.dummyHash ??= (0, argon2_1.hash)('dummy-password-for-timing', OPTIONS);
            await (0, argon2_1.verify)(await this.dummyHash, password).catch(() => false);
            return false;
        }
        return (0, argon2_1.verify)(passwordHash, password).catch(() => false);
    }
    /** Временный пароль: 14 символов, гарантированно буквы и цифры. */
    generateTemporary() {
        const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        const bytes = (0, node_crypto_1.randomBytes)(12);
        let body = '';
        for (const b of bytes)
            body += alphabet[b % alphabet.length];
        return `${body}${2 + (bytes[0] % 8)}x`;
    }
};
exports.PasswordService = PasswordService;
exports.PasswordService = PasswordService = __decorate([
    (0, common_1.Injectable)()
], PasswordService);
//# sourceMappingURL=password.service.js.map