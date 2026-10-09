#!/usr/bin/env bash
# Сборка архива для виртуального хостинга Beget: исходники + собранные api/web, без node_modules
# (зависимости ставит на хостинге deploy/beget/install-deps.sh — архив получается маленьким).
# На хостинге ничего собирать не нужно: там мало памяти для сборки Next.js.
#
#   bash deploy/beget/build-bundle.sh [выходной.zip]
#
# Работает на Linux и macOS (нативных модулей в архиве нет).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
OUT="${1:-$ROOT/fluggi-beget.zip}"
case "$OUT" in /*) ;; *) OUT="$PWD/$OUT" ;; esac

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
echo "▸ Код из git (HEAD)"
mkdir -p "$STAGE/fluggi"
git -C "$ROOT" archive HEAD | tar -x -C "$STAGE/fluggi"
cd "$STAGE/fluggi"

echo "▸ Сборка"
export PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 NEXT_TELEMETRY_DISABLED=1
pnpm install --frozen-lockfile >/dev/null
pnpm build >/dev/null

echo "▸ Чистка"
find . -name node_modules -type d -prune -exec rm -rf {} +
rm -rf apps/web/.next/cache .turbo apps/*/.turbo packages/*/.turbo e2e playwright.config.ts
find . -name '*.tsbuildinfo' -delete

echo "▸ Архив"
rm -f "$OUT"
(cd "$STAGE" && zip -qr "$OUT" fluggi)
echo "✓ $OUT ($(du -h "$OUT" | cut -f1))"
