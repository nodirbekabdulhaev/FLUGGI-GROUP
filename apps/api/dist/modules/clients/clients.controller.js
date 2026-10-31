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
var __param = (this && this.__param) || function (paramIndex, decorator) {
    return function (target, key) { decorator(target, key, paramIndex); }
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.ClientsController = void 0;
const common_1 = require("@nestjs/common");
const contracts_1 = require("@fluggi/contracts");
const decorators_1 = require("../../core/auth/decorators");
const uuid_pipe_1 = require("../../core/http/uuid.pipe");
const zod_pipe_1 = require("../../core/http/zod.pipe");
const clients_service_1 = require("./clients.service");
let ClientsController = class ClientsController {
    clients;
    constructor(clients) {
        this.clients = clients;
    }
    list(auth, q) {
        return this.clients.list(auth, q);
    }
    get(auth, id) {
        return this.clients.get(auth, id);
    }
    create(auth, body, meta) {
        return this.clients.create(auth, body, meta);
    }
    update(auth, id, body, meta) {
        return this.clients.update(auth, id, body, meta);
    }
    addContact(auth, id, body, meta) {
        return this.clients.addContact(auth, id, body, meta);
    }
    updateContact(auth, id, body, meta) {
        return this.clients.updateContact(auth, id, body, meta);
    }
    removeContact(auth, id, meta) {
        return this.clients.removeContact(auth, id, meta);
    }
};
exports.ClientsController = ClientsController;
__decorate([
    (0, common_1.Get)('clients'),
    (0, decorators_1.RequirePermission)('client.read'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Query)((0, zod_pipe_1.zod)(contracts_1.clientListQuerySchema))),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object]),
    __metadata("design:returntype", Promise)
], ClientsController.prototype, "list", null);
__decorate([
    (0, common_1.Get)('clients/:id'),
    (0, decorators_1.RequirePermission)('client.read'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String]),
    __metadata("design:returntype", Promise)
], ClientsController.prototype, "get", null);
__decorate([
    (0, common_1.Post)('clients'),
    (0, decorators_1.RequirePermission)('client.create'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.createClientSchema))),
    __param(2, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object, Object]),
    __metadata("design:returntype", Promise)
], ClientsController.prototype, "create", null);
__decorate([
    (0, common_1.Patch)('clients/:id'),
    (0, decorators_1.RequirePermission)('client.update'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.updateClientSchema))),
    __param(3, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object, Object]),
    __metadata("design:returntype", Promise)
], ClientsController.prototype, "update", null);
__decorate([
    (0, common_1.Post)('clients/:id/contacts'),
    (0, decorators_1.RequirePermission)('client.update'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.contactSchema))),
    __param(3, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object, Object]),
    __metadata("design:returntype", Promise)
], ClientsController.prototype, "addContact", null);
__decorate([
    (0, common_1.Put)('contacts/:id'),
    (0, decorators_1.RequirePermission)('client.update'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.contactSchema))),
    __param(3, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object, Object]),
    __metadata("design:returntype", Promise)
], ClientsController.prototype, "updateContact", null);
__decorate([
    (0, common_1.Delete)('contacts/:id'),
    (0, common_1.HttpCode)(204),
    (0, decorators_1.RequirePermission)('client.update'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object]),
    __metadata("design:returntype", Promise)
], ClientsController.prototype, "removeContact", null);
exports.ClientsController = ClientsController = __decorate([
    (0, common_1.Controller)(),
    __metadata("design:paramtypes", [clients_service_1.ClientsService])
], ClientsController);
//# sourceMappingURL=clients.controller.js.map