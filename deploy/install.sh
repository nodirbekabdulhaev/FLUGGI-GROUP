#!/usr/bin/env bash
# Установка Fluggi CRM на чистый сервер Ubuntu 22.04/24.04 (Oracle Cloud, Hetzner, Beget VPS…).
#
#   sudo bash deploy/install.sh
#
# Скрипт можно запускать повторно: уже сделанные шаги пропускаются, .env не перезаписывается.
# Делает: файрвол, swap, Docker, Caddy (HTTPS Let's Encrypt), .env со случайными секретами,
# сборку и запуск CRM, первого CEO, ежедневный бэкап базы.
set -euo pipefail

APP_DIR=/opt/fluggi
BACKUP_DIR=/var/backups/fluggi
SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE="docker compose -f $APP_DIR/docker-compose.prod.yml"

say() { printf '\n\033[1;36m▸ %s\033[0m\n' "$*"; }
die() { printf '\n\033[1;31m✗ %s\033[0m\n' "$*" >&2; exit 1; }
ask() { # ask VAR "Вопрос" — читает с терминала, если переменная не задана заранее
  local var=$1 prompt=$2
  if [ -z "${!var:-}" ]; then read -r -p "$prompt: " "$var" </dev/tty; fi
  [ -n "${!var:-}" ] || die "Не указано: $prompt"
}

[ "$(id -u)" -eq 0 ] || die "Запустите через sudo: sudo bash deploy/install.sh"
[ -f "$SRC_DIR/docker-compose.prod.yml" ] || die "Запускайте из папки проекта (нет docker-compose.prod.yml)"

ENV_FILE="$APP_DIR/.env"
if [ ! -f "$ENV_FILE" ]; then
  echo "Ответьте на 3 вопроса (или задайте DOMAIN, CEO_EMAIL, CEO_NAME заранее)."
  ask DOMAIN "Домен CRM, например crm.fluggi.uz"
  ask CEO_EMAIL "Email первого CEO (логин)"
  ask CEO_NAME "Имя CEO"
else
  DOMAIN=$(sed -n 's#^APP_URL=https\?://##p' "$ENV_FILE")
fi

say "Пакеты системы"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq ca-certificates curl gnupg rsync openssl cron >/dev/null

say "Файрвол: открываю 80 и 443"
# В образах Ubuntu от Oracle входящие соединения закрыты правилом REJECT в iptables
if iptables -S INPUT 2>/dev/null | grep -q -- '-j REJECT'; then
  for port in 80 443; do
    iptables -C INPUT -p tcp --dport $port -j ACCEPT 2>/dev/null ||
      iptables -I INPUT -p tcp --dport $port -j ACCEPT
  done
  if command -v netfilter-persistent >/dev/null; then netfilter-persistent save >/dev/null; fi
fi
if command -v ufw >/dev/null && ufw status | grep -q 'Status: active'; then
  ufw allow 80/tcp >/dev/null && ufw allow 443/tcp >/dev/null
fi

say "Swap (нужен для сборки на серверах с небольшой памятью)"
MEM_MB=$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo)
if [ "$MEM_MB" -lt 6000 ] && ! swapon --show | grep -q .; then
  fallocate -l 4G /swapfile && chmod 600 /swapfile && mkswap /swapfile >/dev/null && swapon /swapfile
  grep -q '^/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >>/etc/fstab
  echo "Добавлен swap 4 ГБ"
else
  echo "Не нужен (память ${MEM_MB} МБ или swap уже есть)"
fi

say "Docker"
if ! command -v docker >/dev/null; then
  curl -fsSL https://get.docker.com | sh >/dev/null
fi
systemctl enable --now docker >/dev/null

say "Caddy (веб-сервер с автоматическим HTTPS)"
if ! command -v caddy >/dev/null; then
  curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' |
    gpg --dearmor --yes -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
  curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' \
    >/etc/apt/sources.list.d/caddy-stable.list
  apt-get update -qq && apt-get install -y -qq caddy >/dev/null
fi

say "Код приложения → $APP_DIR"
mkdir -p "$APP_DIR"
if [ "$SRC_DIR" != "$APP_DIR" ]; then
  rsync -a --delete --exclude node_modules --exclude .env --exclude '.next' --exclude dist \
    "$SRC_DIR/" "$APP_DIR/"
fi

CEO_PASSWORD=""
if [ ! -f "$ENV_FILE" ]; then
  say "Создаю .env со случайными секретами"
  PG_PASSWORD=$(openssl rand -hex 24)
  CEO_PASSWORD=$(openssl rand -base64 18 | tr -d '/+=' | cut -c1-16)
  umask 077
  cat >"$ENV_FILE" <<EOF
NODE_ENV=production
TZ=Asia/Tashkent
APP_URL=https://$DOMAIN
AUTH_SECRET=$(openssl rand -base64 48 | tr -d '\n')
SESSION_TTL_DAYS=7
LOG_LEVEL=info
TRUST_PROXY=loopback, linklocal, uniquelocal

POSTGRES_USER=fluggi
POSTGRES_PASSWORD=$PG_PASSWORD
POSTGRES_DB=fluggi
DATABASE_URL=postgresql://fluggi:$PG_PASSWORD@postgres:5432/fluggi?schema=public

SEED_DEMO=false
SEED_CEO_EMAIL=$CEO_EMAIL
SEED_CEO_PASSWORD=$CEO_PASSWORD
SEED_CEO_NAME=$CEO_NAME

# Telegram: впишите токен от @BotFather и выполните deploy/telegram-webhook.sh
TELEGRAM_BOT_TOKEN=
TELEGRAM_BOT_USERNAME=
TELEGRAM_MODE=webhook
TELEGRAM_WEBHOOK_SECRET=$(openssl rand -hex 24)

# Instagram / Facebook (Meta): см. раздел «Интеграции» в CRM
META_APP_SECRET=
META_VERIFY_TOKEN=$(openssl rand -hex 16)
META_PAGE_ACCESS_TOKEN=

SCHEDULER_ENABLED=true
STORAGE_DRIVER=local
EOF
  umask 022
fi

say "Сборка и запуск CRM (первый раз 5–15 минут)"
cd "$APP_DIR"
$COMPOSE up -d --build --remove-orphans

say "Жду готовности API"
for _ in $(seq 1 60); do
  curl -fsS http://127.0.0.1:4000/api/v1/ready >/dev/null 2>&1 && break
  sleep 5
done
curl -fsS http://127.0.0.1:4000/api/v1/ready >/dev/null || {
  $COMPOSE logs --tail 50 migrate api
  die "API не запустился — пришлите вывод выше"
}

# Пароль CEO нужен только для первого создания — убираем его из .env
if [ -n "$CEO_PASSWORD" ]; then sed -i 's/^SEED_CEO_PASSWORD=.*/SEED_CEO_PASSWORD=/' "$ENV_FILE"; fi

say "HTTPS для $DOMAIN"
cat >/etc/caddy/Caddyfile <<EOF
$DOMAIN {
	encode gzip
	request_body {
		max_size 25MB
	}
	handle /api/* {
		reverse_proxy 127.0.0.1:4000
	}
	handle {
		reverse_proxy 127.0.0.1:3000
	}
}
EOF
systemctl enable caddy >/dev/null && systemctl reload-or-restart caddy

say "Ежедневный бэкап базы в $BACKUP_DIR (03:00, хранится 14 дней)"
mkdir -p "$BACKUP_DIR" && chmod 700 "$BACKUP_DIR"
cat >/etc/cron.d/fluggi-backup <<EOF
0 3 * * * root cd $APP_DIR && $COMPOSE exec -T postgres pg_dump -U fluggi fluggi | gzip > $BACKUP_DIR/fluggi-\$(date +\%F).sql.gz && find $BACKUP_DIR -name '*.sql.gz' -mtime +14 -delete
EOF

docker image prune -f >/dev/null

printf '\n\033[1;32m✓ Готово!\033[0m  Откройте https://%s\n' "$DOMAIN"
if [ -n "$CEO_PASSWORD" ]; then
  printf '  Логин:  %s\n  Пароль: %s\n' "$CEO_EMAIL" "$CEO_PASSWORD"
  echo "  Сохраните пароль и смените его в профиле после первого входа — повторно он не показывается."
fi
echo "  Если сайт не открывается: проверьте, что DNS-запись $DOMAIN указывает на IP этого сервера."
