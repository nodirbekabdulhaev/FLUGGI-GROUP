"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
const app_factory_1 = require("./app.factory");
const env_1 = require("./config/env");
async function bootstrap() {
    const app = await (0, app_factory_1.createApp)();
    await app.listen((0, env_1.loadEnv)().PORT);
}
void bootstrap();
//# sourceMappingURL=main.js.map