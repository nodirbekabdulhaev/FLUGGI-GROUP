#!/usr/bin/env bash
# Первая установка Fluggi CRM на виртуальный хостинг Beget (PHP + MySQL).
#
#   cd ~/crm.fluggi.uz && git clone --depth 1 -b hosting https://github.com/nodirbekabdulhaev/FLUGGI-GROUP.git fluggi
#   cd fluggi && bash deploy/beget/setup.sh
#
# Спросит домен, базу MySQL, email и имя CEO. Создаст .env со случайными секретами, таблицы,
# справочники, первого CEO (пароль покажет один раз), направит сайт в папку public/
# и напечатает команду для cron. Повторный запуск безопасен: .env не перезаписывается.
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
q() { printf '"%s"' "$(printf '%s' "$1" | sed 's/[\\"$`]/\\&/g')"; }

PHP="$(bash deploy/beget/find-php.sh)" || die "Нужен PHP 8.2 или новее (на Beget: php8.2 / php8.3)"
echo "▸ PHP: $PHP ($("$PHP" -r 'echo PHP_VERSION;'))"
for ext in pdo_mysql mbstring openssl json fileinfo tokenizer xml ctype zip gd; do
  "$PHP" -r "exit(extension_loaded('$ext') ? 0 : 1);" || die "В PHP нет расширения $ext — включите его в панели Beget"
done

CEO_EMAIL="${CEO_EMAIL:-}"
if [ ! -f .env ]; then
  echo "Ответьте на вопросы (данные MySQL — панель Beget → «MySQL»)."
  ask DOMAIN "Домен CRM (например crm.fluggi.uz)"
  ask DB_NAME "Имя базы MySQL (например логин_crm)"
  DB_USER="${DB_USER:-$DB_NAME}"
  ask DB_PASSWORD "Пароль базы MySQL"
  ask CEO_EMAIL "Email первого CEO (логин)"
  ask CEO_NAME "Имя CEO"
  umask 077
  cat >.env <<ENV
APP_NAME="Fluggi CRM"
APP_ENV=production
APP_KEY=base64:$(openssl rand -base64 32)
APP_DEBUG=false
APP_URL=https://$DOMAIN
APP_TIMEZONE=UTC
COMPANY_TIMEZONE=Asia/Tashkent
APP_LOCALE=ru
APP_FALLBACK_LOCALE=ru
LOG_CHANNEL=daily
LOG_LEVEL=info

DB_CONNECTION=mysql
DB_HOST=${DB_HOST:-localhost}
DB_PORT=${DB_PORT:-3306}
DB_DATABASE=$(q "$DB_NAME")
DB_USERNAME=$(q "$DB_USER")
DB_PASSWORD=$(q "$DB_PASSWORD")

SESSION_TTL_DAYS=7
SESSION_DRIVER=file
SESSION_LIFETIME=10080
SESSION_SECURE_COOKIE=true
CACHE_STORE=file
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=local

# Telegram: токен от @BotFather, затем php artisan fluggi:telegram-webhook
TELEGRAM_BOT_TOKEN=
TELEGRAM_BOT_USERNAME=
TELEGRAM_WEBHOOK_SECRET=$(openssl rand -hex 24)

META_APP_SECRET=
META_VERIFY_TOKEN=$(openssl rand -hex 16)
META_PAGE_ACCESS_TOKEN=

CRON_SECRET=$(openssl rand -hex 24)
ENV
  umask 022
fi

chmod -R u+rwX storage bootstrap/cache
echo "▸ Таблицы, справочники, защита данных"
if [ -n "$CEO_EMAIL" ]; then
  "$PHP" artisan fluggi:install --ceo-email="$CEO_EMAIL" --ceo-name="${CEO_NAME:-CEO}"
else
  "$PHP" artisan fluggi:install
fi
"$PHP" artisan storage:link -q 2>/dev/null || true
"$PHP" artisan optimize -q

# Сайт должен смотреть в папку public/ — остальное (код, .env) недоступно из интернета
DOCROOT="${DOCROOT:-$SITE/public_html}"
if [ -L "$DOCROOT" ]; then
  ln -sfn "$APP/public" "$DOCROOT"
elif [ -d "$DOCROOT" ]; then
  mv "$DOCROOT" "$DOCROOT.beget-bak" && ln -s "$APP/public" "$DOCROOT"
  echo "  прежняя папка сайта сохранена: $(basename "$DOCROOT").beget-bak"
else
  ln -s "$APP/public" "$DOCROOT"
fi
echo "▸ Корень сайта: $DOCROOT → $APP/public"

DOMAIN="$(sed -n 's#^APP_URL=https\?://##p' .env)"
echo
echo "✓ Готово. Откройте https://$DOMAIN"
echo
echo "Добавьте задание в панели Beget → «Cron» (каждую минуту):"
echo "  $PHP $APP/artisan schedule:run >/dev/null 2>&1"
