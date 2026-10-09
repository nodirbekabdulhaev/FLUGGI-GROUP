#!/usr/bin/env bash
# Установка зависимостей на хостинге (только для работы, без инструментов сборки)
# и генерация клиента Prisma под сервер. Вызывается из setup.sh и update.sh.
set -euo pipefail

APP="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$APP"
PNPM_VERSION="$(sed -n 's/.*"packageManager": "pnpm@\([0-9.]*\)".*/\1/p' package.json)"

echo "▸ Зависимости (pnpm $PNPM_VERSION, может занять несколько минут)"
npx -y "pnpm@$PNPM_VERSION" install --frozen-lockfile --prod --config.node-linker=hoisted
echo "▸ Клиент базы данных (Prisma)"
node node_modules/prisma/build/index.js generate --schema packages/db/prisma/schema.prisma >/dev/null
