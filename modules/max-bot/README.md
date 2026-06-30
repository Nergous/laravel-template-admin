# max-bot — external bot for the MAX platform

A Python long-polling bot for the [MAX](https://platform-api.max.ru) messaging platform,
built on the `maxapi` library. An optional module of the template: enabled by a flag and
run as a separate Docker service.

`main.py` starts a single-process dispatcher (`dp.start_polling(bot)`) and registers
event handlers. Currently only `bot_started` and `bot_added` are active; the other 14
types are pre-declared in the registry and commented out in `handlers/__init__.py` —
uncomment one and add a handler to enable it.

---

## The Python ↔ Laravel boundary is a file + DB, not HTTP

**There is no network API between the bot and the admin panel.** The integration contract
is two shared resources:

```
                 ┌──────────────────────────┐
   read by both →│  messages.json (registry)│  ← source of truth for codes/defaults
                 └──────────────────────────┘
   Laravel writes┌──────────────────────────┐  Python reads
   override   →  │  bot_messages table      │  →  (SELECT … is_active=1)
                 └──────────────────────────┘
```

The bot and Laravel **share a single MySQL/MariaDB database**. The admin panel writes text
overrides, the bot reads them. No HTTP calls between them.

### 1. `messages.json` — the shared registry

The single source of truth for message **codes**, **labels**, **descriptions**, and
**default values**. An array of objects; the schema is in
[`messages.schema.json`](messages.schema.json).

```json
{ "code": "welcome", "label": "Приветствие", "description": "…", "default": "Привет, я бот." }
```

Read by **both** sides:

- **PHP:** `App\Support\BotMessageCatalog` via `config('bot.registry')`
  (`config/bot.php` → `base_path('modules/max-bot/messages.json')`).
- **Python:** `repositories/messages.py:_load_registry()` (path relative to the module).
  Python additionally keeps typed code constants (`WELCOME`, `BOT_ADDED`, …)
  and **fails on startup** if a required code is missing from the registry (`RuntimeError`).

> When changing the set of codes, edit `messages.json` — and reconcile the constants in
> `repositories/messages.py`, otherwise they will silently diverge from the file.

### 2. The `bot_messages` table — overrides

Migration `database/migrations/bot/...create_bot_messages_table.php`. Columns:

| Column | Type | Meaning |
|---|---|---|
| `id` | PK | — |
| `code` | string(128), **unique** | message code from the registry |
| `text` | text | overridden text (inline HTML) |
| `is_active` | bool, default `true` | whether to apply the override |
| `updated_by` | FK users, nullOnDelete | who edited it |
| `created_at`/`updated_at` | timestamps | — |

The live contract:

- **Laravel writes:** `AdminBotMessageController@update` does an upsert by `code`;
  `@destroy` (the `reset` route) deletes the override → reverts to the default from the registry.
- **Python reads:** `MessageRepository.get(code)` runs
  `SELECT text FROM bot_messages WHERE code=%s AND is_active=1 LIMIT 1` and falls back
  to the `default` from the registry if the row is absent, empty, or `is_active=0`.

> The table is created **only when the bot is enabled**: `BotServiceProvider` registers
> `migrations/bot/` only when `config('bot.enabled')`.

### 2b. The `bot_message_media` table — attachments

Migration `database/migrations/bot/...create_bot_message_media_table.php`. A message can
carry media files (photos, PDFs, …) picked from the admin's media library. They are keyed
by the message **`code`** (not by a `bot_messages` row), so a message can keep its default
text yet still have attachments, and resetting the text does not drop them.

| Column | Type | Meaning |
|---|---|---|
| `id` | PK | — |
| `code` | string(128) | message code from the registry |
| `media_id` | FK media, **cascade on delete** | the attached file |
| `position` | uint | order set in the admin |

The live contract:

- **Laravel writes:** `AdminBotMessageController@update` syncs the set from `media_ids[]`
  (validated by `BotMessageRequest`, capped by `config('bot.max_attachments')`).
- **Python reads:** `MessageRepository.get_attachments(code)` joins `bot_message_media → media`
  and returns `(filename, mime_type, original_name)` in `position` order.

The bot needs the **file bytes** to upload them to MAX, so it must see the Laravel
`public` disk on disk. The path is `MEDIA_ROOT` + the media `filename`
(e.g. `media/abc.webp`); the library uploads it via `bot.send_message(attachments=[InputMedia(...)])`
(see `utils/messaging.py`). In Docker the storage volume is mounted **read-only** into the
bot container and `MEDIA_ROOT=/app/storage/app/public` (see `docker-compose.yml`). A file
missing on disk is skipped, never fatal.

### 3. The text format contract — inline HTML only

The admin edits the text in `NRichText` and stores **inline HTML**. The bot sends it with
`format=ParseMode.HTML` (`handlers/bot_started.py`), and MAX/Telegram understand only
inline markup.

That is why `BotMessageRequest` sanitizes input through `App\Support\BotMessageSanitizer`
(symfony/html-sanitizer, not regexes). Allowed:

- inline tags: `<b> <strong> <i> <em> <s> <strike> <u> <code>`;
- links `<a href>` with the schemes `https`, `http`, `mailto`, `tg`.

Everything else is stripped (block/unknown tags — keeping the text;
`<script>`/`<style>` — together with their contents; `on*=` handlers and dangerous schemes —
removed). When storing texts in the DB **do not rely on block markup.**

---

## Configuration (env)

The bot reads its own environment (`config/config.py`, `Config.from_env`). The `.env`
is looked up first in `modules/max-bot/.env`, then in the admin panel root (one level up).

| Variable | Required | Default | Purpose |
|---|---|---|---|
| `API_TOKEN` | **yes** | — | MAX bot token |
| `BOT_ID` | **yes** | — | bot id (int) |
| `DB_DATABASE` | **yes** | — | database shared with Laravel |
| `DB_USERNAME` | **yes** | — | DB user |
| `DB_PASSWORD` | **yes** | — | DB password |
| `API_BASE_URL` | no | `https://platform-api.max.ru` | base URL of the MAX API |
| `DB_HOST` | no | `127.0.0.1` | DB host |
| `DB_PORT` | no | `3306` | DB port |
| `MEDIA_ROOT` | no | repo `storage/app/public` | root of the Laravel public disk where message attachments are read from (Docker: `/app/storage/app/public`, storage volume mounted read-only) |
| `MAX_API_RATE_LIMIT_HZ` | no | `20` | MAX API request limit, Hz |

A missing required variable → `ValueError` on startup.

## Enabling

The flag on the Laravel side is `config('bot.enabled')`, turned on by **either** of:

- `BOT_ACTIVE=true` (app layer; local run);
- `COMPOSE_PROFILES` containing `bot` (Docker — the same variable brings up the `bot` container).

When disabled: the `bot-messages.*` routes return 404 (`EnsureBotEnabled`), the bot's
migrations are not registered, the `bot-messages.*` permissions are not seeded, the sidebar
item is hidden. See the "Bot messages" section in the root [README](../../README.md).

## Running (locally, outside Docker)

```bash
cd modules/max-bot
python -m venv venv && source venv/bin/activate   # Windows: venv\Scripts\activate
pip install -r requirements.txt
cp .env.example .env        # fill in API_TOKEN, BOT_ID, DB_*
python main.py
```

The bot's tests — `pytest` (see `pytest.ini`, `conftest.py`, `tests/`, `handlers/test_handlers.py`).

## Structure

```
modules/max-bot/
├── main.py              # entry point: long-polling + workers + graceful shutdown
├── messages.json        # shared registry of codes/defaults (source of truth)
├── messages.schema.json # JSON schema of the registry (validated in tests)
├── config/config.py     # loading env → dataclass Config
├── handlers/            # event handlers (bot_started, bot_added are active)
├── repositories/        # messages.py (registry + override + attachments), db.py (aiomysql pool)
├── utils/               # messaging.py (send text + media attachments), rate_limiter etc.
└── log/                 # logging setup
```
