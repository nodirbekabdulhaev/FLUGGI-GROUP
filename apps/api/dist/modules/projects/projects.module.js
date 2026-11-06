"use strict";
var __decorate = (this && this.__decorate) || function (decorators, target, key, desc) {
    var c = arguments.length, r = c < 3 ? target : desc === null ? desc = Object.getOwnPropertyDescriptor(target, key) : desc, d;
    if (typeof Reflect === "object" && typeof Reflect.decorate === "function") r = Reflect.decorate(decorators, target, key, desc);
    else for (var i = decorators.length - 1; i >= 0; i--) if (d = decorators[i]) r = (c < 3 ? d(r) : c > 3 ? d(target, key, r) : d(target, key)) || r;
    return c > 3 && r && Object.defineProperty(target, key, r), r;
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.ProjectsModule = void 0;
const common_1 = require("@nestjs/common");
const project_access_service_1 = require("./project-access.service");
const projects_controller_1 = require("./projects.controller");
const projects_service_1 = require("./projects.service");
const tasks_controller_1 = require("./tasks.controller");
const tasks_service_1 = require("./tasks.service");
const templates_controller_1 = require("./templates.controller");
const templates_service_1 = require("./templates.service");
let ProjectsModule = class ProjectsModule {
};
exports.ProjectsModule = ProjectsModule;
exports.ProjectsModule = ProjectsModule = __decorate([
    (0, common_1.Module)({
        controllers: [projects_controller_1.ProjectsController, tasks_controller_1.TasksController, templates_controller_1.TemplatesController],
        providers: [project_access_service_1.ProjectAccessService, projects_service_1.ProjectsService, tasks_service_1.TasksService, templates_service_1.TemplatesService],
        exports: [project_access_service_1.ProjectAccessService, templates_service_1.TemplatesService],
    })
], ProjectsModule);
//# sourceMappingURL=projects.module.js.map