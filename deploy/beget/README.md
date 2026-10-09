# Fluggi CRM на виртуальном хостинге Beget (без Docker)

Схема: Apache + Passenger запускают один Node.js-процесс (`deploy/beget/server.js`) — в нём и API, и
веб-интерфейс. База — облачная PostgreSQL Beget. Фоновые задачи (уведомления, Telegram, отчёты по
расписанию) выполняет cron раз в минуту через `POST /api/v1/internal/cron`.

Собирать на хостинге ничего не нужно: архив `fluggi-beget.zip` (~10 МБ) содержит уже собранный код.
Зависимости (`node_modules`, ~300 МБ) скрипты ставят на хостинге сами — нужен доступ в интернет
(npm и binaries.prisma.sh).

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

## 4. Установка с GitHub

После каждого изменения кода GitHub Actions собирает версию для хостинга и кладёт её в ветку
**`beget`** репозитория (вкладка Actions → «Beget build»). На хостинге её нужно только скачать.

В SSH (Docker-окружение):

```bash
cd ~/crm.fluggi.uz
git clone --depth 1 -b beget https://github.com/nodirbekabdulhaev/FLUGGI-GROUP.git fluggi
cd fluggi && bash deploy/beget/setup.sh
```

Без git: скачайте архив `fluggi-beget` из последней сборки (GitHub → Actions → «Beget build» →
Artifacts), загрузите `fluggi-beget.zip` в `~/crm.fluggi.uz/` и выполните
`unzip -q -o fluggi-beget.zip && cd fluggi && bash deploy/beget/setup.sh`.

Скрипт спросит домен, строку подключения к базе, email и имя CEO; установит зависимости, применит миграции, создаст
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

Одна команда в SSH (Docker-окружение) — скачает последнюю сборку с GitHub, обновит зависимости и
базу, перезапустит приложение:

```bash
cd ~/crm.fluggi.uz/fluggi && bash deploy/beget/pull-update.sh
```

`.env`, загруженные файлы (`storage/`) и `node_modules` не затрагиваются. Если ставили из архива —
распакуйте новый архив поверх и выполните `bash deploy/beget/update.sh`.

**Приватный репозиторий.** Если сделаете репозиторий приватным, хостингу нужен ключ только для
чтения: в SSH `ssh-keygen -t ed25519 -f ~/.ssh/github -N ""`, содержимое `~/.ssh/github.pub` —
в GitHub → Settings → Deploy keys → Add (без права записи). Затем:

```bash
printf 'Host github.com\n  Hostname ssh.github.com\n  Port 443\n  IdentityFile ~/.ssh/github\n' >> ~/.ssh/config
cd ~/crm.fluggi.uz/fluggi && git remote set-url origin git@github.com:nodirbekabdulhaev/FLUGGI-GROUP.git
```

## Сборка архива (для разработчика)

```bash
bash deploy/beget/build-bundle.sh fluggi-beget.zip   # Linux или macOS
```

## Если что-то не работает

- Ошибка 500/503 на сайте: панель → «Сайты» → логи ошибок; или в SSH
  `cd ~/crm.fluggi.uz/fluggi && PORT=3005 node deploy/beget/server.js` — ошибка запуска будет видна сразу.
- Перезапуск приложения: `touch ~/crm.fluggi.uz/fluggi/tmp/restart.txt`.
- Резервные копии базы делает облачная PostgreSQL Beget (проверьте настройки бэкапов в панели).
  Загруженные файлы лежат в `fluggi/storage/` — их сохраняет бэкап хостинга.
