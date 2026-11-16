# Один образ для api, worker и web (команда задаётся в docker-compose.prod.yml).
FROM node:22-bookworm-slim AS base
ENV PNPM_HOME=/pnpm PATH=/pnpm:$PATH NEXT_TELEMETRY_DISABLED=1
RUN apt-get update && apt-get install -y --no-install-recommends openssl ca-certificates \
  && rm -rf /var/lib/apt/lists/* && corepack enable
WORKDIR /app

FROM base AS build
COPY pnpm-lock.yaml pnpm-workspace.yaml package.json ./
COPY apps/api/package.json apps/api/
COPY apps/web/package.json apps/web/
COPY packages/contracts/package.json packages/contracts/
COPY packages/db/package.json packages/db/
COPY packages/domain/package.json packages/domain/
RUN pnpm install --frozen-lockfile
COPY . .
# Адрес API внутри docker-сети: Next.js запоминает его в rewrites при сборке
ENV API_INTERNAL_URL=http://api:4000
RUN pnpm build

FROM base AS runtime
ENV NODE_ENV=production TZ=Asia/Tashkent
COPY --from=build /app /app
# Next.js пишет кэш в .next/cache во время работы.
RUN chown -R node:node /app/apps/web/.next && mkdir -p /data/storage && chown node:node /data/storage
USER node
EXPOSE 3000 4000
CMD ["node", "apps/api/dist/main.js"]
