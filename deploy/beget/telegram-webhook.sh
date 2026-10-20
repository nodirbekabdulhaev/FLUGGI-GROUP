#!/usr/bin/env bash
# Подключение Telegram-бота на Beget: впишите TELEGRAM_BOT_TOKEN в .env, затем
#
#   bash deploy/beget/telegram-webhook.sh
set -euo pipefail

APP="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$APP"
set -a
# shellcheck disable=SC1091
. ./.env
set +a
[ -n "${TELEGRAM_BOT_TOKEN:-}" ] || { echo "Сначала впишите TELEGRAM_BOT_TOKEN в $APP/.env"; exit 1; }

mkdir -p tmp && touch tmp/restart.txt
curl -fsS "https://api.telegram.org/bot$TELEGRAM_BOT_TOKEN/setWebhook" \
  -d url="$APP_URL/api/v1/telegram/webhook" -d secret_token="$TELEGRAM_WEBHOOK_SECRET" >/dev/null
echo "✓ Webhook установлен:"
curl -fsS "https://api.telegram.org/bot$TELEGRAM_BOT_TOKEN/getWebhookInfo"
echo
