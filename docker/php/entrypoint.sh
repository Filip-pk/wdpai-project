#!/bin/sh
# The project directory is mounted over /app, which hides the vendor directory built into
# the image. Prepare the mounted copy before PHP-FPM starts, so a fresh clone works after
# "docker compose up --build" without manual steps.
set -eu

cd /app
export COMPOSER_ALLOW_SUPERUSER=1

# Composer leaves unchanged files untouched, so a stamp records the last successful install.
stamp=vendor/.anivibe-installed
if [ ! -f vendor/autoload.php ] || [ ! -f "$stamp" ] || [ composer.json -nt "$stamp" ] \
    || { [ -f composer.lock ] && [ composer.lock -nt "$stamp" ]; }; then
    composer install --no-interaction --prefer-dist --optimize-autoloader --no-progress
    touch "$stamp"
    # On Linux hosts, give the generated files to the owner of the project directory.
    chown -R "$(stat -c '%u:%g' /app)" vendor 2>/dev/null || true
fi

# PHP-FPM workers run as www-data and need to write application logs. A failure only
# disables the logs, so it must not stop the application from starting.
php scripts/prepare-admin-logs.php \
    || echo "AniVibe: storage/logs is not writable; application logs are disabled." >&2

# Uploaded profile photos are written and read by the same workers.
mkdir -p storage/uploads/avatars
chown -R www-data:www-data storage/uploads 2>/dev/null || true
chmod 0770 storage/uploads storage/uploads/avatars 2>/dev/null || true

exec docker-php-entrypoint "$@"
