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
var OutboxRunner_1;
Object.defineProperty(exports, "__esModule", { value: true });
exports.OutboxRunner = void 0;
const common_1 = require("@nestjs/common");
const outbox_dispatcher_1 = require("./outbox.dispatcher");
const POLL_MS = 2000;
/**
 * Обработка outbox внутри API-процесса — чтобы при разработке не нужно было
 * запускать отдельный worker. В production включён отдельный worker, а здесь
 * цикл выключается переменной OUTBOX_IN_API=false. Оба варианта безопасны вместе
 * (строки блокируются FOR UPDATE SKIP LOCKED).
 */
let OutboxRunner = OutboxRunner_1 = class OutboxRunner {
    dispatcher;
    logger = new common_1.Logger(OutboxRunner_1.name);
    stopped = false;
    timer;
    constructor(dispatcher) {
        this.dispatcher = dispatcher;
    }
    onApplicationBootstrap() {
        if (process.env.OUTBOX_IN_API === 'false')
            return;
        this.schedule(POLL_MS);
    }
    onApplicationShutdown() {
        this.stopped = true;
        if (this.timer)
            clearTimeout(this.timer);
    }
    schedule(ms) {
        if (this.stopped)
            return;
        this.timer = setTimeout(() => void this.tick(), ms);
    }
    async tick() {
        try {
            const n = await this.dispatcher.processBatch();
            this.schedule(n > 0 ? 0 : POLL_MS);
        }
        catch (err) {
            this.logger.error(err, 'Outbox batch failed');
            this.schedule(POLL_MS * 5);
        }
    }
};
exports.OutboxRunner = OutboxRunner;
exports.OutboxRunner = OutboxRunner = OutboxRunner_1 = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [outbox_dispatcher_1.OutboxDispatcher])
], OutboxRunner);
//# sourceMappingURL=outbox.runner.js.map