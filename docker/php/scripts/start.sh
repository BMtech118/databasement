#!/bin/sh
# Default startup script for Databasement container
# PUID/PGID are set via ENV in Dockerfile (default: 1000)

set -e

if [ "$(id -u)" = "0" ]; then
    # BMT: an agent configured to run as root (PUID=0) starts directly. Remapping
    # the application user to UID 0 with usermod gains nothing and is flagged by
    # EDR (Huntress) as a root backdoor account on every container start.
    if [ -n "${DATABASEMENT_URL:-}" ] && [ "${PUID:-}" = "0" ]; then
        echo "Agent mode (root)"
        exec php artisan agent:run
    fi

    USERHOME=$(grep application /etc/passwd | cut -d ":" -f6)

    echo "Setting up user application with PUID=${PUID} PGID=${PGID}..."
    groupmod -o -g "${PGID}" application
    usermod -o -u "${PUID}" application
    usermod -d "${USERHOME}" application

    if [ "${ENABLE_WEB_SERVER}" != "false" ]; then
        /usr/local/bin/fix-permissions.sh -R application:application /data
    fi
    /usr/local/bin/fix-permissions.sh -R application:application "${USERHOME}"

    if [ -n "${DATABASEMENT_URL:-}" ]; then
        echo "Agent mode"
        exec su -m -s /bin/sh application -c 'exec php artisan agent:run'
    fi

    export SUPERVISOR_USER="root"
    export APP_USER="application"
    exec /usr/bin/supervisord -c /etc/supervisor/supervisord.conf
else
    if [ -n "${DATABASEMENT_URL:-}" ]; then
        echo "Agent mode"
        exec php artisan agent:run
    fi

    echo "Not running as root, skipping permission fix..."
    exec /usr/bin/supervisord -c /etc/supervisor/supervisord-nonroot.conf
fi
