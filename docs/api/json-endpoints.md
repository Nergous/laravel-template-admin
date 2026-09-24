# JSON endpoints

This is a server-driven **Inertia** application: the vast majority of routes return
Inertia page objects (HTML/JSON) or a `RedirectResponse` with flash and validation
errors (see [response-conventions.md](response-conventions.md)), **not** a JSON API.

There are only a few "real" JSON/XHR endpoints that the frontend reads directly
via `fetch`. Their response shapes are an implicit contract between the controller
and Vue; this file pins it down in one place.

## Authentication for all JSON endpoints

All of them live under `/admin/*` and are protected by a **session cookie + CSRF**, not
a token. They are part of the admin SPA, not a public API:

- requests carry the same session as the page (the `*_session` cookie);
- mutating requests (`POST`/`PUT`/`PATCH`/`DELETE`) require the `X-CSRF-TOKEN` header
  (or `X-XSRF-TOKEN` from the cookie). For `media store` this is critical — without
  the token you get `419 Page Expired`;
- access is additionally gated by permissions (see the table and
  [permissions-matrix.md](permissions-matrix.md)).

> If you later need an external API with token authentication, move it into a
> separate `/api/v1` namespace outside `/admin/*` (see
> [response-conventions.md](response-conventions.md)). The current `/admin/*` JSON
> endpoints are unsuitable as a public API: they are tied to the session and CSRF.

## Summary

| Method | URI                           | Permission                      | Response shape            |
| ----- | ----------------------------- | ------------------------------- | ------------------------- |
| GET   | `/admin/search`               | per-entity view permission | `{ results: [...] }`      |
| GET   | `/admin/notifications/recent` | `activity-log.view`             | `{ count, items: [...] }` |
| GET   | `/admin/media/poll`           | `media.view`                    | **bare array** `[...]`    |
| GET   | `/admin/media/browse`         | `media.view`                    | `{ data, current_page, last_page }` |
| POST  | `/admin/notifications/seen`   | `activity-log.view`             | `{ count: 0 }`            |
| GET   | `/admin/media/{id}`           | `media.view`                    | file details object       |
| POST  | `/admin/media`                | `media.upload`                  | `{ queued, after_id }`    |
| POST  | `/admin/media/{id}/replace`   | `media.edit`                    | `{ queued: 1, filename }` |

---

## GET `/admin/search` — global search (Cmd+K)

`AdminSearchController@index`. Consumer — `resources/js/admin/layouts/AdminLayout.vue`.

**Request:** the `q` query parameter. Queries shorter than **2 characters** are
ignored (an empty `results` is returned). At most **5** matches per entity.

**Permission:** the endpoint is under `auth`, but result types are filtered inline via
`can()`: the `user` block is returned only with `users.view`, `role` — with
`roles.view`, `media` — with `media.view`.
With no matching view permissions you get `{ "results": [] }` (not a 403).

**Response** `200 application/json`:

```json
{
    "results": [
        {
            "type": "user",
            "label": "Иван Петров",
            "meta": "ivan@example.com",
            "url": "https://admin.test/admin/users/12",
            "icon": "user"
        },
        {
            "type": "media",
            "label": "photo.webp",
            "meta": "Фото",
            "url": "https://admin.test/admin/media?search=photo.webp",
            "icon": "asset"
        }
    ]
}
```

Fields of each result: `type` (`user`|`role`|`media`), `label`, `meta`, `url`, `icon`.
Media is matched by its display name (`original_name`) and stored file name.

> To add a new entity to search, follow the pattern of the block in the controller (a
> new `if ($user?->can('xxx.view'))` with `->map(...)` into the same shape).

---

## GET `/admin/notifications/recent` — the "bell" feed

`AdminActivityLogController@recent`. Consumer — `AdminLayout.vue` (the topbar badge).

Returns the unread counter and the latest **10** actions of **other** users from the
past **week** (logins excluded). An item is unread when it is newer than the user's
`notifications_seen_at`; `POST /admin/notifications/seen` moves that mark to now.

**Permission:** `activity-log.view` (route middleware, `routes/web.php`). The feed
serves the same audit stream as the `/admin/activity-log` page, so it requires the
same permission, not just `auth`.

**Response** `200 application/json`:

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
            "unread": true,
            "url": "https://admin.test/admin/users/42"
        }
    ]
}
```

`count` counts every unread item in the window (not the list length — `items` is
truncated to 10). `url` points at the affected record when it still exists, otherwise
at the activity log.

---

## GET `/admin/media/poll` — upload polling

`AdminMediaController@poll`. Consumer — `resources/js/admin/pages/Media/Index.vue`.
Used to pick up media that the asynchronous queue (`UploadMedia`) has finished loading,
without reloading the page.

**Permission:** `media.view`.

**Request** (validated inline in the controller):

| Parameter  | Rules                              | Default                 |
| ---------- | ---------------------------------- | ----------------------- |
| `after_id` | `nullable, integer, min:0`         | `0` (return the latest) |
| `limit`    | `nullable, integer, min:1, max:50` | `50`                    |

Returns media with `id > after_id` **uploaded by the current user**, descending by `id`,
at most `limit`. Pass the `after_id` returned by `POST /admin/media`, so uploads of other
admins and rows outside the current page never mix in.

**⚠️ The response is a bare array, NOT wrapped in `{ data: ... }`:**

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
        "created_at": "2026-06-29T10:05:00+00:00",
        "created_local": "29.06.2026 13:05"
    }
]
```

`created_at` is ISO-8601 UTC; `created_local` is the same moment formatted `d.m.Y H:i`
in the display time zone. `filename` is only
the base name (no path).

---

## GET `/admin/media/browse` — media picker (paginated)

`AdminMediaController@browse`. Consumer — `resources/js/admin/components/MediaPicker.vue`
(a reusable modal for picking files from the library).
Unlike `media/poll` (a bare array of recent uploads), this is a searchable, paginated
browse feed.

**Permission:** `media.view`.

**Request** (validated inline in the controller):

| Parameter | Rules                          | Default |
| --------- | ------------------------------ | ------- |
| `search`  | `nullable, string, max:255`    | —       |
| `page`    | `nullable, integer, min:1`     | `1`     |
| `type`    | `nullable, in:image,video,audio,document,other` | — |

`search` matches the file name (substring); results are 24 per page, descending by `id`.

**Response** `200 application/json` (a trimmed paginator envelope, NOT the full Laravel one):

```json
{
    "data": [
        {
            "id": 105,
            "url": "/storage/media/abc.webp",
            "thumb_url": "/storage/media/abc_thumb.webp",
            "type": "image",
            "original_name": "Моё фото.png",
            "size": 24576
        }
    ],
    "current_page": 1,
    "last_page": 3
}
```

---

## POST `/admin/media` — queue files for upload

`AdminMediaController@store`, validation — `MediaRequest`. Consumer — `Media/Index.vue`.

**Permission:** `media.upload` (**not** `media.view`). This is the only mutating JSON
endpoint, so it strictly requires an `X-CSRF-TOKEN`.

**Request:** `multipart/form-data`, the `media` field is an array of files.

| Parameter | Rules                                                                                          |
| --------- | ---------------------------------------------------------------------------------------------- |
| `media`   | `required, array, min:1, max:10`                                                               |
| `media.*` | `required, file, mimes:<18 extensions>, max:51200` (≈50 MB) + a resolution limit for images    |

Allowed extensions: `jpg jpeg png webp gif mp4 webm mov mp3 wav ogg pdf doc docx
xls xlsx txt` (`MediaRequest::ALLOWED_EXTENSIONS`). For images, the pixel resolution
is additionally checked (protection against decompression bombs).

**Response** `200 application/json`:

```json
{ "queued": 3, "after_id": 104 }
```

`queued` is how many files were queued into `UploadMedia`; `after_id` is the largest
media id before this upload — pass it to `media/poll`. The actual WebP conversion
and thumbnail generation happen **asynchronously** in the worker — the frontend then
pulls the ready records via `media/poll`.

**Errors:** `422` with validation errors (format/size/count), `403` without the
`media.upload` permission, `419` without a CSRF token. See [permissions-matrix.md](permissions-matrix.md).

---

## GET `/admin/media/{id}` — file details

`AdminMediaController@show`. Consumer — the details drawer in `Media/Index.vue`.

**Permission:** `media.view`. Returns the media fields plus `dimensions`
(`{ width, height }` for images, read from the file header, otherwise `null`),
`uploaded_by`, `updated_by`, `created_at`/`updated_at` (ISO UTC) and `created_local`.

## POST `/admin/media/{id}/replace` — replace the file

`AdminMediaController@replace`, validation — `ReplaceMediaRequest` (same formats and limits
as an upload, field `file`). **Permission:** `media.edit`, CSRF required.

Responds `{ "queued": 1, "filename": "<old path>" }`. The queue stores the new file, updates
the record in place (id, display name and attachments stay) and deletes the old files.
The file URL changes; the frontend polls `GET /admin/media/{id}` until `filename` differs.
