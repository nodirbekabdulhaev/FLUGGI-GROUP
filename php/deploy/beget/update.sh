#!/usr/bin/env bash
# Обновление с GitHub (ветка hosting — её собирает GitHub Actions после каждого изменения):
#
#   cd ~/crm.fluggi.uz/fluggi && bash deploy/beget/update.sh
#
# .env, загруженные файлы и логи (storage/) не затрагиваются.
set -euo pipefail

APP="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$APP"
[ -f .env ] || { echo "Сначала выполните bash deploy/beget/setup.sh"; exit 1; }

if [ -z "${FLUGGI_UPDATED:-}" ]; then
  echo "▸ Загрузка новой версии с GitHub"
  git fetch -q --depth 1 origin hosting
  git reset -q --hard FETCH_HEAD
  git log -1 --format='  %s (%cr)'
  # Дальше — уже обновлённым скриптом
  FLUGGI_UPDATED=1 exec bash deploy/beget/update.sh
fi

PHP="$(bash deploy/beget/find-php.sh)"
"$PHP" artisan down --retry=15 -q || true
"$PHP" artisan fluggi:install
"$PHP" artisan optimize:clear -q
"$PHP" artisan optimize -q
"$PHP" artisan up -q
echo "✓ Обновлено"
