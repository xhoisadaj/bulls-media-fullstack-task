#!/usr/bin/env bash
#
# Sets up Sheets Sync on a fresh Ubuntu 22.04 / 24.04 server (or VM) from this repository.
# Installs PHP 8.4, Composer, MySQL, nginx and supervisor, creates the database and admin
# account, and starts the queue worker and the daily scheduler.
#
# Usage (from the repository root):
#   sudo ./setup.sh                 # install
#   sudo ./setup.sh --demo          # install and load the demo data (no Google account needed)
#
# Optional environment variables:
#   APP_URL=http://203.0.113.10     public URL (default: http://<server IP>)
#   APP_PORT=80                     port nginx listens on
#   DB_NAME=sheets_sync DB_USER=sheets DB_PASSWORD=...   (password generated when empty)
#   ADMIN_EMAIL=admin@example.com ADMIN_PASSWORD=...     (password generated when empty)
#
# Safe to run again: existing database, users, .env values and APP_KEY are kept.

set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_PORT="${APP_PORT:-80}"
DB_NAME="${DB_NAME:-sheets_sync}"
DB_USER="${DB_USER:-sheets}"
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@example.com}"
PHP_VERSION="8.4"
LOAD_DEMO=0

for arg in "$@"; do
  case "$arg" in
    --demo) LOAD_DEMO=1 ;;
    -h|--help) sed -n '2,20p' "$0"; exit 0 ;;
    *) echo "Unknown option: $arg" >&2; exit 1 ;;
  esac
done

log() { printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m!! %s\033[0m\n' "$*"; }
random_secret() { tr -dc 'A-Za-z0-9' </dev/urandom | head -c "${1:-24}"; }

# Starts or reloads a service with systemd when available, otherwise with `service` (containers).
svc() {
  local action="$1" name="$2"
  if command -v systemctl >/dev/null 2>&1 && [ -d /run/systemd/system ]; then
    systemctl enable "$name" >/dev/null 2>&1 || true
    systemctl "$action" "$name"
  else
    service "$name" "$action" >/dev/null 2>&1 || service "$name" start
  fi
}

# Sets KEY=value in .env, replacing an existing line or appending it.
set_env() {
  local key="$1" value="$2" file="$APP_DIR/.env"
  local escaped
  escaped=$(printf '%s' "$value" | sed -e 's/[\/&|]/\\&/g')
  if grep -qE "^${key}=" "$file"; then
    sed -i -E "s|^${key}=.*|${key}=${escaped}|" "$file"
  else
    printf '%s=%s\n' "$key" "$value" >> "$file"
  fi
}

get_env() { grep -E "^$1=" "$APP_DIR/.env" 2>/dev/null | head -n1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }

if [ "$(id -u)" -ne 0 ]; then
  echo "Run this script with sudo: sudo ./setup.sh" >&2
  exit 1
fi

if ! grep -qE 'Ubuntu (22|24)\.04' /etc/os-release 2>/dev/null; then
  warn "This script is written for Ubuntu 22.04 / 24.04. Continuing anyway."
fi

export DEBIAN_FRONTEND=noninteractive

log "Installing system packages"
apt-get update -y
apt-get install -y --no-install-recommends software-properties-common ca-certificates curl gnupg unzip git \
  nginx mysql-server supervisor cron tzdata
if ! grep -rq "ondrej/php" /etc/apt/sources.list /etc/apt/sources.list.d 2>/dev/null; then
  add-apt-repository -y ppa:ondrej/php
  apt-get update -y
fi
apt-get install -y --no-install-recommends \
  php${PHP_VERSION}-fpm php${PHP_VERSION}-cli php${PHP_VERSION}-mysql php${PHP_VERSION}-mbstring php${PHP_VERSION}-xml \
  php${PHP_VERSION}-curl php${PHP_VERSION}-zip php${PHP_VERSION}-intl php${PHP_VERSION}-bcmath php${PHP_VERSION}-gd

if ! command -v composer >/dev/null 2>&1; then
  log "Installing Composer"
  curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi

log "Starting MySQL"
svc start mysql
for _ in $(seq 1 30); do mysqladmin ping --silent 2>/dev/null && break; sleep 1; done

log "Preparing .env"
cd "$APP_DIR"
[ -f .env ] || cp .env.example .env

DB_PASSWORD="${DB_PASSWORD:-$(get_env DB_PASSWORD)}"
if [ -z "$DB_PASSWORD" ] || [ "$DB_PASSWORD" = "secret" ]; then
  DB_PASSWORD="$(random_secret 24)"
fi

SERVER_IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
DEFAULT_URL="http://${SERVER_IP:-localhost}"
[ "$APP_PORT" != "80" ] && DEFAULT_URL="${DEFAULT_URL}:${APP_PORT}"
APP_URL="${APP_URL:-$DEFAULT_URL}"

set_env APP_ENV production
set_env APP_DEBUG false
set_env APP_URL "$APP_URL"
set_env DB_CONNECTION mysql
set_env DB_HOST 127.0.0.1
set_env DB_PORT 3306
set_env DB_DATABASE "$DB_NAME"
set_env DB_USERNAME "$DB_USER"
set_env DB_PASSWORD "$DB_PASSWORD"
set_env QUEUE_CONNECTION database

log "Creating database and user"
mysql -uroot <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

log "Installing PHP dependencies"
export COMPOSER_ALLOW_SUPERUSER=1
composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader

if [ -z "$(get_env APP_KEY)" ]; then
  php artisan key:generate --force
fi

log "Running migrations and creating the admin account"
php artisan migrate --force
ADMIN_CREATED=0
ADMIN_RESULT="$(php artisan sheets:admin "$ADMIN_EMAIL" --password="${ADMIN_PASSWORD:-}")"
if [[ "$ADMIN_RESULT" == created* ]]; then
  ADMIN_PASSWORD="${ADMIN_RESULT#created }"
  ADMIN_CREATED=1
fi

php artisan filament:assets >/dev/null
php artisan storage:link >/dev/null 2>&1 || true
php artisan config:cache >/dev/null
php artisan route:cache >/dev/null
php artisan view:cache >/dev/null

mkdir -p storage/framework/{cache,sessions,views} storage/logs storage/app/demo bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache

log "Configuring nginx"
cat > /etc/nginx/sites-available/sheets-sync <<NGINX
server {
    listen ${APP_PORT} default_server;
    server_name _;
    root ${APP_DIR}/public;
    index index.php;
    client_max_body_size 12M;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php${PHP_VERSION}-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
    }

    location ~ /\.(?!well-known) {
        deny all;
    }
}
NGINX
ln -sf /etc/nginx/sites-available/sheets-sync /etc/nginx/sites-enabled/sheets-sync
rm -f /etc/nginx/sites-enabled/default
nginx -t
svc start php${PHP_VERSION}-fpm
svc restart php${PHP_VERSION}-fpm
svc start nginx
svc reload nginx

log "Configuring the queue worker (supervisor) and the daily scheduler (cron)"
cat > /etc/supervisor/conf.d/sheets-sync-worker.conf <<SUP
[program:sheets-sync-worker]
command=php ${APP_DIR}/artisan queue:work --sleep=3 --tries=1 --timeout=600
user=www-data
autostart=true
autorestart=true
stopwaitsecs=620
stdout_logfile=${APP_DIR}/storage/logs/worker.log
redirect_stderr=true
SUP
svc start supervisor
supervisorctl reread >/dev/null && supervisorctl update >/dev/null
supervisorctl restart sheets-sync-worker >/dev/null 2>&1 || true

echo "* * * * * www-data cd ${APP_DIR} && php artisan schedule:run >> /dev/null 2>&1" > /etc/cron.d/sheets-sync
chmod 644 /etc/cron.d/sheets-sync
svc start cron

if [ "$LOAD_DEMO" -eq 1 ]; then
  log "Loading the demo data"
  runuser -u www-data -- php artisan sheets:demo
fi

APP_URL="$(get_env APP_URL)"
printf '\n\033[1;32mSheets Sync is ready.\033[0m\n\n'
printf '  Admin panel:   %s/admin\n' "$APP_URL"
if [ "$ADMIN_CREATED" -eq 1 ]; then
  printf '  Login:         %s / %s   (shown once; change it after logging in)\n' "$ADMIN_EMAIL" "$ADMIN_PASSWORD"
else
  printf '  Login:         %s (existing account, password unchanged)\n' "$ADMIN_EMAIL"
fi
printf '  Redirect URI:  %s/google/callback   (register it on your Google OAuth client)\n' "$APP_URL"
printf '  Daily sync:    %s (server time), plus "Sync now" in the panel\n\n' "$(php artisan config:show sync.daily_at 2>/dev/null | awk '{print $NF}' || echo 02:00)"
printf 'Next: follow docs/GOOGLE_API.md, then enter the credentials in Settings → Google connection\n'
printf '      (or run: sudo -u www-data php artisan google:configure).\n'
