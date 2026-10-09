# Fluggi CRM на виртуальном хостинге Beget (без Docker)

Схема: Apache + Passenger запускают один Node.js-процесс (`deploy/beget/server.js`) — в нём и API, и
веб-интерфейс. База — облачная PostgreSQL Beget. Фоновые задачи (уведомления, Telegram, отчёты по
расписанию) выполняет cron раз в минуту через `POST /api/v1/internal/cron`.

Собирать на хостинге ничего не нужно: архив `fluggi-beget.zip` уже содержит собранный код и
`node_modules` для Linux x64.

## 1. Облачная PostgreSQL

1. Панель Beget → «Облако» → «Базы данных» → создать PostgreSQL 16 (минимальной конфигурации
   хватит).
2. Скопируйте строку подключения вида
   `postgresql://ПОЛЬЗОВАТЕЛЬ:ПАРОЛЬ@ХОСТ:5432/ИМЯ_БАЗЫ?sslmode=require`.
   Если в базе есть список разрешённых адресов — добавьте IP вашего хостинга.

## 2. Сайт и домен

1. Панель → «Сайты» → создать сайт, например `crm.fluggi.uz`, привязать домен/поддомен.
2. Панель → «SSL» → включить бесплатный сертификат Let's Encrypt для этого домена.

## 3. Node.js 22

Подключитесь по SSH и войдите в Docker-окружение (Node.js на виртуальном хостинге работает только в нём):

```bash
ssh ЛОГИН@ЛОГИН.beget.tech
ssh localhost -p222
```

Установите Node.js 22 в `~/.local`:

```bash
cat /etc/os-release | grep VERSION=      # нужна Ubuntu 22.04
cd ~
V=$(curl -s https://nodejs.org/dist/latest-v22.x/ | grep -o 'node-v22[0-9.]*-linux-x64.tar.xz' | head -1)
curl -O https://nodejs.org/dist/latest-v22.x/$V && tar -xJf $V
mkdir -p ~/.local && cp -r ${V%.tar.xz}/{bin,lib,include,share} ~/.local/ && rm -rf ${V%.tar.xz} $V
echo 'export PATH=$HOME/.local/bin:$PATH' >> ~/.bashrc && source ~/.bashrc
node -v                                  # v22.x
```

Если `node -v` выдаёт ошибку — на вашем сервере старая ОС; возьмите сборку Node.js 22 из статьи
Beget «Установка и настройка Node.js на хостинг». Там же — как **открыть общий доступ к `~/.local`**
для веб-сервера (обязательно, иначе Passenger не увидит Node.js).

## 4. Загрузка и установка

1. Загрузите `fluggi-beget.zip` в папку сайта `~/crm.fluggi.uz/` (файловый менеджер или FTP).
2. В SSH (Docker-окружение) — распаковывать именно через `unzip`, файловый менеджер портит ссылки:

```bash
cd ~/crm.fluggi.uz && unzip -q -o fluggi-beget.zip && cd fluggi
bash deploy/beget/setup.sh
```

Скрипт спросит домен, строку подключения к базе, email и имя CEO; применит миграции, создаст
CEO, запишет `public_html/.htaccess` для Passenger и покажет **пароль CEO (один раз)** и команду для cron.

## 5. Cron

Панель → «Cron» → добавить задачу «каждую минуту» с командой, которую напечатал `setup.sh`:

```
curl -s -X POST -H 'x-cron-secret: СЕКРЕТ' https://crm.fluggi.uz/api/v1/internal/cron >/dev/null
```

Cron заодно держит приложение «тёплым»: без запросов Passenger останавливает процесс, и первый
запрос после паузы ждёт 10–30 секунд.

## 6. Telegram-бот (по желанию)

Впишите `TELEGRAM_BOT_TOKEN` в `~/crm.fluggi.uz/fluggi/.env` и выполните
`bash deploy/beget/telegram-webhook.sh`.

## Обновление

Загрузите новый `fluggi-beget.zip` в `~/crm.fluggi.uz/` и в SSH:

```bash
cd ~/crm.fluggi.uz && unzip -q -o fluggi-beget.zip && cd fluggi && bash deploy/beget/update.sh
```

`.env`, загруженные файлы (`storage/`) и настройки не затрагиваются.

## Сборка архива (для разработчика)

```bash
bash deploy/beget/build-bundle.sh fluggi-beget.zip   # Linux x64; на Mac — через Docker, см. скрипт
```

## Если что-то не работает

- Ошибка 500/503 на сайте: панель → «Сайты» → логи ошибок; или в SSH
  `cd ~/crm.fluggi.uz/fluggi && PORT=3005 node deploy/beget/server.js` — ошибка запуска будет видна сразу.
- Перезапуск приложения: `touch ~/crm.fluggi.uz/fluggi/tmp/restart.txt`.
- Резервные копии базы делает облачная PostgreSQL Beget (проверьте настройки бэкапов в панели).
  Загруженные файлы лежат в `fluggi/storage/` — их сохраняет бэкап хостинга.
