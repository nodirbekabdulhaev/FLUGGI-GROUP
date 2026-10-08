#!/usr/bin/env bash
# Подключение Telegram-бота: впишите TELEGRAM_BOT_TOKEN в /opt/fluggi/.env, затем
#
#   sudo bash deploy/telegram-webhook.sh
set -euo pipefail

APP_DIR=/opt/fluggi
cd "$APP_DIR"
set -a
# shellcheck disable=SC1091
. ./.env
set +a
[ -n "${TELEGRAM_BOT_TOKEN:-}" ] || { echo "Сначала впишите TELEGRAM_BOT_TOKEN в $APP_DIR/.env"; exit 1; }

docker compose -f docker-compose.prod.yml up -d api worker
curl -fsS "https://api.telegram.org/bot$TELEGRAM_BOT_TOKEN/setWebhook" \
  -d url="$APP_URL/api/v1/telegram/webhook" -d secret_token="$TELEGRAM_WEBHOOK_SECRET" >/dev/null
echo "✓ Webhook установлен:"
curl -fsS "https://api.telegram.org/bot$TELEGRAM_BOT_TOKEN/getWebhookInfo"
echo
