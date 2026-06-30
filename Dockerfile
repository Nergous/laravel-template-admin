# syntax=docker/dockerfile:1.7

# =============================================================================
#  Laravel Admin Template — multi-stage image.
#
#  Runtime: FrankenPHP (Caddy + PHP-FPM in one process, optional worker mode).
#  Stages:  vendor  → composer dependencies (without dev)
#           assets  → frontend build (Vite/Vue)
#           base    → shared runtime with PHP extensions
#           prod    → final image (default)
#           dev     → development image (with dev dependencies, no build)
#
#  Build:   docker build --target prod -t laravel-admin .
#           docker build --target dev  -t laravel-admin:dev .
# =============================================================================

# ---------- composer vendor (prod, without dev dependencies) ----------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev --no-interaction --no-scripts \
        --prefer-dist --no-autoloader

# ---------- vite build (frontend) ----------
FROM node:20-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.js jsconfig.json ./
COPY resources ./resources
COPY public ./public
RUN npm run build

# ---------- base runtime (shared base for prod and dev) ----------
FROM dunglas/frankenphp:php8.4-alpine AS base

# Extensions covering the full range of DBs/drivers the project supports:
#   pdo_mysql  — MySQL/MariaDB (stack default)
#   pdo_pgsql  — PostgreSQL
#   pdo_sqlite — SQLite (.env.example default, tests)
#   redis      — cache/sessions/queues
#   gd         — image optimization (ImageOptimizer -> WebP)
#   intl       — localization (RU project)
#   bcmath/pcntl/opcache/zip/mbstring — Laravel runtime + queue worker
RUN install-php-extensions \
        pdo_mysql \
        pdo_pgsql \
        pdo_sqlite \
        redis \
        gd \
        intl \
        bcmath \
        pcntl \
        zip \
        mbstring \
        opcache

# composer is needed for dump-autoload against the final paths (prod) and for installing in dev
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Unprivileged application user (uid/gid 1000 — compatible with bind-mount).
# The FrankenPHP image already contains www-data; we give it a predictable uid, and the
# right to bind privileged ports isn't needed — we listen on :8000.
RUN set -eux; \
    apk add --no-cache shadow su-exec; \
    usermod -u 1000 www-data 2>/dev/null || true; \
    groupmod -g 1000 www-data 2>/dev/null || true

WORKDIR /app

COPY docker/php.ini      /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/Caddyfile    /etc/frankenphp/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# FrankenPHP listens on this port (overridden via SERVER_NAME in compose)
ENV SERVER_NAME=":8000"
EXPOSE 8000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]

# ---------- dev (code bind-mount, dev dependencies, no pre-build) ----------
FROM base AS dev
ENV APP_ENV=local
# The code is mounted via bind-mount from compose.dev.yaml; vendor/build are installed
# in place by the entrypoint on the first run. The entrypoint starts as root
# (chown bind-mount/volumes), then drops privileges to www-data via su-exec.

# ---------- prod (final self-contained image) ----------
FROM base AS prod
ENV APP_ENV=production

# Copy the application and the build-stage artifacts
COPY . /app
COPY --from=vendor /app/vendor       /app/vendor
COPY --from=assets /app/public/build /app/public/build

# Authoritative classmap against the final paths + permissions on storage/cache
RUN set -eux; \
    composer dump-autoload --optimize --classmap-authoritative --no-dev; \
    mkdir -p storage/framework/cache storage/framework/sessions \
             storage/framework/views storage/logs bootstrap/cache; \
    chown -R www-data:www-data /app

# The container starts as root to chown the mounted storage volume on the first
# run; entrypoint.sh then drops privileges to www-data (su-exec) before
# starting FrankenPHP/worker. See docker/entrypoint.sh.
