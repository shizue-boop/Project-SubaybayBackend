#!/bin/sh
set -eu
: "${APP_KEY:?Set the production Laravel APP_KEY in Render.}"
: "${APP_URL:?Set APP_URL to the Render service URL.}"
: "${DB_HOST:?Set the database DB_HOST in Render.}"
: "${DB_DATABASE:?Set the database DB_DATABASE in Render.}"
: "${DB_USERNAME:?Set the database DB_USERNAME in Render.}"
: "${DB_PASSWORD:?Set the database DB_PASSWORD in Render.}"
: "${FRONTEND_ORIGINS:?Set the exact Vercel origin in Render.}"
test "${APP_ENV:-}" = production || { echo "APP_ENV must be production." >&2; exit 1; }
test "${APP_DEBUG:-true}" = false || { echo "APP_DEBUG must be false." >&2; exit 1; }
port="${PORT:-10000}"
storage_path="${LOCAL_STORAGE_PATH:-/var/www/html/storage/app/private}"
mkdir -p "$storage_path"
chown www-data:www-data "$storage_path"
sed -i "s/^Listen 80$/Listen ${port}/" /etc/apache2/ports.conf
sed -i "s/:80>/:${port}>/" /etc/apache2/sites-available/000-default.conf
exec apache2-foreground
