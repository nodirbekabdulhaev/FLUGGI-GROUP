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
exports.MeetingsController = void 0;
const common_1 = require("@nestjs/common");
const contracts_1 = require("@fluggi/contracts");
const decorators_1 = require("../../core/auth/decorators");
const uuid_pipe_1 = require("../../core/http/uuid.pipe");
const zod_pipe_1 = require("../../core/http/zod.pipe");
const meetings_service_1 = require("./meetings.service");
let MeetingsController = class MeetingsController {
    meetings;
    constructor(meetings) {
        this.meetings = meetings;
    }
    list(auth, q) {
        return this.meetings.list(auth, q);
    }
    create(auth, body, meta) {
        return this.meetings.create(auth, body, meta);
    }
    update(auth, id, body, meta) {
        return this.meetings.update(auth, id, body, meta);
    }
    complete(auth, id, body, meta) {
        return this.meetings.complete(auth, id, body, meta);
    }
};
exports.MeetingsController = MeetingsController;
__decorate([
    (0, common_1.Get)(),
    (0, decorators_1.RequirePermission)('meeting.read'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Query)((0, zod_pipe_1.zod)(contracts_1.meetingListQuerySchema))),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object]),
    __metadata("design:returntype", Promise)
], MeetingsController.prototype, "list", null);
__decorate([
    (0, common_1.Post)(),
    (0, decorators_1.RequirePermission)('meeting.create'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.createMeetingSchema))),
    __param(2, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object, Object]),
    __metadata("design:returntype", Promise)
], MeetingsController.prototype, "create", null);
__decorate([
    (0, common_1.Patch)(':id'),
    (0, decorators_1.RequirePermission)('meeting.update'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.updateMeetingSchema))),
    __param(3, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object, Object]),
    __metadata("design:returntype", Promise)
], MeetingsController.prototype, "update", null);
__decorate([
    (0, common_1.Post)(':id/complete'),
    (0, common_1.HttpCode)(200),
    (0, decorators_1.RequirePermission)('meeting.update'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.completeMeetingSchema))),
    __param(3, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object, Object]),
    __metadata("design:returntype", Promise)
], MeetingsController.prototype, "complete", null);
exports.MeetingsController = MeetingsController = __decorate([
    (0, common_1.Controller)('meetings'),
    __metadata("design:paramtypes", [meetings_service_1.MeetingsService])
], MeetingsController);
//# sourceMappingURL=meetings.controller.js.map