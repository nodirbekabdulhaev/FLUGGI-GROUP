#!/usr/bin/env bash
# Production deploy on Beget (SSH) or any shared hosting with SSH + composer.
# Usage:  bash bin/deploy.sh            (first time:  bash bin/deploy.sh --install)
set -euo pipefail
cd "$(dirname "$0")/.."

PHP="${PHP:-php}"
COMPOSER="${COMPOSER:-composer}"

[ -f .env ] || { echo "Create .env first (cp .env.example .env) and fill in DB_* and APP_URL"; exit 1; }

$COMPOSER install --no-dev --optimize-autoloader --no-interaction
grep -q '^APP_KEY=base64' .env || $PHP artisan key:generate --force

$PHP artisan down --retry=30 || true
$PHP artisan migrate --force
$PHP artisan config:cache
$PHP artisan route:cache
$PHP artisan view:cache
$PHP artisan up

if [ "${1:-}" = "--install" ]; then
    $PHP artisan erp:install          # asks for organization name, admin e-mail and password
fi

chmod -R u+rwX storage bootstrap/cache
echo "Done. Add the cron job (see docs/DEPLOY_BEGET.md):  * * * * * $PHP $(pwd)/artisan schedule:run"
