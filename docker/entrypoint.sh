#!/usr/bin/env bash
# Prepares the app on container start: dependencies, .env, APP_KEY, database, permissions.
set -euo pipefail
cd /var/www/html

if [ ! -f .env ]; then
  cp .env.example .env
fi

if [ ! -d vendor ]; then
  composer install --no-interaction --prefer-dist
fi

if ! grep -q '^APP_KEY=base64:' .env; then
  php artisan key:generate --force
fi

echo "Waiting for MySQL at ${DB_HOST:-db}..."
for i in $(seq 1 60); do
  if mysqladmin ping -h"${DB_HOST:-db}" -u"${DB_USERNAME:-sheets}" -p"${DB_PASSWORD:-secret}" --silent 2>/dev/null; then
    break
  fi
  sleep 2
done

php artisan migrate --force
php artisan sheets:admin "${ADMIN_EMAIL:-admin@example.com}" --password="${ADMIN_PASSWORD:-password}" >/dev/null
php artisan storage:link >/dev/null 2>&1 || true

mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache

exec "$@"
