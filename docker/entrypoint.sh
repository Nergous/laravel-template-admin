#!/bin/sh
set -e

cd /app

# -----------------------------------------------------------------------------
#  Control variables (can be overridden in the service environment):
#    RUN_MIGRATIONS=false  — opt in to migrations for first boot/releases.
#    RUN_SEEDS=false       — include initial users when the database is empty.
#                            OFF BY DEFAULT: local users have demo passwords.
#    OPTIMIZE=auto         — cache config/route/view. auto = only if APP_ENV != local.
#    WAIT_FOR_DB=true      — wait for the DB to be ready before migrations (for the compose
#                            stack); the container exits with an error if it never answers.
#
#  Both the prod and the dev image start this script as root (to fix ownership of
#  mounted volumes), run every artisan command as www-data and finally drop to
#  www-data with su-exec, so no root-owned log/cache file is left behind.
# -----------------------------------------------------------------------------

# Runs a command as www-data when the script runs as root, as-is otherwise.
as_app() {
    if [ "$(id -u)" = "0" ] && command -v su-exec >/dev/null 2>&1; then
        su-exec www-data "$@"
    else
        "$@"
    fi
}

# storage/cache directories (a host bind-mount or a fresh volume may be empty).
# storage/app/backups is where app:db-backup writes and where a dump is copied
# before app:db-restore (docs/deploy.md).
mkdir -p \
    storage/app/public \
    storage/app/private \
    storage/app/backups \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

# In the dev image vendor/ is an empty named volume on the first run — install the
# dependencies in place. Runs before the ownership fix below, so files written by
# composer's post-autoload scripts (bootstrap/cache/*.php) are handed to www-data too.
if [ "${APP_ENV:-}" = "local" ]; then
    [ -f vendor/autoload.php ] || composer install --no-interaction --prefer-dist
fi

# Ownership (only when started as root). Only entries that do not belong to
# www-data yet are changed — a fresh volume, a restored archive, or files left
# by `docker compose exec app php artisan ...` (root by default; prefer
# `exec -u www-data`). Unlike chown -R this writes nothing on an ordinary
# start: the media library is only read (stat), not re-owned file by file.
if [ "$(id -u)" = "0" ]; then
    find storage bootstrap/cache \( ! -user www-data -o ! -group www-data \) \
        -exec chown -h www-data:www-data {} +
fi

# APP_KEY — mandatory. The behavior depends on the environment:
#   local      — generate it automatically (idempotent: an existing key is left untouched).
#   production — do NOT generate on the fly: the key must persist across restarts, otherwise
#                sessions and encryption break (a new key on every start). Fail fast.
if [ -z "${APP_KEY:-}" ] && ! grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
    if [ "${APP_ENV:-production}" = "local" ]; then
        as_app php artisan key:generate --force --no-interaction || true
    else
        echo "ERROR: APP_KEY is not set in the environment." >&2
        echo "Generate a key and put it into the env (env_file/.env):" >&2
        echo "    php artisan key:generate --show" >&2
        echo "Without a persistent APP_KEY, sessions and encrypted data break." >&2
        exit 1
    fi
fi

# Guard against a "silent" SQLite in production. If APP_ENV=production but the
# default DB_CONNECTION=sqlite (from .env.example) is still set, the DB_* were most likely forgotten.
# Then the SQLite file is created in the immutable image layer (not on a volume) and loses
# its data when the container is recreated, while a configured MariaDB sits idle next to it.
# Better to fail explicitly. A deliberate SQLite on a persistent volume — ALLOW_SQLITE_IN_PRODUCTION=true.
if [ "${APP_ENV:-production}" = "production" ] \
    && [ "${DB_CONNECTION:-sqlite}" = "sqlite" ] \
    && [ "${ALLOW_SQLITE_IN_PRODUCTION:-false}" != "true" ]; then
    echo "ERROR: APP_ENV=production with DB_CONNECTION=sqlite." >&2
    echo "Looks like DB_* are not set — .env still has the default sqlite. In a container it is" >&2
    echo "ephemeral (lost when the container is recreated). Set DB_CONNECTION=mariadb" >&2
    echo "and DB_HOST/DB_*, or, if a SQLite on a persistent volume is intentional, set" >&2
    echo "ALLOW_SQLITE_IN_PRODUCTION=true." >&2
    exit 1
fi

# Wait for the DB to be ready (only when there is a configured connection, not sqlite).
# Starting without a database would only fail later in a less obvious way.
if [ "${WAIT_FOR_DB:-true}" = "true" ] && [ "${DB_CONNECTION:-sqlite}" != "sqlite" ]; then
    echo "Waiting for the DB to be ready (${DB_CONNECTION}@${DB_HOST:-?}:${DB_PORT:-?})..."
    tries=0
    until as_app php artisan db:show --quiet >/dev/null 2>&1; do
        tries=$((tries + 1))
        if [ "$tries" -ge 30 ]; then
            echo "ERROR: the database ${DB_CONNECTION}@${DB_HOST:-?}:${DB_PORT:-?} did not answer within 60 seconds." >&2
            echo "Check DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/DB_PASSWORD and that the db service is up," >&2
            echo "or set WAIT_FOR_DB=false to start without waiting." >&2
            exit 1
        fi
        sleep 2
    done
fi

# public/storage -> storage/app/public (symlink for serving media). The prod image
# already ships it (the code there is read-only for www-data); dev creates it once.
[ -L public/storage ] || as_app php artisan storage:link --force --no-interaction || true

# Migrations (web only; queue/scheduler start with RUN_MIGRATIONS=false).
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    as_app php artisan migrate --force --no-interaction
fi

# Base RBAC is initialized only for an empty database, including on later migrations.
# RUN_SEEDS also includes initial users: two demo accounts locally, or a production
# admin if ADMIN_PASSWORD is configured. Existing data is never re-seeded on restart.
if [ "${RUN_SEEDS:-false}" = "true" ]; then
    as_app php artisan app:seed-fresh --users --no-interaction
elif [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    as_app php artisan app:seed-fresh --no-interaction
fi

# Prod caches (config/route/view). In local we don't cache — it gets in the way of hot-reload.
case "${OPTIMIZE:-auto}" in
    true) DO_CACHE=1 ;;
    false) DO_CACHE=0 ;;
    *) [ "${APP_ENV:-production}" != "local" ] && DO_CACHE=1 || DO_CACHE=0 ;;
esac
if [ "${DO_CACHE:-0}" = "1" ]; then
    as_app php artisan config:cache --no-interaction || true
    as_app php artisan route:cache  --no-interaction || true
    as_app php artisan view:cache   --no-interaction || true
fi

# Privilege drop: the server/worker itself runs as www-data (su-exec is installed
# in the image, see Dockerfile). Started without root (e.g. user: in compose) — exec directly.
if [ "$(id -u)" = "0" ] && command -v su-exec >/dev/null 2>&1; then
    exec su-exec www-data "$@"
fi

exec "$@"
