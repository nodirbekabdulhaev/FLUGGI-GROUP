#!/usr/bin/env bash
# Обновление CRM новой версией кода (из распакованного архива или git).
#
#   sudo bash deploy/update.sh
#
# Перед обновлением делает бэкап базы. Миграции применяются автоматически (сервис migrate).
set -euo pipefail

APP_DIR=/opt/fluggi
BACKUP_DIR=/var/backups/fluggi
SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE="docker compose -f $APP_DIR/docker-compose.prod.yml"

[ "$(id -u)" -eq 0 ] || { echo "Запустите через sudo"; exit 1; }
[ -f "$APP_DIR/.env" ] || { echo "CRM ещё не установлена — запустите deploy/install.sh"; exit 1; }

cd "$APP_DIR"
mkdir -p "$BACKUP_DIR"
echo "▸ Бэкап базы перед обновлением"
$COMPOSE exec -T mysql sh -c 'exec mysqldump --single-transaction --triggers -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' | gzip >"$BACKUP_DIR/fluggi-before-update-$(date +%F-%H%M).sql.gz"

if [ "$SRC_DIR" != "$APP_DIR" ]; then
  echo "▸ Копирую новый код"
  rsync -a --delete --exclude node_modules --exclude .env --exclude '.next' --exclude dist \
    "$SRC_DIR/" "$APP_DIR/"
elif [ -d .git ]; then
  echo "▸ git pull"
  git pull --ff-only
fi

echo "▸ Сборка и перезапуск"
$COMPOSE up -d --build --remove-orphans

for _ in $(seq 1 60); do
  curl -fsS http://127.0.0.1:4000/api/v1/ready >/dev/null 2>&1 && break
  sleep 5
done
if curl -fsS http://127.0.0.1:4000/api/v1/ready >/dev/null; then
  docker image prune -f >/dev/null
  echo "✓ Обновлено"
else
  $COMPOSE logs --tail 50 migrate api
  echo "✗ API не запустился — пришлите вывод выше"
  exit 1
fi
