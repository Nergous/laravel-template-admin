# Laravel Admin Template

Шаблон админ-панели на **Laravel 12** + **Inertia 3** + **Vue 3.5** (SPA) с собственной дизайн-системой **nergous-cit**:

- SPA на Inertia + Vue с дизайн-системой **nergous-cit** (тёмная тема, плотность интерфейса, командная палитра) — без рантайм-зависимостей сверх `vue` + `inertia`;
- **RBAC** через [spatie/laravel-permission](https://spatie.be/docs/laravel-permission) — роли, разрешения, гранулярная матрица доступа, UI управления;
- Медиатека с асинхронной загрузкой и WebP-конвертацией (Job `UploadMedia` + очередь);
- Журнал действий (`activity_log` + trait `LogsActivity`) с JSON-diff изменений;
- Настройки приложения (типизированный key/value) и опциональный модуль «Сообщения бота».

Используется как стартовая точка для новых проектов: клонируешь → удаляешь демо-сидеры → добавляешь свои сущности (см. [«Как добавить свою сущность»](#как-добавить-свою-сущность)).

---

## Стек

- **PHP** ^8.2 · **Laravel** ^12
- **Inertia** ^3 (`inertiajs/inertia-laravel`) · **Vue** ^3.5 — SPA (один Blade-шаблон, остальное во Vue)
- **Vite** 7 · дизайн-система **nergous-cit** (`resources/js/lib/nergous-cit`, zero-deps Vue-библиотека)
- **spatie/laravel-permission** ^7 (RBAC)
- **SQLite** по умолчанию (совместимо с MySQL/MariaDB/PostgreSQL)
- Очереди: `database` driver — для асинхронной обработки медиа

---

## Требования (ручная установка)

Для пути через [Docker](#docker) ничего из этого не нужно — расширения и версии вшиты
в образ. Для локального запуска без Docker:

- **PHP** 8.2+ с расширениями `pdo_sqlite` (БД по умолчанию), `gd` со сборкой WebP
  (оптимизация медиа; без неё оригиналы сохраняются без превью — `ImageOptimizer`
  молча отдаёт фолбэк), `mbstring`, `intl`.
- **Composer** 2.
- **Node.js** ≥ 20.19 или ≥ 22.12 (требование Vite 7) + npm.

## Быстрый старт

```bash
git clone <repo> my-new-project
cd my-new-project

cp .env.example .env            # Windows (PowerShell): copy .env.example .env
composer install
npm install
php artisan key:generate

# Создать БД (для SQLite — пустой файл)
touch database/database.sqlite  # Windows (PowerShell): New-Item database/database.sqlite

php artisan migrate --seed      # создаст роли admin/operator и тестовых пользователей
php artisan storage:link        # симлинк public/storage → отдача загруженных медиа (иначе 404)
npm run build
php artisan serve
# в другом терминале:
php artisan queue:work          # обработка загрузок медиа (или QUEUE_CONNECTION=sync — без воркера, см. Troubleshooting)
```

После `--seed` будут доступны:

- `admin@example.com` / `password123` (роль `admin`, все разрешения)
- `operator@example.com` / `password123` (роль `operator`, только медиатека)

**Не используй сидер в production.** Удалить тестовых пользователей: убрать `UserSeeder::class` из `DatabaseSeeder` или вызвать `php artisan app:create-admin` для создания нормального админа и снести через UI.

### Composer-скрипты

| Команда          | Назначение                                                                                                                                                                        |
| ---------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `composer setup` | Установка «под ключ» для SQLite: composer/npm + SQLite-файл + миграции + сиды (роли/права + в `local` демо-пользователи) + `storage:link` + сборка. После неё сразу можно войти под `admin@example.com` / `password123` |
| `composer dev`   | Параллельно: artisan serve, queue listen, pail (логи), vite                                                                                                                       |
| `composer test`  | Сброс кеша конфигурации и запуск PHPUnit (`tests/Feature` и `tests/Unit`)                                                                                                                        |

Фронтенд собирается через Vite: `npm run dev` — dev-сервер с HMR, `npm run build` — production-сборка.

### Создание администратора

```bash
php artisan app:create-admin admin@example.com "Имя администратора"
```

Команда сама досевает базовый RBAC (каталог прав + выдачу их роли суперадмина,
идемпотентно) и назначает роль пользователю. Поэтому она даёт **рабочего** админа
даже на свежей БД без сидов (после голого `migrate`) — без неё роль суперадмина
осталась бы без прав, и админ получал бы 403 во всех разделах.

---

## Docker

Образ — multi-stage на базе **FrankenPHP** (Caddy + PHP 8.4 одним процессом).
Расширения PHP: `pdo_mysql`/`pdo_pgsql`/`pdo_sqlite`, `redis`, `gd` (WebP), `intl`,
`bcmath`, `pcntl`, `zip`, `mbstring`, `opcache` (+ JIT в prod). Приложение работает
от непривилегированного пользователя `www-data`.

### Разработка (hot-reload)

`compose.dev.yaml` поднимает: `app` (FrankenPHP, код смонтирован bind-mount,
правки PHP подхватываются), `vite` (HMR на `:5173`), `queue` (`queue:listen`),
`db` (MariaDB) и `redis`.

```bash
cp .env.example .env          # дефолты (local, debug) подходят как есть
docker compose -f compose.dev.yaml up --build
```

- Приложение: <http://localhost:8000> · Vite HMR: <http://localhost:5173>
- `vendor/` и `node_modules/` ставятся автоматически при первом старте
  (в именованные тома, чтобы не конфликтовать с правами хоста).
- В dev `RUN_SEEDS=true` — создаются демо-роли и тестовые пользователи.

### Production (самодостаточный стек)

`docker-compose.yml` поднимает: `app` (web), `queue` (`queue:work`),
`scheduler` (`schedule:work`), `db` (MariaDB) и `redis` — каждый со своим
healthcheck'ом и named-volume для данных. Код и зависимости вшиты в образ
(immutable), наружу монтируется только `storage`.

```bash
cp .env.example .env
# В .env выставить:
#   APP_ENV=production, APP_DEBUG=false, APP_URL=https://ваш-домен
#   APP_KEY=...                      (обязателен; сгенерируй `php artisan key:generate --show` и впиши.
#                                     В production entrypoint его НЕ генерирует — падает с ошибкой,
#                                     чтобы ключ персистел между рестартами)
#   TRUSTED_PROXIES=<подсеть прокси>  (если за reverse-proxy; см. ниже)
# Раскомментировать блок «Docker-compose» в .env (DB_*/REDIS_* -> хосты db/redis)

docker compose up -d --build
```

- Внешний порт приложения: `APP_PORT` (по умолчанию `8888` → контейнерный `:8000`).
  За TLS-прокси (nginx/traefik) приложение остаётся на HTTP. Чтобы получать реальный
  IP клиента (а не адрес прокси), задайте `TRUSTED_PROXIES` = подсеть/IP прокси; по
  умолчанию прокси не доверяются, поэтому `X-Forwarded-For` нельзя подделать (это
  защищает login-throttle). Cookie сессии в production помечается `Secure` автоматически.
- Миграции выполняются на старте сервиса `app` (`RUN_MIGRATIONS=true`); `queue`
  и `scheduler` их не запускают. `queue:work` не обязателен — если не нужна асинхронная обработка файлов то устанавливается `QUEUE_CONNECTION=sync`.
- **Базовый RBAC** (роли + права) сеется автоматически вместе с миграциями на старте
  `app` — в любой среде. А вот **демо/боевые ПОЛЬЗОВАТЕЛИ** управляются `RUN_SEEDS`
  (по умолчанию `false` — в production не создаются). Заведите админа:
  `docker compose exec -it app php artisan app:create-admin ...` (`-it` обязателен —
  команда интерактивно запрашивает пароль), либо задайте `RUN_SEEDS=true` +
  `ADMIN_PASSWORD`, чтобы боевой админ создался автоматически. Так или иначе админ
  получает полный набор прав из коробки.
- **БД для production — не SQLite.** Раскомментируйте блок `DB_*` в `.env`
  (`DB_CONNECTION=mariadb`, `DB_HOST=db`, …). Если оставить дефолтный
  `DB_CONNECTION=sqlite`, entrypoint **упадёт с понятной ошибкой**: SQLite в контейнере
  эфемерен и потерял бы данные при пересоздании. Осознанный SQLite на постоянном томе —
  `ALLOW_SQLITE_IN_PRODUCTION=true`.
- **Опциональный модуль бота** — сервис `bot` (собирается из `modules/max-bot`)
  поднимается только при `COMPOSE_PROFILES=bot`. Та же переменная включает и
  app-слой бота (`config('bot.enabled')`, см. [«Сообщения бота»](#сообщения-бота-опциональный-модуль)).
- БД (`db`) наружу не публикуется — доступна только внутри сети стека.
  Чтобы использовать внешнюю БД — задайте `DB_HOST` на внешний адрес и уберите
  сервис `db` (+ его `depends_on`).

Полезные команды:

```bash
docker compose logs -f app                       # логи приложения
docker compose exec app php artisan migrate      # ручная миграция
docker compose exec app php artisan tinker       # консоль
docker compose down                              # остановить (тома сохраняются)
docker compose down -v                           # снести вместе с данными
```

---

## Что включено

### Из коробки

| Раздел           | URL                   | Permission          |
| ---------------- | --------------------- | ------------------- |
| Дашборд          | `/admin`              | (только auth)       |
| Пользователи     | `/admin/users`        | `users.view`        |
| Роли             | `/admin/roles`        | `roles.view`        |
| Разрешения       | `/admin/permissions`  | `permissions.view`  |
| Медиатека        | `/admin/media`        | `media.view`        |
| Журнал действий  | `/admin/activity-log` | `activity-log.view` |
| Настройки        | `/admin/settings`     | `settings.view`     |
| Сообщения бота\* | `/admin/bot-messages` | `bot-messages.view` |

> \* — опциональный модуль, доступен только при включённом боте (`config('bot.enabled')`),
> иначе роуты отдают 404, а пункт в сайдбаре скрыт.

> Колонка «Permission» — доступ к разделу (просмотр). Действия гейтятся **гранулярно**: создание/редактирование — `*.create`/`*.edit` (в `FormRequest::authorize()`), удаление — `*.delete` (middleware на destroy-роутах). Для медиа: загрузка — `media.upload`, удаление — `media.delete`. Настройки: запись — `settings.edit`; сообщения бота: правка — `bot-messages.edit`. Если заводишь кастомную роль с `*.view`, но без `*.delete`, серверная защита сработает (403), но кнопки удаления в UI стоит дополнительно скрыть через `can('*.delete')` (см. `resources/js/lib/can.js`).

### Базовые разрешения

Сидер `RolePermissionSeeder` создаёт:

- `users.{view,create,edit,delete}`
- `roles.{view,create,edit,delete}`
- `permissions.{view,create,edit,delete}`
- `media.{view,upload,delete}`
- `activity-log.view`
- `settings.{view,edit}`
- `bot-messages.{view,edit}` — только когда включён модуль бота

Роли: `admin` — все разрешения; `operator` — только медиатека (`media.*`). Обе помечены `is_system`.

Добавлять свои в формате `модуль.действие` — UI ролей группирует чекбоксы по префиксу до точки.

### Настройки

Раздел `/admin/settings` — типизированное key/value-хранилище (`App\Models\Setting`).
Структура задаётся константой `Setting::SCHEMA` (группа → ключ → `[тип, дефолт]`): группы
`general` (имя приложения, таймзона, favicon), `seo` (мета-теги, og-image, индексация) и
`security` (время жизни сессии, лимит попыток входа `login_throttle`). Значения кэшируются
и сбрасываются при записи; валидация — `UpdateSettingsRequest`.

> **Под свой проект:** дефолты брендинга (`app_name`, `meta_title_template`,
> `canonical_domain`) в `Setting::SCHEMA` — нейтральные плейсхолдеры; замени их здесь
> или через UI. Дашборд `/admin` тоже демонстрационный: его KPI завязаны на сущности
> шаблона — перепиши под свой домен (`AdminDashboardController` + `pages/Dashboard.vue`).

### Сообщения бота (опциональный модуль)

Раздел `/admin/bot-messages` позволяет администратору переопределять тексты, которые
отправляет внешний бот. Источник правды по кодам/меткам/дефолтам — общий JSON-реестр
`modules/max-bot/messages.json` (`config('bot.registry')`, читается классом
`App\Support\BotMessageCatalog`). Правки сохраняются как переопределения в таблице
`bot_messages` (`App\Models\BotMessage`); «сброс» удаляет переопределение и возвращает
дефолт из реестра.

Модуль **выключаемый**. Признак — `config('bot.enabled')`, который включается любой из:

- `BOT_ACTIVE=true` (app-слой; локальный запуск). В `.env.example` он по умолчанию
  `false`, то есть из коробки модуль выключён — поставьте `BOT_ACTIVE=true`, чтобы включить.
- `COMPOSE_PROFILES` содержит `bot` (Docker — та же переменная поднимает контейнер `bot`).

Когда выключен: middleware `bot.enabled` (`EnsureBotEnabled`) отдаёт на роутах 404,
`BotServiceProvider` не подключает миграции `database/migrations/bot/`, права
`bot-messages.*` не сидятся, а пункт сайдбара скрыт.

> **Включаете бот позже?** Права `bot-messages.*` сидятся только при включённом боте.
> После включения пересейте их (идемпотентно):
> `php artisan db:seed --class=Database\Seeders\RolePermissionSeeder` — иначе раздел
> бота будет недоступен (роуты под `permission:bot-messages.view`). Миграции бота
> накатятся сами при включённом флаге: `php artisan migrate`.

### Возможности UI

- **Тёмная тема** и **плотность интерфейса** (S/M/L) через composable `useTheme`;
  выбор хранится в `localStorage`, анти-флэш-скрипт в `admin.blade.php` применяет их
  до загрузки CSS. Переключатели — в сайдбаре и топбаре.
- **Командная палитра и глобальный поиск** по `Ctrl/Cmd+K` (composable `useHotkeys`,
  раскладко-независимый; новые горячие клавиши добавляются через тот же composable).
- **Тосты** для flash-сообщений (`success`/`error`/`warning`/`info`) через `useToast`/`NToaster`.
- **Уведомления** — последние действия журнала за 24 часа (бейдж на колокольчике в топбаре).
- **Доступность**: focus-trap и блокировка прокрутки в оверлеях (модалки/дроверы/палитра),
  Escape для закрытия, ARIA-разметка, поддержка `prefers-reduced-motion`.
- **Bulk-операции** в таблицах (массовое удаление медиа; восстановление/полное удаление
  пользователей из корзины) и server-side сортировка/фильтрация.

---

## Как добавить свою сущность

Краткий рецепт под текущий стек (Inertia + Vue):

1. **Миграция + модель + фабрика.** Подключи нужные трейты (у каждого есть
   предусловие — не пропусти, иначе словишь ошибку в рантайме):
   - `SoftDeletes` — корзина; добавь `$table->softDeletes()` в миграцию.
   - `LogsActivity` — журнал + JSON-diff. Чтобы тип субъекта читаемо назывался в
     журнале, зарегистрируй класс в `config/audit.php` (`subjects`: FQCN → короткий
     ключ) и добавь перевод ключа в `lang/ru/activity.php` (иначе откат на имя класса).
   - `TracksAuthor` — автозаполнение `created_by`/`updated_by`. **Требует колонок** в
     миграции, иначе INSERT упадёт:
     `$table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();`
     и так же `updated_by`.
   - `HasSearch` — даёт хелпер `scopeSearchLike()`, но **сам scope объяви на модели**
     (без этого `->search()` не существует):
     `public function scopeSearch($q, $s) { return $this->scopeSearchLike($q, $s, ['name', /* ... */]); }`

   Заведи фабрику `database/factories/XxxFactory.php` — на неё опираются сидеры и тесты
   (эталон — `database/factories/UserFactory.php`).
2. **Сервис** `App\Services\XxxService` — **для сложной** доменной логики и оркестрации
   (транзакции, sync связей, журналирование, инварианты): тогда контроллер остаётся
   тонким — валидирует вход и рендерит, а решения принимает сервис. Для простого CRUD
   сервис не обязателен: контроллер может работать с моделью напрямую (как
   `AdminBotMessageController`/`AdminSettingsController`). Граница «когда сервис» —
   в [CLAUDE.md](CLAUDE.md). Нарушения правил бросай через
   `Illuminate\Validation\ValidationException::withMessages([...])` — Laravel сам вернёт
   `redirect back` с ошибкой под нужным ключом, а контроллеру не нужен `try/catch`.
   Эталоны сервиса — `UserService`/`RoleService`/`PermissionService`/`MediaService`
   (тестируются юнит-тестами в `tests/Unit/Services/` без HTTP-слоя).
3. **Контроллер** `App\Http\Controllers\Admin\AdminXxxController` — отдаёт Inertia-ответы
   (`Inertia::render('Xxx/Index', [...])`), инжектит сервис в конструктор и вызывает
   его методы; `FormRequest` с проверкой прав в `authorize()`.
4. **Маршруты** в `routes/web.php`. Просмотр гейтится middleware на группе
   (`Route::middleware('permission:xxx.view')->group(...)`), а `store`/`update` живут
   **в той же `*.view`-группе**, но реально гейтятся в `FormRequest::authorize()` по
   HTTP-методу (POST → `xxx.create`, PUT/PATCH → `xxx.edit`). Удаление — отдельной
   группой/middleware под `xxx.delete`. (Подробнее — шапка `routes/web.php` и CLAUDE.md.)
5. **Permissions** — добавь в `RolePermissionSeeder` (схема `модуль.действие`) или через
   UI `/admin/permissions`. Метку группы для матрицы доступа добавь в
   `lang/ru/permissions.php` (`resources`: префикс → подпись), иначе группа покажется
   нелокализованным ключом.
6. **Inertia-страница** — Vue-компонент(ы) под `resources/js/admin/pages/Xxx/` (например `Index.vue`),
   импортируй элементы дизайн-системы из барреля `@/lib/nergous-cit`, оберни контент в `AdminLayout`.
   Эталон мелкой CRUD-формы в дровере — `pages/Users/Index.vue` + `Partials/Form.vue`
   (учти: страница тянет локальные, не-DS хелперы `ConfirmModal`/`useConfirm`/`can`/`format`
   из `resources/js/admin/`, а URL заданы строками — Ziggy в проекте нет).
7. **Пункт сайдбара** — добавь запись в массив `sections` в `resources/js/admin/layouts/AdminLayout.vue`
   (поле `perm` гейтит видимость через `can()`); при необходимости — счётчик для бейджа в
   `App\Http\Middleware\HandleInertiaRequests::share()` (`counts`, гейти по `*.view`).
8. **Тесты** — по образцу `tests/Feature/UserManagementTest.php` (HTTP + права) и
   `tests/Unit/Services/UserServiceTest.php` (доменная логика без HTTP).
9. **Глобальный поиск (опционально)** — чтобы сущность находилась через `Ctrl/Cmd+K`,
   добавь блок по образцу в `AdminSearchController` (там же — инлайн-`can()` на сущность).

---

## Безопасность (HTTP-заголовки и CSP)

Middleware `App\Http\Middleware\SecurityHeaders` (подключён в `bootstrap/app.php` к web-группе) добавляет ко всем ответам:

| Заголовок                   | Значение                                   | Когда                                                   |
| --------------------------- | ------------------------------------------ | ------------------------------------------------------- |
| `X-Frame-Options`           | `DENY`                                     | всегда (анти-кликджекинг)                               |
| `X-Content-Type-Options`    | `nosniff`                                  | всегда                                                  |
| `Referrer-Policy`           | `strict-origin-when-cross-origin`          | всегда                                                  |
| `X-XSS-Protection`          | `0`                                        | всегда (отключает баговый legacy-аудитор; защищает CSP) |
| `Permissions-Policy`        | `camera=(), microphone=(), geolocation=()` | всегда                                                  |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains`      | только при HTTPS                                        |
| `Content-Security-Policy`   | строгая, с per-request **nonce**           | **только в `production`**                               |

### Почему CSP включается только в production

Локальный `npm run dev` (Vite HMR) грузит клиент и сокет со стороннего origin (`http://localhost:5173`, `ws://`), который строгий `script-src 'self'` заблокировал бы. В production ассеты собраны в `/build` (same-origin), поэтому политика работает. Проверять CSP нужно на собранном билде (`npm run build` + `APP_ENV=production`).

### Как nonce связан с inline-скриптами

`SecurityHeaders` вызывает `Vite::useCspNonce()` до рендера view — Laravel сам проставляет nonce в `@vite`- и `@inertiaHead`-теги. В корневом шаблоне `resources/views/admin.blade.php` есть единственный inline-`<script>` (анти-флэш темы и плотности, выполняется до загрузки CSS) — ему вручную проставлен nonce через `{{ \Illuminate\Support\Facades\Vite::cspNonce() }}`. **Любой новый inline-`<script>` без `nonce` в production выполняться не будет** — либо добавь ему атрибут `nonce`, либо вынеси код в JS/Vue (вся логика интерфейса и так живёт во Vue).

### Как настроить под себя

Всё правится в одном файле — `app/Http/Middleware/SecurityHeaders.php`:

- **Внешние ресурсы** (CDN, шрифты Google, аналитика, S3-картинки) — добавь источник в нужную директиву метода `contentSecurityPolicy()`, напр. `img-src 'self' data: https://cdn.example.com`.
- **HSTS preload** — если домен готов к попаданию в preload-список браузеров, допиши `; preload` к значению `Strict-Transport-Security` (необратимо на годы — см. hstspreload.org).
- **Встраивание в iframe** — если панель должна открываться в iframe доверенного домена, замени `X-Frame-Options: DENY` и `frame-ancestors 'none'` на конкретный origin.
- **Ужесточить `style-src`** — сейчас `'unsafe-inline'` (из-за атрибутов `style=""`). Чтобы убрать: вынеси инлайн-стили в классы и поменяй на `style-src 'self'`.
- **Проверка**: `curl -sI https://твой-домен/admin/login | grep -i -E 'content-security|x-frame|strict-transport'`.

---

## Troubleshooting

- **Загруженные картинки отдают 404** — не создан симлинк `public/storage`. Выполни
  `php artisan storage:link` (в Docker делается автоматически). **На Windows** создание
  симлинка требует включённого Developer Mode или запуска терминала от администратора —
  иначе этот шаг (и `composer setup`) упадёт. Как обходной путь — junction:
  `mklink /J public\storage storage\app\public`.
- **Медиа загружается, но не конвертируется в WebP / нет превью** — не запущен воркер
  очереди. Подними `php artisan queue:work` (или `composer dev`, который включает воркер).
  Не хочешь держать воркер вообще — поставь `QUEUE_CONNECTION=sync`: обработка пойдёт
  прямо в HTTP-запросе, без отдельного процесса. Размен: большой файл держит запрос
  (лимит времени джобы `$timeout` в `sync` не действует), а ошибка обработки вернётся
  как 500 вместо тихого ретрая/ухода в `failed_jobs`. Удобно для простого хостинга.
- **Превью не генерируются даже с воркером** — PHP-расширение `gd` собрано без WebP:
  `ImageOptimizer` молча сохраняет оригинал без превью. Доставь `gd` со сборкой WebP.
- **CSP не видно в dev** — она включается **только** в `production` (в dev Vite HMR грузится
  со стороннего origin). Проверяй на собранном билде: `npm run build` + `APP_ENV=production`.
- **`npm run build` падает с непонятной ошибкой** — версия Node ниже требований Vite 7
  (нужен ≥ 20.19 / 22.12).

## Структура

```
laravel-template-admin/
├── app/
│   ├── Console/Commands/        # CreateAdmin (app:create-admin), BackfillThumbnails
│   ├── Http/
│   │   ├── Controllers/Admin/   # Dashboard, Users, Roles, Permissions, Media, ActivityLog, Settings, BotMessage, Search
│   │   ├── Controllers/Auth/    # LoginController
│   │   ├── Middleware/          # SecurityHeaders, HandleInertiaRequests (shared props), EnsureBotEnabled
│   │   ├── Requests/            # FormRequest для валидации
│   │   └── Sorts/               # Стратегии сортировки таблиц (UserSort, MediaSort)
│   ├── Jobs/UploadMedia.php     # Асинхронная обработка медиа (WebP + превью)
│   ├── Models/                  # User, Media, ActivityLog, Setting, BotMessage
│   ├── Providers/               # AppServiceProvider, BotServiceProvider (опц. модуль бота)
│   ├── Services/ImageOptimizer  # WebP-конвертация и thumbnails
│   ├── Support/BotMessageCatalog # чтение JSON-реестра сообщений бота
│   └── Traits/                  # LogsActivity, HasSearch, TracksAuthor
├── config/{permission,inertia,bot,audit,rbac}.php  # audit: subjects+retention журнала; rbac: имя суперадмина
├── database/
│   ├── migrations/              # users, media, activity_log, settings, permission_tables, …
│   │   └── bot/                 # миграции модуля бота (грузятся только при включённом боте)
│   └── seeders/                 # RolePermissionSeeder, UserSeeder
├── lang/ru/                     # activity.php, permissions.php (локализация)
├── modules/max-bot/             # опциональный внешний бот (источник правды — messages.json)
├── resources/
│   ├── js/
│   │   ├── admin/               # Inertia-приложение: app.js, pages/, layouts/, components/, composables/
│   │   └── lib/
│   │       ├── nergous-cit/     # дизайн-система (Vue-компоненты, токены, composables)
│   │       ├── can.js           # can(perm) — UI-проверка прав по shared-пропам
│   │       └── format.js, swatch.js  # форматирование и цвет-хеш ролей
│   └── views/admin.blade.php    # единственный Blade-шаблон — точка входа Inertia
└── routes/web.php               # только админка
```

---

## Документация

- **API-поверхность** (4 JSON-ручки и их контракты, помаршрутная матрица разрешений и
  каталог ошибок, соглашения об ответах, снимок `route:list`) — в **[docs/](docs/README.md)**.
- **Модуль бота** (контракт Python ↔ Laravel) — в
  **[modules/max-bot/README.md](modules/max-bot/README.md)**.

**Health-check.** Приложение отдаёт `GET /up` (стандартный health-роут Laravel,
`bootstrap/app.php`) — возвращает 200, если оно поднялось. Используется в
Docker-healthcheck'ах сервисов; подходит для liveness/readiness-проб балансировщика.

---

## Лицензия

MIT.
</content>
</invoke>
