#!/usr/bin/env bash
# Сборка PHP-версии для виртуального хостинга: код + vendor (без dev-пакетов) + собранные стили и скрипты.
# На хостинге не нужны ни Node.js, ни composer.
#
#   bash php/deploy/build.sh /tmp/fluggi        # из корня репозитория
set -euo pipefail

OUT="${1:?Укажите папку для сборки}"
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ROOT="$(git -C "$SRC" rev-parse --show-toplevel)"

rm -rf "$OUT" && mkdir -p "$OUT"
# Только файлы из git (без .env, vendor, node_modules и локальных данных)
git -C "$ROOT" archive HEAD php | tar -x -C "$OUT" --strip-components=1

cd "$OUT"
composer install --no-dev --optimize-autoloader --no-interaction --no-progress --prefer-dist
npm ci --no-audit --no-fund
npm run build
rm -rf node_modules tests phpunit.xml .github vite.config.js tailwind.config.js postcss.config.js \
  package.json package-lock.json resources/css resources/js CONVENTIONS.md
mkdir -p storage/app/private storage/framework/{cache/data,sessions,views} storage/logs bootstrap/cache

# Что не должно попасть в ветку и затираться обновлением: настройки и данные хостинга
cat >.gitignore <<'IGN'
/.env
/.env.backup
/storage/app/*
!/storage/app/.gitignore
/storage/framework/cache/data/*
/storage/framework/sessions/*
/storage/framework/views/*
/storage/logs/*
/bootstrap/cache/*.php
/public/storage
IGN
for d in storage/app storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache; do
  touch "$d/.gitkeep"
done
echo "✓ Сборка: $OUT ($(du -sh . | cut -f1))"
