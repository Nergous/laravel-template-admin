# syntax=docker/dockerfile:1.7

# =============================================================================
#  Laravel Admin Template — multi-stage образ.
#
#  Runtime: FrankenPHP (Caddy + PHP-FPM в одном процессе, опц. worker-режим).
#  Стадии:  vendor  → composer-зависимости (без dev)
#           assets  → сборка фронта (Vite/Vue)
#           base    → общий runtime с PHP-расширениями
#           prod    → финальный образ (по умолчанию)
#           dev     → образ для разработки (с dev-зависимостями, без сборки)
#
#  Сборка:  docker build --target prod -t laravel-admin .
#           docker build --target dev  -t laravel-admin:dev .
# =============================================================================

# ---------- composer vendor (prod, без dev-зависимостей) ----------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev --no-interaction --no-scripts \
        --prefer-dist --no-autoloader

# ---------- vite build (фронт) ----------
FROM node:20-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.js jsconfig.json ./
COPY resources ./resources
COPY public ./public
RUN npm run build

# ---------- base runtime (общая основа для prod и dev) ----------
FROM dunglas/frankenphp:php8.4-alpine AS base

# Расширения под весь спектр БД/драйверов, которые поддерживает проект:
#   pdo_mysql  — MySQL/MariaDB (дефолт стека)
#   pdo_pgsql  — PostgreSQL
#   pdo_sqlite — SQLite (дефолт .env.example, тесты)
#   redis      — кэш/сессии/очереди
#   gd         — оптимизация изображений (ImageOptimizer -> WebP)
#   intl       — локализация (проект RU)
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

# composer нужен для dump-autoload по финальным путям (prod) и установки в dev
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Непривилегированный пользователь приложения (uid/gid 1000 — совместимо с bind-mount).
# Образ FrankenPHP уже содержит www-data; задаём ему предсказуемый uid и право
# биндить привилегированные порты не требуется — слушаем :8000.
RUN set -eux; \
    apk add --no-cache shadow su-exec; \
    usermod -u 1000 www-data 2>/dev/null || true; \
    groupmod -g 1000 www-data 2>/dev/null || true

WORKDIR /app

COPY docker/php.ini      /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/Caddyfile    /etc/frankenphp/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# FrankenPHP слушает на этом порту (переопределяется через SERVER_NAME в compose)
ENV SERVER_NAME=":8000"
EXPOSE 8000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]

# ---------- dev (bind-mount кода, dev-зависимости, без предсборки) ----------
FROM base AS dev
ENV APP_ENV=local
# Код монтируется bind-mount'ом из compose.dev.yaml; vendor/build ставятся
# на месте через entrypoint при первом запуске. Entrypoint стартует от root
# (chown bind-mount/томов), затем понижает привилегии до www-data через su-exec.

# ---------- prod (финальный самодостаточный образ) ----------
FROM base AS prod
ENV APP_ENV=production

# Копируем приложение и артефакты стадий сборки
COPY . /app
COPY --from=vendor /app/vendor       /app/vendor
COPY --from=assets /app/public/build /app/public/build

# Авторитативный classmap по финальным путям + права на storage/cache
RUN set -eux; \
    composer dump-autoload --optimize --classmap-authoritative --no-dev; \
    mkdir -p storage/framework/cache storage/framework/sessions \
             storage/framework/views storage/logs bootstrap/cache; \
    chown -R www-data:www-data /app

# Контейнер стартует от root для chown смонтированного storage-тома на первом
# запуске; entrypoint.sh затем понижает привилегии до www-data (su-exec) перед
# запуском FrankenPHP/worker. См. docker/entrypoint.sh.
