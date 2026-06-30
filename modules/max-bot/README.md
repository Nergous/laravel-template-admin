# max-bot — внешний бот для платформы MAX

Python-бот с long-polling для мессенджер-платформы [MAX](https://platform-api.max.ru),
построенный на библиотеке `maxapi`. Опциональный модуль шаблона: включается флагом и
поднимается отдельным Docker-сервисом.

`main.py` запускает однопроцессный диспетчер (`dp.start_polling(bot)`) и регистрирует
обработчики событий. Сейчас активны только `bot_started` и `bot_added`; остальные 14
типов предобъявлены в реестре и закомментированы в `handlers/__init__.py` — снимите
комментарий и добавьте обработчик, чтобы включить.

---

## Граница Python ↔ Laravel — это файл + БД, а не HTTP

**Между ботом и админкой нет сетевого API.** Контракт интеграции — два общих ресурса:

```
                 ┌──────────────────────────┐
   читают оба →  │  messages.json (реестр)  │  ← источник правды по кодам/дефолтам
                 └──────────────────────────┘
   Laravel пишет ┌──────────────────────────┐  Python читает
   override   →  │  таблица bot_messages    │  →  (SELECT … is_active=1)
                 └──────────────────────────┘
```

Бот и Laravel **делят одну БД MySQL/MariaDB**. Админка пишет переопределения текстов,
бот их читает. Никаких HTTP-вызовов между ними.

### 1. `messages.json` — общий реестр

Единый источник истины для **кодов**, **меток**, **описаний** и **значений по
умолчанию** сообщений. Массив объектов; схема — в
[`messages.schema.json`](messages.schema.json).

```json
{ "code": "welcome", "label": "Приветствие", "description": "…", "default": "Привет, я бот." }
```

Читается **обеими** сторонами:

- **PHP:** `App\Support\BotMessageCatalog` через `config('bot.registry')`
  (`config/bot.php` → `base_path('modules/max-bot/messages.json')`).
- **Python:** `repositories/messages.py:_load_registry()` (путь — относительно модуля).
  Python дополнительно держит типизированные константы кодов (`WELCOME`, `BOT_ADDED`, …)
  и **падает на старте**, если требуемый код отсутствует в реестре (`RuntimeError`).

> При изменении набора кодов правьте `messages.json` — и сверяйте константы в
> `repositories/messages.py`, иначе они молча разойдутся с файлом.

### 2. Таблица `bot_messages` — переопределения

Миграция `database/migrations/bot/...create_bot_messages_table.php`. Колонки:

| Колонка | Тип | Смысл |
|---|---|---|
| `id` | PK | — |
| `code` | string(128), **unique** | код сообщения из реестра |
| `text` | text | переопределённый текст (инлайн-HTML) |
| `is_active` | bool, default `true` | применять ли переопределение |
| `updated_by` | FK users, nullOnDelete | кто правил |
| `created_at`/`updated_at` | timestamps | — |

Живой контракт:

- **Laravel пишет:** `AdminBotMessageController@update` делает upsert по `code`;
  `@destroy` (маршрут `reset`) удаляет переопределение → возврат к дефолту из реестра.
- **Python читает:** `MessageRepository.get(code)` выполняет
  `SELECT text FROM bot_messages WHERE code=%s AND is_active=1 LIMIT 1` и откатывается
  к `default` из реестра, если строки нет, она пустая или `is_active=0`.

> Таблица создаётся **только при включённом боте**: `BotServiceProvider` подключает
> `migrations/bot/` лишь когда `config('bot.enabled')`.

### 3. Контракт формата текста — только инлайн-HTML

Админ редактирует текст в `NRichText` и хранит **инлайн-HTML**. Бот отправляет его с
`format=ParseMode.HTML` (`handlers/bot_started.py`), а MAX/Telegram понимают только
инлайн-разметку.

Поэтому `BotMessageRequest` санитизирует ввод через `App\Support\BotMessageSanitizer`
(symfony/html-sanitizer, не регулярки). Разрешено:

- инлайн-теги: `<b> <strong> <i> <em> <s> <strike> <u> <code>`;
- ссылки `<a href>` со схемами `https`, `http`, `mailto`, `tg`.

Всё остальное вырезается (блочные/неизвестные теги — с сохранением текста;
`<script>`/`<style>` — вместе с содержимым; `on*=`-обработчики и опасные схемы —
удаляются). При хранении текстов в БД **не закладывайтесь на блочную разметку.**

---

## Конфигурация (env)

Бот читает собственное окружение (`config/config.py`, `Config.from_env`). `.env`
ищется сначала в `modules/max-bot/.env`, затем в корне админки (на уровень выше).

| Переменная | Обяз. | Дефолт | Назначение |
|---|---|---|---|
| `API_TOKEN` | **да** | — | токен бота MAX |
| `BOT_ID` | **да** | — | id бота (int) |
| `DB_DATABASE` | **да** | — | общая с Laravel БД |
| `DB_USERNAME` | **да** | — | пользователь БД |
| `DB_PASSWORD` | **да** | — | пароль БД |
| `API_BASE_URL` | нет | `https://platform-api.max.ru` | базовый URL API MAX |
| `DB_HOST` | нет | `127.0.0.1` | хост БД |
| `DB_PORT` | нет | `3306` | порт БД |
| `MAX_API_RATE_LIMIT_HZ` | нет | `20` | лимит запросов к API MAX, Гц |

Отсутствие обязательной переменной → `ValueError` на старте.

## Включение

Признак на стороне Laravel — `config('bot.enabled')`, включается **любым** из:

- `BOT_ACTIVE=true` (app-слой; локальный запуск);
- `COMPOSE_PROFILES` содержит `bot` (Docker — та же переменная поднимает контейнер `bot`).

Когда выключено: роуты `bot-messages.*` отдают 404 (`EnsureBotEnabled`), миграции
бота не подключаются, права `bot-messages.*` не сидятся, пункт сайдбара скрыт. См.
раздел «Сообщения бота» в корневом [README](../../README.md).

## Запуск (локально, вне Docker)

```bash
cd modules/max-bot
python -m venv venv && source venv/bin/activate   # Windows: venv\Scripts\activate
pip install -r requirements.txt
cp .env.example .env        # заполнить API_TOKEN, BOT_ID, DB_*
python main.py
```

Тесты бота — `pytest` (см. `pytest.ini`, `conftest.py`, `tests/`, `handlers/test_handlers.py`).

## Структура

```
modules/max-bot/
├── main.py              # точка входа: long-polling + воркеры + graceful shutdown
├── messages.json        # общий реестр кодов/дефолтов (источник правды)
├── messages.schema.json # JSON-схема реестра (валидируется в тестах)
├── config/config.py     # загрузка env → dataclass Config
├── handlers/            # обработчики событий (активны bot_started, bot_added)
├── repositories/        # messages.py (реестр + override), db.py (пул aiomysql)
├── utils/               # rate_limiter и пр.
└── log/                 # настройка логирования
```
