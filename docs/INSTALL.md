# Установка платформы хранения колёс на сервер

Debian 12 + ispmanager 6 · домен **wh.diskimira.com** · PHP 8.4 · MariaDB на отдельном сервере.

Инструкция рассчитана на первую установку «с нуля». Выкладка идёт по схеме «релизы + симлинк»
(`deploy.sh`): каждая версия лежит в своей папке, сайт смотрит на симлинк `current`, откат — одна команда.

> **Пароль БД в этом файле заменён на `<ПАРОЛЬ_БД>`.** Реальные значения храните только в `shared/.env` на сервере.

---

## 0. Исходные данные

| Параметр | Значение |
|---|---|
| Домен | `wh.diskimira.com` |
| PHP | 8.4 (PHP-FPM) |
| Сервер БД | `192.168.1.113`, порт `3310` (MariaDB 10.11+) |
| База данных | `wh-dm-01` |
| Пользователь БД | `wh-01` |
| Пароль БД | `<ПАРОЛЬ_БД>` |
| Пользователь сайта в ispmanager | `1c8` |
| Каталог сайта | `/var/www/1c8/data/www/wh.diskimira.com` |
| Ветка репозитория | `claude/project-thread-a2b3r2` (пока нет ветки `main`) |

Переменные окружения в SSH-сессии **не нужны**: `deploy.sh` сам находит PHP 8.4, берёт настройки
из файла `deploy.env` рядом с собой и создаёт обёртку `artisan` для ручных команд.

> На сервере уже работает другой Laravel-проект на PHP 8.2 и системный composer.
> Их **не трогаем**: системный `php` остаётся 8.2, `update-alternatives` не меняем.
> `deploy.sh` просто запускает composer и artisan интерпретатором PHP 8.4.
> Папка `wh.diskimira.com` должна быть пустой (или содержать только заглушку ispmanager) — другой проект в неё не кладите.
>
> **Оба проекта работают под одним пользователем `1c8`.** Поэтому: свой SSH-ключ для GitHub
> под отдельным именем (не `id_ed25519`, шаг 5), ничего не прописывается в `~/.bashrc`/`~/.profile`,
> воркер называется `wh-worker`, cron — отдельной строкой. Кэш, сессии и очередь лежат в своей БД
> `wh-dm-01`, поэтому с другим проектом не пересекаются.
>
> **sudo пользователю `1c8` не нужен.** Шаги «под root» (2, 4, 10) выполняет администратор сервера.

Итоговая структура каталогов:

```
/var/www/1c8/data/www/wh.diskimira.com/
├── deploy.sh                 ← скрипт выкладки
├── deploy.env                ← его настройки (репозиторий, ветка)
├── artisan                   ← обёртка: ./artisan <команда> = PHP 8.4 + current
├── shared/
│   ├── .env                  ← настройки и пароли приложения (один на все релизы)
│   └── storage/              ← логи, сессии, фото комплектов
├── releases/
│   ├── 20261102-150000/      ← версии кода
│   └── ...
└── current -> releases/20261102-150000   ← корень сайта: current/public
```

---

## 1. PHP 8.4 через ispmanager (рядом с 8.2)

Системный PHP на сервере — 8.2 (им пользуется другой проект). Нашему приложению нужен 8.3+, ставим 8.4 **дополнительно**.

1. ispmanager → **Настройки → Конфигурация ПО** (Software configuration) → **PHP** →
   установить альтернативную версию **PHP 8.4** (обычно ставится в `/opt/php84/`).
2. Там же, в свойствах PHP 8.4 → **Расширения**, включите:
   `mbstring`, `pdo_mysql` (mysqlnd), `fileinfo`, `dom`/`xml`, `tokenizer`, `ctype`, `iconv`,
   `openssl`, `curl`, `zip`, `bcmath`, `intl`, `opcache`.
3. Проверка по SSH (что PHP 8.4 установлен и где лежит):

   ```bash
   ls /opt/php84/bin/php /usr/bin/php8.4 2>/dev/null
   /opt/php84/bin/php -m | grep -Ei 'pdo_mysql|mbstring|fileinfo|dom|zip|intl|opcache'
   ```

   Больше ничего делать не нужно: `deploy.sh` найдёт PHP 8.4 по этим путям сам.
   Если он лежит где-то ещё — пропишите путь в `deploy.env` строкой `PHP_BIN=…` (шаг 6).

## 2. Системные пакеты (под root)

```bash
apt update
apt install -y git unzip curl supervisor mariadb-client
# Composer уже установлен (2.8.x) — переустанавливать не нужно.

# Node.js 22 — нужен для сборки интерфейса (Vite 8 требует Node 20.19+; в Debian 12 только 18)
curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
apt install -y nodejs
node -v   # v22.x
```

## 3. Пользователь и сайт в ispmanager

1. **Пользователи** → пользователь `1c8` (существующий).
   Включить ему **доступ к shell** (нужен для deploy.sh и artisan).
2. **Сайты** → **Создать**:
   - Имя: `wh.diskimira.com`, владелец — `1c8`;
   - Обработчик: **PHP-FPM**, версия **PHP 8.4**;
   - Корневая директория: `/var/www/1c8/data/www/wh.diskimira.com/current/public`
     (папки `current` пока нет — это нормально, она появится после первой выкладки;
     если ispmanager не даёт сохранить, временно оставьте путь по умолчанию и поменяйте после шага 7);
   - Галочка **SSL** → **Let's Encrypt**, включить **перенаправление HTTP → HTTPS**.
3. Свойства сайта → **PHP** (или «Настройки PHP»):
   `upload_max_filesize = 20M`, `post_max_size = 25M`, `memory_limit = 256M`, `max_execution_time = 60`.
4. Свойства сайта → **Конфигурационный файл nginx** (или «Дополнительные директивы nginx») — добавьте
   из `deploy/nginx-sklad.conf` всё, кроме `root` (корень уже задан в п. 2):

   ```nginx
   client_max_body_size 20m;
   location / { try_files $uri $uri/ /index.php?$query_string; }
   location = /sw.js { add_header Cache-Control "no-cache"; try_files $uri =404; }
   location /build/ { expires 1y; add_header Cache-Control "public, immutable"; }
   ```

   Если в конфиге ispmanager уже есть `location /`, замените в нём `try_files` на строку выше,
   а не дублируйте блок.

> DNS: A-запись `wh.diskimira.com` должна указывать на этот сервер **до** выпуска сертификата Let's Encrypt.

## 4. Доступ к базе данных

База живёт на отдельном сервере `192.168.1.113:3310`. Проверьте с веб-сервера:

```bash
mysql -h 192.168.1.113 -P 3310 -u wh-01 -p wh-dm-01 -e "SELECT VERSION(); SHOW VARIABLES LIKE 'character_set_server';"
```

Должно быть: версия **10.11 или новее**, кодировка `utf8mb4`.

Если подключиться не удаётся — на сервере БД (под root MariaDB):

```sql
-- кодировка базы
ALTER DATABASE `wh-dm-01` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- права пользователю с IP веб-сервера (замените <IP_ВЕБ_СЕРВЕРА>)
CREATE USER IF NOT EXISTS 'wh-01'@'<IP_ВЕБ_СЕРВЕРА>' IDENTIFIED BY '<ПАРОЛЬ_БД>';
GRANT ALL PRIVILEGES ON `wh-dm-01`.* TO 'wh-01'@'<IP_ВЕБ_СЕРВЕРА>';
FLUSH PRIVILEGES;
```

И проверьте, что MariaDB слушает сеть (`bind-address` не `127.0.0.1`) и порт 3310 открыт в фаерволе
для IP веб-сервера.

## 5. Доступ сервера к GitHub (deploy key)

Репозиторий приватный, поэтому серверу нужен ключ только на чтение. Под пользователем `1c8`
уже может быть ключ другого проекта — **не перезаписываем его**, создаём отдельный `wh_deploy`
и псевдоним хоста `github-wh`:

```bash
su - 1c8
ls ~/.ssh/                      # посмотреть, какие ключи уже есть — их не трогаем
ssh-keygen -t ed25519 -C "wh.diskimira.com deploy" -f ~/.ssh/wh_deploy -N ""

cat >> ~/.ssh/config <<'EOF'

Host github-wh
    HostName github.com
    User git
    IdentityFile ~/.ssh/wh_deploy
    IdentitiesOnly yes
EOF
chmod 600 ~/.ssh/config

cat ~/.ssh/wh_deploy.pub
```

Скопируйте вывод в GitHub: репозиторий `warehouse_service` → **Settings → Deploy keys → Add deploy key**
(галочку «Allow write access» **не** ставить). Проверка:

```bash
ssh -T github-wh     # «Hi konkotds-gif/warehouse_service! You've successfully authenticated…»
```

Доступ другого проекта к его репозиторию при этом не меняется: он продолжает ходить на `github.com`
со своим ключом.

## 6. deploy.sh, deploy.env и файл настроек `.env`

Дальше всё — **под пользователем `1c8`**, без sudo.

```bash
cd ~/www/wh.diskimira.com

# забрать deploy.sh и образец его настроек из репозитория
git clone --depth 1 --branch claude/project-thread-a2b3r2 git@github-wh:konkotds-gif/warehouse_service.git /tmp/wh-src
cp /tmp/wh-src/deploy.sh . && chmod +x deploy.sh
cp /tmp/wh-src/deploy/deploy.env.example deploy.env
rm -rf /tmp/wh-src

# первый запуск: создаст каталоги и shared/.env со сгенерированным APP_KEY, затем остановится
./deploy.sh
```

`deploy.env` уже содержит нужные значения (репозиторий через `github-wh`, ветка `claude/project-thread-a2b3r2`),
править его не требуется.

Отредактируйте `shared/.env` (`nano shared/.env`) — замените соответствующие строки
(`APP_KEY` уже заполнен, его не трогайте):

```dotenv
APP_NAME="Хранение колёс"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://wh.diskimira.com
APP_DISPLAY_TIMEZONE=Asia/Yekaterinburg

LOG_CHANNEL=daily
LOG_LEVEL=warning

DB_CONNECTION=mariadb
DB_HOST=192.168.1.113
DB_PORT=3310
DB_DATABASE=wh-dm-01
DB_USERNAME=wh-01
DB_PASSWORD=<ПАРОЛЬ_БД>

SESSION_DRIVER=database
SESSION_LIFETIME=720
SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=database
CACHE_STORE=database

# Почта для оповещений (onec:check-outbox). Пока log — письма пишутся в лог.
MAIL_MAILER=log
MAIL_FROM_ADDRESS="noreply@diskimira.com"
ONEC_ALERT_EMAIL=

SMS_DRIVER=log
```

Остальные строки (`STORAGE_*`, `ONEC_*`) можно оставить как в образце.

> **APP_KEY не меняйте после запуска** — им шифруются сессии; при смене все пользователи разлогинятся.

## 7. Первая выкладка

```bash
cd ~/www/wh.diskimira.com
./deploy.sh
```

Скрипт сам: находит PHP 8.4 и пишет, какой взял (`PHP: /opt/php84/bin/php (8.4.x)`), клонирует код
в `releases/<дата>`, подключает `shared/.env` и `shared/storage`, выполняет `composer install --no-dev`
(системным composer, но на PHP 8.4), собирает интерфейс (`npm ci && npm run build`),
применяет миграции (`migrate --force`), делает `storage:link`, `optimize` и переключает `current`.

Проверьте:

```bash
ls -l current                     # симлинк на свежий релиз
./artisan about                   # Environment: production, Debug: OFF, Database: mariadb, PHP 8.4
./artisan migrate:status          # все миграции — Ran
```

Если корень сайта на шаге 3 временно оставляли по умолчанию — теперь поменяйте его на
`/var/www/1c8/data/www/wh.diskimira.com/current/public`.

> **Демо-данные (`--seed`, `db:seed`) на боевом сервере не запускать.** Они создают пользователей с паролем `password`.

### Права

В ispmanager PHP-FPM работает от имени владельца сайта, поэтому достаточно, чтобы всё принадлежало `1c8`:

```bash
# под root, если что-то создавалось от root
chown -R 1c8:1c8 /var/www/1c8/data/www/wh.diskimira.com
chmod -R ug+rwX /var/www/1c8/data/www/wh.diskimira.com/shared/storage
```

## 8. Первый администратор и ключ для 1С

```bash
cd ~/www/wh.diskimira.com
./artisan user:create admin "Администратор" --role=admin
# пароль спросит интерактивно (не короче 8 символов)
```

Дальше сотрудников, магазины, склады и ячейки удобнее заводить в интерфейсе
(«Склады и ячейки» → склад → «Сгенерировать ряды»). Из консоли можно так:

```bash
./artisan user:create ivanov "Иванов И." --role=seller --location=М01
```

Ключ API для 1С (выводится **один раз** — сразу сохраните):

```bash
./artisan onec:key "1С УТ" --ips=<IP_СЕРВЕРА_1С>
```

Перенос текущих хранений из Excel (CSV, `;`, образец — `deploy/import-example.csv`):

```bash
./artisan storage:import /путь/к/файлу.csv --dry-run    # только проверка
./artisan storage:import /путь/к/файлу.csv
```

## 9. Планировщик (cron)

ispmanager → **Планировщик (CRON)** → создать задачу от пользователя `1c8`, **каждую минуту** (`* * * * *`):

```bash
/var/www/1c8/data/www/wh.diskimira.com/artisan schedule:run >> /dev/null 2>&1
```

Он запускает: пометку просроченных договоров (02:00), проверку неподтверждённых событий 1С
(каждые 10 минут) и чистку журнала 1С (ежедневно). Проверка: `./artisan schedule:list`.

## 10. Очередь (воркер)

Очередь в БД (`QUEUE_CONNECTION=database`). Воркер держит supervisor. Под root создайте
`/etc/supervisor/conf.d/wh-worker.conf`:

```ini
[program:wh-worker]
command=/var/www/1c8/data/www/wh.diskimira.com/artisan queue:work --sleep=3 --tries=3 --max-time=3600
user=1c8
numprocs=1
autostart=true
autorestart=true
stopwaitsecs=3600
redirect_stderr=true
stdout_logfile=/var/www/1c8/data/www/wh.diskimira.com/shared/storage/logs/worker.log
```

```bash
supervisorctl reread && supervisorctl update && supervisorctl status wh-worker   # RUNNING
```

`deploy.sh` после каждой выкладки делает `queue:restart` — воркер сам перезапустится на новом коде.

> Нет возможности поставить supervisor — временно добавьте в cron ещё одну задачу «каждую минуту»:
> `/var/www/1c8/data/www/wh.diskimira.com/artisan queue:work --stop-when-empty --max-time=55 >> /dev/null 2>&1`

## 11. Проверка после установки

| Что | Как | Ожидаем |
|---|---|---|
| Сайт открывается по HTTPS | `https://wh.diskimira.com` | страница входа, замок в браузере |
| Живость | `curl -s https://wh.diskimira.com/health` | `"database":"ok"`, `"cache":"ok"` |
| Вход | логин `admin` | рабочий стол администратора |
| Бирки | создать тестовое хранение → «Печать бирок» | бирки с QR (номер договора) |
| Фото | загрузить фото комплекта | загружается, открывается |
| Планировщик | `./artisan schedule:list` | 3 задачи |
| Воркер | `supervisorctl status wh-worker` | RUNNING |
| API-документация | `https://wh.diskimira.com/docs/api` (под админом) | страница Scramble |

Тестовое хранение после проверки удалите или отметьте выданным.

## 12. Обновление и откат

Обновление до свежего кода ветки (из `deploy.env`):

```bash
cd ~/www/wh.diskimira.com
./deploy.sh
```

Другую ветку можно указать явно: `./deploy.sh <ветка>`. Откат на предыдущий релиз:

```bash
./deploy.sh --rollback
```

> Откат возвращает код, но **не откатывает миграции БД**. Перед обновлением, меняющим схему,
> сделайте дамп: `mysqldump -h 192.168.1.113 -P 3310 -u wh-01 -p wh-dm-01 > ~/backup-$(date +%F).sql`.

Хранится 5 последних релизов (`DEPLOY_KEEP`). Кэш OPcache сбрасывается при перезагрузке пула PHP-FPM —
если после выкладки виден старый код, перезапустите PHP 8.4 в ispmanager или задайте
`FPM_SERVICE=<имя службы php-fpm>` в `deploy.env` (нужен sudo).

## 13. Частые проблемы

| Симптом | Причина и решение |
|---|---|
| `deploy.sh`: *Не найден PHP 8.3+* | PHP 8.4 не установлен в ispmanager (шаг 1) или лежит в нестандартном месте — укажите `PHP_BIN=…` в `deploy.env`. |
| `php artisan …`: *Composer detected issues in your platform… PHP >= 8.3* | Запущен системный `php` (8.2). Для ручных команд используйте обёртку `./artisan …` из каталога сайта. |
| `deploy.sh`: *Нужен Node.js 20.19+* | Установлен Node 18 из Debian — поставьте Node 22 (шаг 2, под root). |
| `git clone`: *Permission denied (publickey)* / *Repository not found* | Клонирование идёт не через `github-wh`: проверьте `DEPLOY_REPO` и `ssh -T github-wh` (шаг 5). |
| `SQLSTATE[HY000] [2002]` / `[1045]` | Нет сети до 192.168.1.113:3310 или нет GRANT для IP веб-сервера (шаг 4). |
| 500 без текста | Смотрите `shared/storage/logs/laravel-*.log`. Часто — права на `shared/storage` или пустой `APP_KEY`. |
| 419 Page Expired при входе | `APP_URL` не совпадает с адресом в браузере или нет HTTPS при `SESSION_SECURE_COOKIE=true`. |
| Стили не грузятся, белая страница | Не собран фронтенд (`public/build` пуст) — повторите выкладку, посмотрите вывод `npm run build`. |
| 404 на всех страницах, кроме главной | В nginx нет `try_files $uri $uri/ /index.php?$query_string;` (шаг 3, п. 4). |
| Фото не загружаются (413) | `client_max_body_size 20m` в nginx и `upload_max_filesize`/`post_max_size` в PHP. |
| Изменения в `.env` не применились | После правки `.env`: `./artisan optimize:clear && ./artisan optimize && ./artisan queue:restart`. |

---

Связанные документы: [README](../README.md) · [техдокументация](TECHNICAL.md) · [API для 1С](API.md).
