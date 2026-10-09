# Fluggi CRM на виртуальном хостинге Beget (PHP + MySQL)

Обычный тариф «для сайтов»: PHP 8.2+, MySQL, cron. Node.js, Docker и SSH-окружение `-p222`
**не нужны**. GitHub сам проверяет и собирает каждую версию и кладёт её в ветку **`hosting`**
(вкладка Actions → «Hosting (PHP)»); на хостинге её нужно только скачать.

## 1. Сайт, PHP и база

1. Панель Beget → «Сайты» → создать сайт `crm.fluggi.uz`, привязать домен.
2. Там же у сайта выбрать версию **PHP 8.2** или новее.
3. Панель → «SSL» → бесплатный сертификат Let's Encrypt для домена.
4. Панель → «MySQL» → создать базу, например `crm` (полное имя будет `ЛОГИН_crm`), задать пароль.
   На Beget имя пользователя базы = имя базы, сервер — `localhost`.

## 2. Установка

SSH (обычный, **без** `ssh localhost -p222`):

```bash
ssh ЛОГИН@ЛОГИН.beget.tech
cd ~/crm.fluggi.uz
git clone --depth 1 -b hosting https://github.com/nodirbekabdulhaev/FLUGGI-GROUP.git fluggi
cd fluggi && bash deploy/beget/setup.sh
```

Скрипт спросит домен, имя и пароль базы, email и имя CEO. Он:
- создаст `.env` со случайными ключами (файл не в git, обновления его не трогают);
- создаст таблицы, роли и права, справочники, первого CEO и покажет **пароль CEO один раз**;
- направит сайт в папку `fluggi/public` (`public_html` станет ссылкой на неё; прежняя папка
  сохранится как `public_html.beget-bak`) — код и `.env` из интернета недоступны;
- напечатает команду для cron.

Если в базе уже есть данные прежней версии CRM (на Node.js) — таблицы и данные подходят без
переноса, пароли сотрудников прежние.

## 3. Cron

Панель → «Cron» → добавить задачу **каждую минуту** с командой, которую напечатал `setup.sh`, например:

```
/usr/local/bin/php8.2 /home/ЛОГИН/crm.fluggi.uz/fluggi/artisan schedule:run >/dev/null 2>&1
```

Cron рассылает уведомления и Telegram, отмечает просрочки, создаёт напоминания и отчёты.

## 4. Telegram-бот (по желанию)

Впишите `TELEGRAM_BOT_TOKEN` и `TELEGRAM_BOT_USERNAME` в `~/crm.fluggi.uz/fluggi/.env`, затем:

```bash
cd ~/crm.fluggi.uz/fluggi && php8.2 artisan config:cache && php8.2 artisan fluggi:telegram-webhook
```

## Обновление

```bash
cd ~/crm.fluggi.uz/fluggi && bash deploy/beget/update.sh
```

Скачает последнюю версию из ветки `hosting`, обновит таблицы и справочники (без потери данных),
очистит кэш. `.env`, загруженные файлы и логи (`storage/`) не затрагиваются.

Без git: GitHub → Actions → последняя сборка «Hosting (PHP)» → Artifacts → `fluggi-hosting`,
распакуйте в `~/crm.fluggi.uz/fluggi` (поверх) и выполните `php8.2 artisan fluggi:install && php8.2 artisan optimize`.

## Если что-то не работает

- Ошибка 500: `~/crm.fluggi.uz/fluggi/storage/logs/laravel-ДАТА.log` (последние строки).
- «PHP 8.2 не найден»: в SSH выполните `ls /usr/local/bin/php*` и запустите
  `PHP_BIN=/usr/local/bin/php8.3 bash deploy/beget/setup.sh`.
- Резервные копии базы и файлов делает Beget (панель → «Резервные копии»).
- Сообщение «хостинг не разрешает триггеры» — не ошибка: это дополнительная защита журналов
  и оплат на уровне БД, приложение защищает их и без неё.
