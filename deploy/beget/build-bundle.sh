#!/usr/bin/env bash
# Сборка готового архива для виртуального хостинга Beget: код + собранные api/web +
# node_modules для Linux x64. На хостинге ничего собирать не нужно (там мало памяти).
#
#   bash deploy/beget/build-bundle.sh [выходной.zip]
#
# Запускать на Linux x64 (нативные модули должны совпасть с сервером). На Mac:
#   docker run --rm --platform linux/amd64 -v "$PWD":/src -w /src node:22-bookworm \
#     bash -c 'corepack enable && bash deploy/beget/build-bundle.sh'
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
OUT="$(realpath -m "${1:-$ROOT/fluggi-beget.zip}")"
[ "$(uname -s)-$(uname -m)" = "Linux-x86_64" ] || { echo "Нужен Linux x86_64 (см. комментарий в скрипте)"; exit 1; }

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
echo "▸ Код из git (HEAD) → $STAGE/fluggi"
mkdir -p "$STAGE/fluggi"
git -C "$ROOT" archive HEAD | tar -x -C "$STAGE/fluggi"
cd "$STAGE/fluggi"

echo "▸ Зависимости (плоский node_modules)"
export PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 NEXT_TELEMETRY_DISABLED=1
pnpm install --frozen-lockfile --config.node-linker=hoisted >/dev/null

echo "▸ Сборка"
pnpm build >/dev/null

echo "▸ Чистка"
rm -rf apps/web/.next/cache .turbo apps/*/.turbo packages/*/.turbo e2e playwright.config.ts
rm -rf node_modules/@playwright node_modules/playwright node_modules/playwright-core
# Next.js использует только glibc-вариант компилятора
rm -rf node_modules/@next/swc-linux-x64-musl

echo "▸ Архив"
rm -f "$OUT"
(cd "$STAGE" && zip -qry "$OUT" fluggi)
echo "✓ $OUT ($(du -h "$OUT" | cut -f1))"
