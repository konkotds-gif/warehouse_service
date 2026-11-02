# Платформа хранения колёс «Диски Мира»

Учёт сезонного хранения шин и колёс: приём в магазине, договор с уникальным номером, бирки с QR,
перемещения магазин ⇄ склад, адресное хранение `XX_YY_ZZ` (склад_ряд_ячейка), выдача, инвентаризация,
гараж клиента и обмен с 1С:УТ 10.3 через REST API.

Документы: [установка на сервер](docs/INSTALL.md) · [план](docs/PLAN.md) · [техдокументация](docs/TECHNICAL.md) · [API для 1С](docs/API.md) · [изменения](CHANGELOG.md)

## Стек

Laravel 13 · PHP 8.4 (работает и на 8.3) · MariaDB 10.11 · Inertia + Vue 3 · Tailwind 4 · Vite.
Интерфейс — PWA (работает с телефона кладовщика, офлайн-очередь сканирований).

## Установка для разработки

```bash
composer install
cp .env.example .env && php artisan key:generate
# создать БД sklad и пользователя, прописать DB_* в .env
php artisan migrate --seed        # схема + демо-данные
npm ci && npm run build           # или npm run dev
php artisan serve
```

Тесты используют отдельную базу `sklad_test` (см. `phpunit.xml`): `php artisan test`.
Стиль кода: `vendor/bin/pint`.

### Демо-доступы (после `--seed`, пароль у всех `password`)

| Логин | Роль | Локация |
|---|---|---|
| admin | Администратор | — |
| manager | Руководитель магазина | М01 |
| seller | Продавец | М01 |
| seller5 | Продавец | М05 |
| keeper | Кладовщик | склад 02 |
| driver | Водитель | — |

На боевом сервере `--seed` не запускать.

## Команды

| Команда | Назначение |
|---|---|
| `php artisan user:create ivanov "Иванов И." --role=seller --location=М01` | создать сотрудника (роли: seller, storekeeper, driver, store_manager, admin) |
| `php artisan onec:key "1С УТ" --ips=10.0.0.5` | выпустить ключ API для 1С (показывается один раз) |
| `php artisan storage:import file.csv [--dry-run]` | перенос текущих хранений из Excel/CSV, образец — `deploy/import-example.csv` |
| `php artisan storage:mark-overdue` | пометить просроченные договоры (по расписанию в 02:00) |
| `php artisan onec:check-outbox` | предупредить, если 1С не забирает события (каждые 10 мин) |
| `php artisan onec:prune-inbox` | чистка журнала идемпотентности (ежедневно) |

Ячейки склада генерируются в интерфейсе: «Склады и ячейки» → склад → «Сгенерировать ряды».

## Выкладка на сервер (Debian 12 + ispmanager 6)

1. Создать сайт и БД в ispmanager, PHP 8.4, корень — `/var/www/sklad/data/app/current/public`
   (фрагмент nginx — `deploy/nginx-sklad.conf`).
2. Положить `.env` в `/var/www/sklad/data/app/shared/.env` (`APP_ENV=production`, `APP_DEBUG=false`, DB_*, `APP_URL`).
3. Запустить `./deploy.sh main` под пользователем сайта. Откат — `./deploy.sh --rollback`.
   Если задан `FPM_SERVICE=php8.4-fpm`, пул PHP-FPM перезагрузится автоматически.
4. Cron — `deploy/crontab.txt`, воркер очереди — `deploy/supervisor-sklad-worker.conf`.
5. Создать администратора (`user:create`) и ключ для 1С (`onec:key`).

Проверка живости: `GET /health`. Документация API (Scramble) — `/docs/api`, доступна администратору.

## Отличия от техдокументации

- Тесты на PHPUnit (Pest не подключали — меньше зависимостей, тот же Laravel-тестинг).
- Роли — собственная матрица `App\Domain\Users\Role` вместо spatie/permission (5 ролей, без редактирования прав в интерфейсе).
- Вход — собственный контроллер (логин + пароль, блокировка после 5 попыток) вместо Fortify.
- Кэш, сессии и очередь — в БД; Redis не нужен на старте.
- PHPStan в CI пока не включён.
