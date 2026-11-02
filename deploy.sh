#!/usr/bin/env bash
# Выкладка по схеме «релизы + симлинк» (техдок, раздел 5.4).
# Запуск под пользователем сайта:  ./deploy.sh [ветка]     откат:  ./deploy.sh --rollback
set -euo pipefail

BASE=${DEPLOY_BASE:-/var/www/sklad/data/app}
REPO=${DEPLOY_REPO:-git@github.com:konkotds-gif/warehouse_service.git}
BRANCH=${1:-main}
KEEP=${DEPLOY_KEEP:-5}
PHP=${PHP_BIN:-php}
FPM_SERVICE=${FPM_SERVICE:-}   # например php8.4-fpm; пусто — перезапустить пул вручную в ispmanager

cd "$BASE"

if [[ "$BRANCH" == "--rollback" ]]; then
  PREV=$(ls -1d releases/*/ | sort | tail -n 2 | head -n 1)
  [[ -z "$PREV" ]] && { echo "Нет предыдущего релиза"; exit 1; }
  ln -sfn "$BASE/${PREV%/}" current
  echo "Откат на ${PREV%/}"
  (cd current && $PHP artisan optimize && $PHP artisan queue:restart)
  [[ -n "$FPM_SERVICE" ]] && sudo systemctl reload "$FPM_SERVICE"
  exit 0
fi

mkdir -p releases shared/storage/{app/public,framework/{cache,sessions,views},logs}
[[ -f shared/.env ]] || { echo "Создайте $BASE/shared/.env (образец — .env.example)"; exit 1; }

REL="$BASE/releases/$(date +%Y%m%d-%H%M%S)"
git clone --depth 1 --branch "$BRANCH" "$REPO" "$REL"
cd "$REL"

ln -s ../../shared/.env .env
rm -rf storage && ln -s ../../shared/storage storage

composer install --no-dev --optimize-autoloader --no-interaction
npm ci && npm run build && rm -rf node_modules

$PHP artisan migrate --force
$PHP artisan storage:link || true
$PHP artisan optimize

ln -sfn "$REL" "$BASE/current"
$PHP artisan queue:restart
[[ -n "$FPM_SERVICE" ]] && sudo systemctl reload "$FPM_SERVICE"

# Старые релизы
cd "$BASE/releases" && ls -1d */ | sort | head -n -"$KEEP" | xargs -r rm -rf
echo "Готово: $REL"
