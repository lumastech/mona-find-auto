#!/usr/bin/env bash
set -euo pipefail

###############################################################################
# Waits for MySQL, then makes sure the application is installed before handing
# over to the container's command.
###############################################################################

wait_for_mysql() {
    local attempts=0

    until mysqladmin ping --host="${DB_HOST:-mysql}" --silent > /dev/null 2>&1; do
        attempts=$((attempts + 1))

        if [ "${attempts}" -ge 60 ]; then
            echo "MySQL did not become available in time." >&2
            exit 1
        fi

        sleep 1
    done
}

if [ ! -f .env ]; then
    cp .env.example .env
fi

if [ ! -d vendor ]; then
    composer install --no-interaction --prefer-dist
fi

if ! grep -q '^APP_KEY=base64:' .env; then
    php artisan key:generate --force
fi

wait_for_mysql

php artisan migrate --force

exec "$@"
