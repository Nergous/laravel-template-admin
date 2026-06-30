#!/bin/sh
set -e

cd /app

# -----------------------------------------------------------------------------
#  Control variables (can be overridden in the service environment):
#    RUN_MIGRATIONS=true   — run `migrate --force` on start (web: yes, queue/scheduler: no)
#    RUN_SEEDS=false       — run `db:seed --force`. OFF BY DEFAULT:
#                            the seeder creates DEMO users, not needed in production.
#    OPTIMIZE=auto         — cache config/route/view. auto = only if APP_ENV != local.
#    WAIT_FOR_DB=true      — wait for the DB to be ready before migrations (for the compose stack).
# -----------------------------------------------------------------------------

# storage/cache directories (a host bind-mount may be empty on the first run)
mkdir -p \
    storage/app/public \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

# Fix permissions only if we are root (the dev image already runs as www-data).
if [ "$(id -u)" = "0" ]; then
    chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
fi

# In the dev image vendor/ and the frontend build are mounted empty via bind-mount —
# install the dependencies in place if they aren't there yet.
if [ "${APP_ENV:-}" = "local" ]; then
    [ -f vendor/autoload.php ] || composer install --no-interaction --prefer-dist
fi

# APP_KEY — mandatory. The behavior depends on the environment:
#   local      — generate it automatically (idempotent: an existing key is left untouched).
#   production — do NOT generate on the fly: the key must persist across restarts, otherwise
#                sessions and encryption break (a new key on every start). Fail fast.
if [ -z "${APP_KEY:-}" ] && ! grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
    if [ "${APP_ENV:-production}" = "local" ]; then
        php artisan key:generate --force --no-interaction || true
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
if [ "${WAIT_FOR_DB:-true}" = "true" ] && [ "${DB_CONNECTION:-sqlite}" != "sqlite" ]; then
    echo "Waiting for the DB to be ready (${DB_CONNECTION}@${DB_HOST:-?}:${DB_PORT:-?})..."
    tries=0
    until php artisan db:show --quiet >/dev/null 2>&1 || [ "$tries" -ge 30 ]; do
        tries=$((tries + 1))
        sleep 2
    done
fi

# public/storage -> storage/app/public (symlink for serving media)
php artisan storage:link --force --no-interaction || true

# Migrations (web only; queue/scheduler start with RUN_MIGRATIONS=false)
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    php artisan migrate --force --no-interaction

    # The structural RBAC (permission catalog + granting it to the superadmin) is needed in ANY
    # environment: without it an admin created by the command/seeder ends up WITHOUT permissions and gets a 403
    # in every section. This is NOT demo data — so it is always seeded together with the
    # migrations, regardless of RUN_SEEDS. Idempotent (firstOrCreate).
    php artisan db:seed --class='Database\Seeders\RolePermissionSeeder' --force --no-interaction
fi

# Demo/real USERS (UserSeeder) — separate, behind a flag. OFF BY DEFAULT:
# in production the admin is usually created via `php artisan app:create-admin` (which
# also seeds RBAC itself). RUN_SEEDS=true + ADMIN_PASSWORD creates a real admin
# automatically; in local — two test users.
if [ "${RUN_SEEDS:-false}" = "true" ]; then
    php artisan db:seed --class='Database\Seeders\UserSeeder' --force --no-interaction
fi

# Prod caches (config/route/view). In local we don't cache — it gets in the way of hot-reload.
case "${OPTIMIZE:-auto}" in
    true) DO_CACHE=1 ;;
    false) DO_CACHE=0 ;;
    *) [ "${APP_ENV:-production}" != "local" ] && DO_CACHE=1 || DO_CACHE=0 ;;
esac
if [ "${DO_CACHE:-0}" = "1" ]; then
    php artisan config:cache --no-interaction || true
    php artisan route:cache  --no-interaction || true
    php artisan view:cache   --no-interaction || true
fi

# Privilege drop: the entrypoint runs as root (needed to chown the mounted
# volumes on the first run), but the app/worker itself we run as www-data.
# su-exec is installed in the image (see Dockerfile). In the dev image we are already www-data — exec directly.
if [ "$(id -u)" = "0" ] && command -v su-exec >/dev/null 2>&1; then
    exec su-exec www-data "$@"
fi

exec "$@"
