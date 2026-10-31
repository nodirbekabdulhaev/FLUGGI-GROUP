#!/usr/bin/env bash
# Обновление на виртуальном хостинге Beget после распаковки нового fluggi-beget.zip:
#
#   cd ~/crm.fluggi.uz && unzip -o fluggi-beget.zip && cd fluggi && bash deploy/beget/update.sh
#
# .env, загруженные файлы (storage/) и tmp/ архив не затрагивает.
set -euo pipefail

APP="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$APP"
[ -f .env ] || { echo "Сначала выполните bash deploy/beget/setup.sh"; exit 1; }
NODE="$(command -v node)"

set -a
# shellcheck disable=SC1091
. ./.env
set +a

bash deploy/beget/install-deps.sh
echo "▸ Миграции базы"
"$NODE" node_modules/prisma/build/index.js migrate deploy --schema packages/db/prisma/schema.prisma
echo "▸ Защита данных (триггеры)"
"$NODE" node_modules/prisma/build/index.js db execute --schema packages/db/prisma/schema.prisma \
  --file packages/db/prisma/protect.sql >/dev/null 2>&1 ||
  echo "  ⚠ хостинг не разрешает триггеры — CRM работает и без них (записи защищает приложение)"
echo "▸ Справочники и права"
"$NODE" node_modules/tsx/dist/cli.mjs packages/db/prisma/seed.ts
mkdir -p tmp && touch tmp/restart.txt
echo "✓ Обновлено. Приложение перезапустится при следующем запросе."
