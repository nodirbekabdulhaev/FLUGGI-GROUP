#!/usr/bin/env bash
# Обновление с GitHub (ветка beget — её собирает GitHub Actions после каждого изменения):
#
#   cd ~/crm.fluggi.uz/fluggi && bash deploy/beget/pull-update.sh
#
# Ветка beget перезаписывается каждой сборкой, поэтому берём её целиком (fetch + reset).
# .env, storage/, tmp/ и node_modules не в git и не затрагиваются.
set -euo pipefail

APP="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$APP"
[ -d .git ] || { echo "Папка установлена не через git clone — см. deploy/beget/README.md"; exit 1; }

echo "▸ Загрузка новой версии с GitHub"
git fetch -q --depth 1 origin beget
git reset -q --hard FETCH_HEAD
git log -1 --format='  %s (%cr)'
# Дальше — уже обновлённым скриптом
exec bash deploy/beget/update.sh
