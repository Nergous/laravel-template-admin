# JSON-эндпоинты

Это серверно-управляемое приложение на **Inertia**: подавляющее большинство
маршрутов возвращают объекты страниц Inertia (HTML/JSON) или `RedirectResponse`
с flash и ошибками валидации (см. [response-conventions.md](response-conventions.md)),
а **не** JSON-API.

«Настоящих» JSON/XHR-ручек, которые фронт читает напрямую через `fetch`, всего
**четыре**. Их формы ответов — неявный контракт между контроллером и Vue; этот
файл фиксирует его в одном месте.

## Аутентификация всех JSON-ручек

Все четыре живут под `/admin/*` и защищены **сессионной cookie + CSRF**, а не
токеном. Это часть admin-SPA, а не публичный API:

- запросы идут с той же сессией, что и страница (cookie `*_session`);
- мутирующие запросы (`POST`/`PUT`/`PATCH`/`DELETE`) требуют заголовок
  `X-CSRF-TOKEN` (или `X-XSRF-TOKEN` из cookie). У `media store` это критично —
  без токена будет `419 Page Expired`;
- доступ дополнительно гейтится правами (см. таблицу и
  [permissions-matrix.md](permissions-matrix.md)).

> Если позже понадобится внешний API с токен-аутентификацией — выносите его в
> отдельное пространство `/api/v1` вне `/admin/*` (см.
> [response-conventions.md](response-conventions.md)). Текущие `/admin/*`-JSON
> непригодны как публичный API: они завязаны на сессию и CSRF.

## Сводка

| Метод | URI                           | Право                           | Форма ответа              |
| ----- | ----------------------------- | ------------------------------- | ------------------------- |
| GET   | `/admin/search`               | `users.view` и/или `media.view` | `{ results: [...] }`      |
| GET   | `/admin/notifications/recent` | `activity-log.view`             | `{ count, items: [...] }` |
| GET   | `/admin/media/poll`           | `media.view`                    | **голый массив** `[...]`  |
| POST  | `/admin/media`                | `media.upload`                  | `{ queued: <int> }`       |

---

## GET `/admin/search` — глобальный поиск (Cmd+K)

`AdminSearchController@index`. Потребитель — `resources/js/admin/layouts/AdminLayout.vue`.

**Запрос:** query-параметр `q`. Запросы короче **2 символов** игнорируются (вернётся
пустой `results`). На каждую сущность — максимум **5** совпадений.

**Право:** ручка под `auth`, но типы результатов фильтруются инлайн через `can()`:
блок `user` отдаётся только при `users.view`, блок `media` — только при `media.view`.
Без обоих прав вернётся `{ "results": [] }` (а не 403).

**Ответ** `200 application/json`:

```json
{
    "results": [
        {
            "type": "user",
            "label": "Иван Петров",
            "meta": "ivan@example.com",
            "url": "/admin/users/12/edit",
            "icon": "user"
        },
        {
            "type": "media",
            "label": "photo.webp",
            "meta": "Фото",
            "url": "/admin/media",
            "icon": "image"
        }
    ]
}
```

Поля каждого результата: `type` (`user`|`media`), `label`, `meta`, `url`, `icon`.

> Добавление новой сущности в поиск — по образцу блока в контроллере (новый
> `if ($user?->can('xxx.view'))` с `->map(...)` в ту же форму).

---

## GET `/admin/notifications/recent` — фид «колокольчика»

`AdminActivityLogController@recent`. Потребитель — `AdminLayout.vue` (бейдж в топбаре).

Возвращает счётчик и последние **10** записей журнала за **24 часа**.

**Право:** `activity-log.view` (middleware на маршруте, `routes/web.php`). Фид отдаёт
ту же ленту аудита, что и страница `/admin/activity-log`, поэтому требует то же
право, а не просто `auth`.

**Ответ** `200 application/json`:

```json
{
    "count": 3,
    "items": [
        {
            "id": 87,
            "user": "Иван Петров",
            "action": "Создание",
            "subject": "Пользователь #42",
            "time": "5 минут назад",
            "iso_time": "2026-06-29T10:15:00+00:00",
            "url": "/admin/activity-log"
        }
    ]
}
```

`count` считается по тому же 24-часовому окну, что и `items` (а не по длине списка —
`items` обрезан до 10).

---

## GET `/admin/media/poll` — поллинг загрузок

`AdminMediaController@poll`. Потребитель — `resources/js/admin/pages/Media/Index.vue`.
Используется, чтобы подхватывать медиа, дозагруженные асинхронной очередью
(`UploadMedia`), без перезагрузки страницы.

**Право:** `media.view`.

**Запрос** (валидируется инлайн в контроллере):

| Параметр   | Правила                            | По умолчанию            |
| ---------- | ---------------------------------- | ----------------------- |
| `after_id` | `nullable, integer, min:0`         | `0` (вернуть последние) |
| `limit`    | `nullable, integer, min:1, max:50` | `50`                    |

Возвращает медиа с `id > after_id`, по убыванию `id`, не более `limit`.

**⚠️ Ответ — голый массив, НЕ обёрнут в `{ data: ... }`:**

```json
[
    {
        "id": 105,
        "url": "/storage/media/abc.webp",
        "thumb_url": "/storage/media/abc_thumb.webp",
        "type": "image",
        "filename": "abc.webp",
        "original_name": "Моё фото.png",
        "size": 24576,
        "created_at": "29.06.2026 13:05"
    }
]
```

`created_at` — уже отформатированная строка `d.m.Y H:i`, а не ISO. `filename` —
только базовое имя (без пути).

---

## POST `/admin/media` — постановка файлов в очередь

`AdminMediaController@store`, валидация — `MediaRequest`. Потребитель — `Media/Index.vue`.

**Право:** `media.upload` (а **не** `media.view`). Это единственная мутирующая JSON-ручка,
поэтому ей строго нужен `X-CSRF-TOKEN`.

**Запрос:** `multipart/form-data`, поле `media` — массив файлов.

| Параметр  | Правила                                                                                        |
| --------- | ---------------------------------------------------------------------------------------------- |
| `media`   | `required, array, min:1, max:10`                                                               |
| `media.*` | `required, file, mimes:<18 расширений>, max:51200` (≈50 МБ) + лимит разрешения для изображений |

Допустимые расширения: `jpg jpeg png webp gif mp4 webm mov mp3 wav ogg pdf doc docx
xls xlsx txt` (`MediaRequest::ALLOWED_EXTENSIONS`). Для изображений дополнительно
проверяется разрешение в пикселях (защита от decompression bomb).

**Ответ** `200 application/json`:

```json
{ "queued": 3 }
```

`queued` — сколько файлов поставлено в очередь `UploadMedia`. Сама конвертация в
WebP и генерация превью происходят **асинхронно** в воркере — фронт затем подтягивает
готовые записи через `media/poll`.

**Ошибки:** `422` с ошибками валидации (формат/размер/количество), `403` без права
`media.upload`, `419` без CSRF-токена. См. [permissions-matrix.md](permissions-matrix.md).
