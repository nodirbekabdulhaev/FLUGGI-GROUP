# Развёртывание (Beget)

> **Виртуальный хостинг Beget (без Docker)** — см. [`deploy/beget/README.md`](deploy/beget/README.md):
> один Node.js-процесс под Passenger, облачная PostgreSQL Beget, фоновые задачи по cron.
> Ниже — установка на **VPS** (Beget VPS, Oracle Cloud, Hetzner) с Ubuntu 22.04/24.04.
> Рекомендуемый минимум: 2 vCPU, 4 ГБ RAM, 40 ГБ SSD.
>
> Статус: production-образ собран и проверен (миграции, создание CEO, API, worker, web, вход).

## Быстрая установка (рекомендуется)

Подходит для любого VPS с Ubuntu 22.04/24.04: Oracle Cloud Always Free (ARM), Hetzner, Beget VPS.

1. DNS: A-запись домена CRM (например `crm.fluggi.uz`) → публичный IP сервера.
2. В облачном файрволе открыть входящие TCP 80 и 443 (в Oracle: Subnet → Security List → Ingress).
3. Скопировать архив на сервер и запустить установку:

```bash
# на Mac
scp fluggi-phaseN.zip ubuntu@IP:~
ssh ubuntu@IP
# на сервере
sudo apt-get install -y unzip && unzip -o fluggi-phaseN.zip && cd fluggi
sudo bash deploy/install.sh
```

Скрипт спросит домен, email и имя CEO; сам поставит Docker, Caddy (HTTPS), создаст `.env` со
случайными секретами (`/opt/fluggi/.env`, права 600), соберёт и запустит CRM, настроит ежедневный
бэкап `/var/backups/fluggi`. В конце покажет пароль CEO — один раз.

Обновление новой версией: распаковать архив и выполнить `sudo bash deploy/update.sh`
(сначала делает бэкап базы). Telegram-бот: вписать `TELEGRAM_BOT_TOKEN` в `/opt/fluggi/.env` и
выполнить `sudo bash deploy/telegram-webhook.sh`.

Ниже — ручная установка через Nginx (тот же результат).

## Схема

```
Интернет → Nginx (443, TLS Let's Encrypt)
              ├─ /api/*  → api:4000   (NestJS)
              └─ /       → web:3000   (Next.js)
           worker  — фоновые задачи: outbox, планировщик, отправка в Telegram
           postgres — данные (volume pgdata)
```

## 1. Подготовка сервера

```bash
# Docker
curl -fsSL https://get.docker.com | sh
# Nginx и certbot
apt install -y nginx certbot python3-certbot-nginx
```

Направьте DNS-запись (например `crm.fluggi.uz`) на IP VPS в панели Beget.

## 2. Код и переменные

```bash
git clone <repo> /opt/fluggi && cd /opt/fluggi
cp .env.example .env
```

В `.env` для production:

```env
NODE_ENV=production
APP_URL=https://crm.fluggi.uz
AUTH_SECRET=<openssl rand -base64 48>
POSTGRES_PASSWORD=<надёжный пароль>
DATABASE_URL=postgresql://fluggi:<тот же пароль>@postgres:5432/fluggi?schema=public
API_INTERNAL_URL=http://api:4000
SEED_DEMO=false
SEED_CEO_EMAIL=you@fluggi.uz
SEED_CEO_PASSWORD=<временный пароль, сменить после входа>
SEED_CEO_NAME=Ваше имя
```

## 3. Запуск

```bash
docker compose -f docker-compose.prod.yml up -d --build
```

Сервис `migrate` применяет миграции и seed (роли, права, первый CEO), затем стартуют `api`,
`worker`, `web`. Порты 3000/4000 открыты только на `127.0.0.1`.

После первого входа смените пароль CEO в профиле и удалите `SEED_CEO_PASSWORD` из `.env`.

## 4. Nginx

`/etc/nginx/sites-available/fluggi`:

```nginx
server {
  server_name crm.fluggi.uz;
  client_max_body_size 25m;

  location /api/ {
    proxy_pass http://127.0.0.1:4000;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
  }

  location / {
    proxy_pass http://127.0.0.1:3000;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
  }
}
```

```bash
ln -s /etc/nginx/sites-available/fluggi /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
certbot --nginx -d crm.fluggi.uz
```

## 5. Обновление

```bash
cd /opt/fluggi && git pull
docker compose -f docker-compose.prod.yml up -d --build
```

Миграции применяются автоматически сервисом `migrate`. Ручные изменения production-БД запрещены (ТЗ §67).

## 6. Файлы

По умолчанию файлы (договоры, вложения) хранятся в Docker-volume `storage` на VPS.
Для надёжности лучше Beget S3: создайте бакет в панели Beget и задайте в `.env`:

```env
STORAGE_DRIVER=s3
STORAGE_ENDPOINT=https://s3.ru1.storage.beget.cloud
STORAGE_BUCKET=<имя бакета>
STORAGE_ACCESS_KEY=<ключ>
STORAGE_SECRET_KEY=<секрет>
```

Бакет должен быть **приватным**: файлы отдаются только через API после проверки прав.

## 7. Резервные копии

Ежедневный дамп (cron на хосте):

```bash
0 3 * * * docker compose -f /opt/fluggi/docker-compose.prod.yml exec -T postgres \
  pg_dump -U fluggi fluggi | gzip > /var/backups/fluggi-$(date +\%F).sql.gz
```

Копируйте дампы за пределы VPS (например в Beget S3).

## 8. Telegram-бот

1. Создайте бота у @BotFather (README, раздел 9) и укажите в `.env` на сервере:
   `TELEGRAM_BOT_TOKEN`, `TELEGRAM_BOT_USERNAME`, `TELEGRAM_MODE=webhook`,
   `TELEGRAM_WEBHOOK_SECRET=$(openssl rand -hex 24)`, `OUTBOX_IN_API=false`.
2. `docker compose -f docker-compose.prod.yml up -d api worker`
3. Зарегистрируйте webhook (один раз и после смены домена/секрета):

```bash
set -a; . ./.env; set +a
curl -s "https://api.telegram.org/bot$TELEGRAM_BOT_TOKEN/setWebhook" \
  -d url="$APP_URL/api/v1/telegram/webhook" -d secret_token="$TELEGRAM_WEBHOOK_SECRET"
curl -s "https://api.telegram.org/bot$TELEGRAM_BOT_TOKEN/getWebhookInfo"
```

Планировщик работает в worker по времени Ташкента; каждая задача выполняется один раз
за свой интервал (таблица `job_runs`), поэтому второй экземпляр worker безопасен.

## 9. Проверка

```bash
curl https://crm.fluggi.uz/api/v1/ready   # {"status":"ok","database":"ok"}
docker compose -f docker-compose.prod.yml logs -f api worker
```
