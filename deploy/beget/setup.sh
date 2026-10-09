#!/usr/bin/env bash
# Первая настройка Fluggi CRM на виртуальном хостинге Beget (в Docker-окружении по SSH):
#
#   cd ~/crm.fluggi.uz/fluggi && bash deploy/beget/setup.sh
#
# Создаёт .env со случайными секретами, применяет миграции, создаёт первого CEO,
# пишет .htaccess для Passenger и печатает команду для cron.
# Повторный запуск безопасен: .env не перезаписывается.
set -euo pipefail

APP="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SITE="$(dirname "$APP")"
cd "$APP"

die() { printf '\n✗ %s\n' "$*" >&2; exit 1; }
ask() {
  local var=$1 prompt=$2
  if [ -z "${!var:-}" ]; then read -r -p "$prompt: " "$var" </dev/tty; fi
  [ -n "${!var:-}" ] || die "Не указано: $prompt"
}
q() { printf '"%s"' "${1//\"/\\\"}"; }

NODE="$(command -v node || true)"
[ -n "$NODE" ] || die "Node.js не найден. Установите Node.js 22 в ~/.local (инструкция: deploy/beget/README.md)"
MAJOR="$("$NODE" -p 'process.versions.node.split(".")[0]')"
[ "$MAJOR" -ge 22 ] || die "Нужен Node.js 22+, сейчас $("$NODE" -v)"
[ -f apps/api/dist/app.factory.js ] || die "Нет собранного кода — загрузите архив fluggi-beget.zip целиком"

CEO_PASSWORD=""
if [ ! -f .env ]; then
  echo "Ответьте на 4 вопроса."
  ask DOMAIN "Домен CRM (например crm.fluggi.uz)"
  ask DATABASE_URL "Строка подключения к облачной PostgreSQL Beget (postgresql://…)"
  ask CEO_EMAIL "Email первого CEO (логин)"
  ask CEO_NAME "Имя CEO"
  CEO_PASSWORD="$(openssl rand -base64 18 | tr -d '/+=' | cut -c1-16)"
  umask 077
  cat >.env <<EOF
NODE_ENV=production
TZ=Asia/Tashkent
APP_URL=https://$DOMAIN
DATABASE_URL=$(q "$DATABASE_URL")
AUTH_SECRET=$(openssl rand -base64 48 | tr -d '\n')
SESSION_TTL_DAYS=7
LOG_LEVEL=info
# Фоновые задачи: cron в панели Beget раз в минуту вызывает /api/v1/internal/cron с этим секретом
CRON_SECRET=$(openssl rand -hex 24)
SCHEDULER_ENABLED=true

SEED_DEMO=false
SEED_CEO_EMAIL=$(q "$CEO_EMAIL")
SEED_CEO_PASSWORD=$CEO_PASSWORD
SEED_CEO_NAME=$(q "$CEO_NAME")

# Telegram: впишите токен от @BotFather и выполните bash deploy/beget/telegram-webhook.sh
TELEGRAM_BOT_TOKEN=
TELEGRAM_BOT_USERNAME=
TELEGRAM_MODE=webhook
TELEGRAM_WEBHOOK_SECRET=$(openssl rand -hex 24)

META_APP_SECRET=
META_VERIFY_TOKEN=$(openssl rand -hex 16)
META_PAGE_ACCESS_TOKEN=

STORAGE_DRIVER=local
EOF
  umask 022
fi

set -a
# shellcheck disable=SC1091
. ./.env
set +a

echo "▸ Миграции базы"
"$NODE" node_modules/prisma/build/index.js migrate deploy --schema packages/db/prisma/schema.prisma
echo "▸ Роли, права, справочники, первый CEO"
"$NODE" node_modules/tsx/dist/cli.mjs packages/db/prisma/seed.ts
if [ -n "$CEO_PASSWORD" ]; then sed -i 's/^SEED_CEO_PASSWORD=.*/SEED_CEO_PASSWORD=/' .env; fi

echo "▸ Passenger: $SITE/public_html/.htaccess"
mkdir -p "$SITE/public_html" tmp storage
cat >"$SITE/public_html/.htaccess" <<EOF
PassengerEnabled on
PassengerAppType node
PassengerNodejs $NODE
PassengerAppRoot $APP
PassengerStartupFile deploy/beget/server.js
PassengerFriendlyErrorPages off
EOF
touch tmp/restart.txt

DOMAIN="${APP_URL#https://}"
echo
echo "✓ Готово. Откройте https://$DOMAIN (первый запуск — до 30 секунд)"
if [ -n "$CEO_PASSWORD" ]; then
  echo "  Логин:  $SEED_CEO_EMAIL"
  echo "  Пароль: $CEO_PASSWORD   ← сохраните и смените после входа, повторно не показывается"
fi
echo
echo "Добавьте задание в панели Beget → Cron, «каждую минуту»:"
echo "  curl -s -X POST -H 'x-cron-secret: $CRON_SECRET' https://$DOMAIN/api/v1/internal/cron >/dev/null"
