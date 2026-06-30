# Документация

Справочник по API-поверхности шаблона. Это серверно-управляемое приложение на
Inertia: «API» — в основном HTTP-маршруты, отдающие страницы Inertia или редиректы,
и лишь 4 настоящих JSON-ручки. Доки ниже фиксируют контракты, которые иначе пришлось
бы выводить из исходников.

## `api/`

| Файл | Что внутри |
|---|---|
| [`api/json-endpoints.md`](api/json-endpoints.md) | 4 настоящих JSON/XHR-ручки: запрос, форма ответа, аутентификация (сессия + CSRF) |
| [`api/permissions-matrix.md`](api/permissions-matrix.md) | Карта маршрут → право (двухуровневая модель middleware + FormRequest) и каталог ошибок (403/404/422/429 + доменные проверки) |
| [`api/response-conventions.md`](api/response-conventions.md) | Shared-пропсы Inertia, конверт пагинатора, health `/up`, заметки о версионировании/именовании |
| [`api/routes.snapshot.txt`](api/routes.snapshot.txt) | Снимок `php artisan route:list` (генерируется `composer doc:routes`). Снят при **включённом** боте (`BOT_ACTIVE=true`) — это суперсет: при дефолтном `BOT_ACTIVE=false` 3 маршрута `bot-messages.*` не регистрируются |

## Бот

Контракт интеграции Python ↔ Laravel (общий `messages.json` + таблица `bot_messages`,
правило инлайн-HTML, env-переменные) — в [`../modules/max-bot/README.md`](../modules/max-bot/README.md).
JSON-схема реестра — [`../modules/max-bot/messages.schema.json`](../modules/max-bot/messages.schema.json).

## Поддержание в актуальности

- Снимок маршрутов: `composer doc:routes` после изменения `routes/web.php` (для
  стабильного суперсета снимай при `BOT_ACTIVE=true`, чтобы в диффе не «прыгали»
  маршруты бота в зависимости от флага).
- Схема реестра бота проверяется тестом `modules/max-bot/tests/test_registry_schema.py`.
- Прочие доки правятся вручную — они невелики и держатся рядом с кодом, который описывают.

> Тяжёлый инструментарий (Scribe, генерируемый OpenAPI-портал, коллекции Postman)
> намеренно **не** подключён: для шаблона такого размера 4 JSON-ручки дешевле описать
> вручную. Если у проекта появится внешний публичный API — заведите его в отдельном
> `/api/v1` (см. `api/response-conventions.md`) и тогда уже подключайте генератор.
