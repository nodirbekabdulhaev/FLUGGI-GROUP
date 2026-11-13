"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.createApp = createApp;
require("reflect-metadata");
const core_1 = require("@nestjs/core");
const cookie_parser_1 = __importDefault(require("cookie-parser"));
const helmet_1 = __importDefault(require("helmet"));
const nestjs_pino_1 = require("nestjs-pino");
const app_module_1 = require("./app.module");
const env_1 = require("./config/env");
async function createApp() {
    const env = (0, env_1.loadEnv)();
    const app = await core_1.NestFactory.create(app_module_1.AppModule, {
        bufferLogs: true,
        // Подпись webhook Meta проверяется по исходному телу запроса
        rawBody: true,
    });
    app.useLogger(app.get(nestjs_pino_1.Logger));
    const trustProxy = /^\d+$/.test(env.TRUST_PROXY) ? Number(env.TRUST_PROXY) : env.TRUST_PROXY;
    app.set('trust proxy', trustProxy);
    app.disable('x-powered-by');
    app.use((0, helmet_1.default)());
    app.use((0, cookie_parser_1.default)());
    // Формы для сайта: браузер посетителя на чужом домене (WordPress) может отправлять заявки
    app.use('/api/v1/public/forms', (req, res, next) => {
        res.setHeader('Access-Control-Allow-Origin', '*');
        res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
        res.setHeader('Cross-Origin-Resource-Policy', 'cross-origin');
        if (req.method === 'OPTIONS')
            return void res.status(204).end();
        next();
    });
    app.useBodyParser('json', { limit: '1mb' });
    app.useBodyParser('urlencoded', { extended: false, limit: '100kb' });
    app.setGlobalPrefix('api/v1');
    app.enableShutdownHooks();
    return app;
}
//# sourceMappingURL=app.factory.js.map