#!/usr/bin/env bash
# Выкладка по схеме «релизы + симлинк» (техдок, раздел 5.4).
#
# Скрипт лежит в корне каталога сайта (рядом с releases/, shared/, current) и запускается
# под пользователем сайта, без sudo:
#   ./deploy.sh              — выложить ветку из deploy.env (или main)
#   ./deploy.sh <ветка>      — выложить указанную ветку
#   ./deploy.sh --rollback   — откат на предыдущий релиз
#
# Настройки — в файле deploy.env рядом со скриптом (образец — deploy/deploy.env.example):
#   DEPLOY_REPO, DEPLOY_BRANCH, PHP_BIN, DEPLOY_KEEP, FPM_SERVICE.
# PHP 8.3+ определяется автоматически, даже если системный php — более старая версия:
# системный PHP и composer не меняются, скрипт просто запускает их нужным интерпретатором.
set -euo pipefail

BASE=${DEPLOY_BASE:-$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)}
[[ -f "$BASE/deploy.env" ]] && set -a && . "$BASE/deploy.env" && set +a

REPO=${DEPLOY_REPO:-git@github.com:konkotds-gif/warehouse_service.git}
BRANCH=${1:-${DEPLOY_BRANCH:-main}}
KEEP=${DEPLOY_KEEP:-5}
FPM_SERVICE=${FPM_SERVICE:-}   # например php8.4-fpm; пусто — перезапустить PHP в ispmanager вручную

# --- PHP 8.3+ ---------------------------------------------------------------
php_ok() { [[ -x "$1" ]] && "$1" -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);' 2>/dev/null; }

PHP=""
for c in ${PHP_BIN:-} /opt/php84/bin/php /usr/bin/php8.4 /opt/php85/bin/php /usr/bin/php8.5 \
         /opt/php83/bin/php /usr/bin/php8.3 "$(command -v php || true)"; do
  if [[ -n "$c" ]] && php_ok "$c"; then PHP=$c; break; fi
done
[[ -z "$PHP" ]] && { echo "Не найден PHP 8.3+. Установите PHP 8.4 в ispmanager или укажите PHP_BIN в $BASE/deploy.env"; exit 1; }
echo "PHP: $PHP ($("$PHP" -r 'echo PHP_VERSION;'))"

COMPOSER=${COMPOSER_BIN:-$(command -v composer || true)}
[[ -z "$COMPOSER" ]] && { echo "Не найден composer"; exit 1; }

# Обёртка для ручных команд: $BASE/artisan user:create … (всегда нужный PHP и текущий релиз)
cat > "$BASE/artisan" <<EOF
#!/bin/sh
cd "$BASE/current" && exec "$PHP" artisan "\$@"
EOF
chmod +x "$BASE/artisan"

cd "$BASE"

# --- Откат ------------------------------------------------------------------
if [[ "$BRANCH" == "--rollback" ]]; then
  PREV=$(ls -1d releases/*/ | sort | tail -n 2 | head -n 1)
  [[ -z "$PREV" ]] && { echo "Нет предыдущего релиза"; exit 1; }
  ln -sfn "$BASE/${PREV%/}" current
  echo "Откат на ${PREV%/}"
  (cd current && "$PHP" artisan optimize && "$PHP" artisan queue:restart)
  [[ -n "$FPM_SERVICE" ]] && sudo systemctl reload "$FPM_SERVICE"
  exit 0
fi

# --- Проверки окружения -----------------------------------------------------
NODE_OK=$(node -e 'const [a,b]=process.versions.node.split(".").map(Number);console.log(a>22||(a==22&&b>=12)||(a==20&&b>=19)?1:0)' 2>/dev/null || echo 0)
[[ "$NODE_OK" == 1 ]] || { echo "Нужен Node.js 20.19+ или 22.12+ (сейчас: $(node -v 2>/dev/null || echo нет))"; exit 1; }

mkdir -p releases shared/storage/{app/public,framework/{cache,sessions,views},logs}

REL="$BASE/releases/$(date +%Y%m%d-%H%M%S)"
git clone --depth 1 --branch "$BRANCH" "$REPO" "$REL"

# Первый запуск: создать shared/.env из образца с новым APP_KEY и остановиться
if [[ ! -f shared/.env ]]; then
  cp "$REL/.env.example" shared/.env
  KEY="base64:$(openssl rand -base64 32)"
  sed -i "s|^APP_KEY=.*|APP_KEY=$KEY|" shared/.env
  chmod 600 shared/.env
  rm -rf "$REL"
  echo
  echo "Создан $BASE/shared/.env (APP_KEY сгенерирован)."
  echo "Заполните APP_ENV, APP_DEBUG, APP_URL и DB_* (см. docs/INSTALL.md, шаг 6) и запустите ./deploy.sh ещё раз."
  exit 0
fi

cd "$REL"
ln -s ../../shared/.env .env
rm -rf storage && ln -s ../../shared/storage storage

"$PHP" "$COMPOSER" install --no-dev --optimize-autoloader --no-interaction
npm ci && npm run build && rm -rf node_modules

"$PHP" artisan migrate --force
"$PHP" artisan storage:link || true
"$PHP" artisan optimize

ln -sfn "$REL" "$BASE/current"
"$PHP" artisan queue:restart
[[ -n "$FPM_SERVICE" ]] && sudo systemctl reload "$FPM_SERVICE"

# Старые релизы
cd "$BASE/releases" && ls -1d */ | sort | head -n -"$KEEP" | xargs -r rm -rf

echo
echo "Готово: $REL"
echo "PHP для cron и supervisor: $PHP"
echo "Ручные команды: $BASE/artisan <команда>"
