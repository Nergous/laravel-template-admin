#!/bin/sh
set -e

cd /app

# -----------------------------------------------------------------------------
#  Управляющие переменные (можно переопределить в окружении сервиса):
#    RUN_MIGRATIONS=true   — выполнить `migrate --force` на старте (web: да, queue/scheduler: нет)
#    RUN_SEEDS=false       — выполнить `db:seed --force`. ПО УМОЛЧАНИЮ ВЫКЛ:
#                            сидер создаёт ДЕМО-пользователей, в production не нужен.
#    OPTIMIZE=auto         — кэшировать config/route/view. auto = только если APP_ENV != local.
#    WAIT_FOR_DB=true      — ждать готовности БД перед миграциями (для compose-стека).
# -----------------------------------------------------------------------------

# storage/cache-директории (bind-mount на хост может быть пустым при первом запуске)
mkdir -p \
    storage/app/public \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

# Права чиним только если мы root (dev-образ запускается уже под www-data).
if [ "$(id -u)" = "0" ]; then
    chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
fi

# В dev-образе vendor/ и сборка фронта монтируются пустыми bind-mount'ом —
# поставим зависимости на месте, если их ещё нет.
if [ "${APP_ENV:-}" = "local" ]; then
    [ -f vendor/autoload.php ] || composer install --no-interaction --prefer-dist
fi

# APP_KEY — обязателен. Поведение зависит от среды:
#   local      — сгенерировать автоматически (idempotent: существующий ключ не трогаем).
#   production — НЕ генерируем на лету: ключ должен персистеть между рестартами, иначе
#                ломаются сессии и шифрование (новый ключ при каждом старте). Fail fast.
if [ -z "${APP_KEY:-}" ] && ! grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
    if [ "${APP_ENV:-production}" = "local" ]; then
        php artisan key:generate --force --no-interaction || true
    else
        echo "ОШИБКА: APP_KEY не задан в окружении." >&2
        echo "Сгенерируйте ключ и впишите его в env (env_file/.env):" >&2
        echo "    php artisan key:generate --show" >&2
        echo "Без постоянного APP_KEY ломаются сессии и зашифрованные данные." >&2
        exit 1
    fi
fi

# Защита от «тихого» SQLite в production. Если APP_ENV=production, но осталось
# дефолтное DB_CONNECTION=sqlite (из .env.example), скорее всего забыли задать DB_*.
# Тогда SQLite-файл создастся в неизменяемом слое образа (не на томе) и потеряет
# данные при пересоздании контейнера, а рядом будет простаивать настроенная MariaDB.
# Лучше упасть явно. Осознанный SQLite на постоянном томе — ALLOW_SQLITE_IN_PRODUCTION=true.
if [ "${APP_ENV:-production}" = "production" ] \
    && [ "${DB_CONNECTION:-sqlite}" = "sqlite" ] \
    && [ "${ALLOW_SQLITE_IN_PRODUCTION:-false}" != "true" ]; then
    echo "ОШИБКА: APP_ENV=production при DB_CONNECTION=sqlite." >&2
    echo "Похоже, не заданы DB_* — в .env остался дефолтный sqlite. В контейнере он" >&2
    echo "эфемерен (теряется при пересоздании контейнера). Настройте DB_CONNECTION=mariadb" >&2
    echo "и DB_HOST/DB_*, либо, если SQLite на постоянном томе осознан, выставьте" >&2
    echo "ALLOW_SQLITE_IN_PRODUCTION=true." >&2
    exit 1
fi

# Дождаться готовности БД (только при наличии настроенного подключения, не sqlite).
if [ "${WAIT_FOR_DB:-true}" = "true" ] && [ "${DB_CONNECTION:-sqlite}" != "sqlite" ]; then
    echo "Ожидание готовности БД (${DB_CONNECTION}@${DB_HOST:-?}:${DB_PORT:-?})..."
    tries=0
    until php artisan db:show --quiet >/dev/null 2>&1 || [ "$tries" -ge 30 ]; do
        tries=$((tries + 1))
        sleep 2
    done
fi

# public/storage -> storage/app/public (symlink для отдачи медиа)
php artisan storage:link --force --no-interaction || true

# Миграции (только на web; queue/scheduler стартуют с RUN_MIGRATIONS=false)
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    php artisan migrate --force --no-interaction

    # Структурный RBAC (каталог прав + выдача суперадмину) нужен в ЛЮБОЙ среде:
    # без него созданный командой/сидером админ окажется БЕЗ прав и получит 403
    # во всех разделах. Это НЕ демо-данные — поэтому сеется всегда вместе с
    # миграциями, независимо от RUN_SEEDS. Идемпотентно (firstOrCreate).
    php artisan db:seed --class='Database\Seeders\RolePermissionSeeder' --force --no-interaction
fi

# Демо/боевые ПОЛЬЗОВАТЕЛИ (UserSeeder) — отдельно, по флагу. ПО УМОЛЧАНИЮ ВЫКЛ:
# в production админа обычно заводят через `php artisan app:create-admin` (он сам
# досевает RBAC). RUN_SEEDS=true + ADMIN_PASSWORD создаст боевого админа
# автоматически; в local — двух тестовых пользователей.
if [ "${RUN_SEEDS:-false}" = "true" ]; then
    php artisan db:seed --class='Database\Seeders\UserSeeder' --force --no-interaction
fi

# Прод-кэши (config/route/view). В local не кэшируем — мешает hot-reload.
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

# Понижение привилегий: entrypoint работает от root (нужно для chown смонтированных
# томов на первом запуске), но само приложение/worker запускаем под www-data.
# su-exec установлен в образе (см. Dockerfile). В dev-образе мы уже www-data — exec напрямую.
if [ "$(id -u)" = "0" ] && command -v su-exec >/dev/null 2>&1; then
    exec su-exec www-data "$@"
fi

exec "$@"
