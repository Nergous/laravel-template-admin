# JSON endpoints

The admin panel is a server-driven **Inertia** application. Most routes return
an Inertia page or a redirect with flash messages and validation errors (see
[response-conventions.md](response-conventions.md)). This file describes the
routes that the Vue code calls directly with `fetch`/XHR and that answer with
JSON (or an empty 204), plus the payload contract of the bulk actions.

## Authentication

Every endpoint below lives under `/admin/*` and uses the admin session, not a
token:

- the request carries the session cookie of the page;
- `POST`/`PUT`/`PATCH`/`DELETE` need a CSRF token. The frontend sends
  `X-XSRF-TOKEN` read from the `XSRF-TOKEN` cookie on every request
  (`resources/js/lib/csrf.ts`); the `csrf-token` meta tag is only a fallback;
- the route group also runs `AuthenticateSession`, `EnsureAccountIsActive`
  (blocked accounts are signed out) and `RequirePasswordChange` (a user with a
  pending forced password change gets **403** on every JSON route; only the
  profile routes and logout stay open);
- permissions are checked by route middleware, see
  [permissions-matrix.md](permissions-matrix.md).

All calls go through `apiFetch()` (`resources/js/lib/api.ts`), except the
upload in `useMediaUpload.ts`, which uses `XMLHttpRequest` for the progress
bar. Error handling of both is described in
[response-conventions.md](response-conventions.md#csrf-and-419).

These endpoints are an internal contract between controllers and Vue, not a
public API.

## Summary

| Method | URI | Permission | Response |
| ------ | --- | ---------- | -------- |
| GET | `/admin/search` | signed in; result types filtered by `*.view` | `{ results: [...] }` |
| GET | `/admin/notifications/recent` | `activity-log.view` | `{ count, mutes, items }` |
| GET | `/admin/notifications/count` | `activity-log.view` | `{ count }` |
| POST | `/admin/notifications/seen` | `activity-log.view` | `{ count: 0 }` |
| PUT | `/admin/notifications/preferences` | `activity-log.view` | `{ mutes, count }` |
| POST | `/admin/session/ping` | signed in | `204 No Content` |
| POST | `/admin/media` | `media.upload` | `{ queued, batch }` |
| GET | `/admin/media/poll` | `media.view` | `{ items, failed, duplicates }` |
| GET | `/admin/media/browse` | `media.view` | `{ data, current_page, last_page }` |
| GET | `/admin/media/{media}` | `media.view` | file details |
| POST | `/admin/media/{media}/replace` | `media.edit` | `{ queued: 1, batch }` |
| POST | `/admin/media/{media}/crop` | `media.edit` | file details |

---

## GET `/admin/search` — global search (Cmd+K)

`AdminSearchController@index`. Consumer: `layouts/partials/AdminCommandPalette.vue`.
`resources/js/lib/csrf.ts` also calls it with an empty query to get a fresh
`XSRF-TOKEN` cookie after a 419.

**Request:** `q`. Queries shorter than 2 characters return `{ "results": [] }`;
otherwise up to **5** matches per entity type.

**Entity types and permissions** (a type without its view permission is left
out; no permission at all gives an empty list, not a 403):

| `type` | Permission | `meta` | `url` |
| ------ | ---------- | ------ | ----- |
| `user` | `users.view` | email | user page |
| `role` | `roles.view` | description or `Роль` | role page |
| `media` | `media.view` | `Фото`/`Видео`/`Аудио`/`Документ`/`Файл` | media library filtered by name |

**Response** `200`:

```json
{
    "results": [
        {
            "type": "user",
            "label": "Иван Петров",
            "meta": "ivan@example.com",
            "url": "https://example.com/admin/users/12",
            "icon": "user"
        }
    ]
}
```

Each item has `type`, `label`, `meta`, `url` and `icon`. To search your own entity,
add a block to the controller following the same pattern.

---

## Notifications (the bell)

`NotificationController`. Consumers: `composables/useNotifications.ts` and
`layouts/partials/NotificationBell.vue`.

The bell shows actions of **other** users from the last 7 days; `login` and
`logout` are excluded. An item is unread when it is newer than the user's
`notifications_seen_at` (24 hours back if the user never opened the bell).

Each entry belongs to one category (`ActivityLog::NOTIFICATION_CATEGORIES`):
`auth` (failed sign-ins, ended sessions, impersonation), `users`, `roles`
(roles and permissions), `media`, `settings`, `system` (everything else). Muted
categories are left out of the feed and of the counter.

### GET `/admin/notifications/recent`

```json
{
    "count": 3,
    "mutes": ["media"],
    "items": [
        {
            "id": 87,
            "user": "Система",
            "action": "Неудачный вход",
            "category": "auth",
            "subject": "editor@example.com",
            "time": "5 минут назад",
            "iso_time": "2026-10-05T10:15:00+00:00",
            "unread": true,
            "url": "https://example.com/admin/activity-log",
            "repeat": 4
        }
    ]
}
```

- `items` holds at most 10 entries, built from the latest 50 rows.
- Consecutive `login_failed` entries for the same account collapse into one
  item; `repeat` is the number of attempts (1 for every other item).
- `count` is the unread total over the whole window, not the length of
  `items`. A burst of failed sign-ins for one account counts once.
- `url` points at the affected record when it still exists, otherwise at the
  activity log.

### GET `/admin/notifications/count`

`{ "count": 3 }`. The layout polls it every 60 seconds, only while the tab is
visible and the user has been active within the last 5 minutes, so an idle tab
does not keep the session alive.

### POST `/admin/notifications/seen`

Moves `notifications_seen_at` to now. Response `{ "count": 0 }`.

### PUT `/admin/notifications/preferences`

Request `{ "mutes": ["media", "system"] }`: `mutes` must be present (an empty
array unmutes everything), values come from the category list above.
Response `{ "mutes": [...], "count": <unread count with the new mutes> }`.
Stored without an activity-log entry.

---

## POST `/admin/session/ping` — keep the session alive

`SessionController@ping`. Returns `204 No Content`; any authenticated request
extends the session. `useSessionTimeout.ts` calls it while the user works on one
page and when they choose to stay signed in from the expiry warning.

---

## Media library

Consumers: `composables/useMediaUpload.ts`, `pages/Media/Partials/*`,
`components/MediaPicker.vue`.

### POST `/admin/media` — queue an upload

`AdminMediaController@store`, validation `MediaRequest`. `multipart/form-data`:

| Field | Rules |
| ----- | ----- |
| `media[]` | required, 1–10 files |
| `media.*` | `mimes:` one of 18 extensions, `max:51200` (50 MB), images at most 40 megapixels |
| `folder` | optional, an existing folder path (`media_folders.name`); empty means the library root |

Allowed extensions (`MediaRequest::ALLOWED_EXTENSIONS`): `jpg jpeg png webp avif
gif mp4 webm mov mp3 wav ogg pdf doc docx xls xlsx txt`.

**Response** `200`: `{ "queued": 3, "batch": "<uuid>" }`. Each file becomes an
`UploadMedia` job; every row and log entry of the request carries the batch
id. Images are converted to WebP (or AVIF, see `MEDIA_IMAGE_FORMAT`) and get a
600 px thumbnail `<name>.thumb.webp` (`<name>.thumb.avif` for AVIF output) and
responsive copies of 960 and 1440 px (`<name>.w960.webp`) when the original is
wider; other files are stored as they are.

A file whose SHA-256 already exists in the library is not stored again: the job
logs `upload_duplicate` pointing at the existing record and leaves that record
where it is (it is not moved into the chosen folder).

**Errors:** 422 (format, size, count, resolution, unknown folder), 403 without
`media.upload`. The XHR upload does not retry a 419 or 401: it sends the user to
the login page.

### GET `/admin/media/poll?batch=<uuid>` — upload progress

`AdminMediaController@poll`. `batch` is required (`uuid`).

```json
{
    "items": [
        {
            "id": 105,
            "url": "/storage/media/abc.webp",
            "thumb_url": "/storage/media/abc.thumb.webp",
            "srcset": "/storage/media/abc.thumb.webp 600w, /storage/media/abc.webp 1200w",
            "type": "image",
            "mime_type": "image/webp",
            "filename": "abc.webp",
            "original_name": "Моё фото.png",
            "alt": null,
            "folder": "Баннеры/2026",
            "size": 24576,
            "focal_x": null,
            "focal_y": null,
            "created_at": "2026-10-05T10:05:00+00:00",
            "created_local": "05.10.2026 13:05",
            "usages": []
        }
    ],
    "failed": [{ "id": 901, "name": "broken.jpg", "error": "Не удалось обработать файл" }],
    "duplicates": [{ "id": 902, "name": "copy.jpg", "existing_id": 77 }]
}
```

- `items` — records whose current file came from this batch (new uploads and
  replacements), newest first, at most 50.
- `failed` — `upload_failed` entries of the batch (the job gave up after 3 tries).
- `duplicates` — files skipped as duplicates; `existing_id` is the record that
  already holds the content.
- `filename` is the base name only. `created_at` is ISO-8601 UTC,
  `created_local` is `d.m.Y H:i` in the display time zone.

The client polls for up to 3 minutes, with a delay growing from 1 to 10 seconds.

### GET `/admin/media/browse` — media picker

`AdminMediaController@browse`. Query: `search` (≤255), `page` (≥1), `type`
(`image|video|audio|document|other`). 24 items per page, newest first, across the
whole library regardless of folders.

```json
{
    "data": [
        {
            "id": 105, "url": "...", "thumb_url": "...", "srcset": "...",
            "width": 1200, "height": 800, "focal_x": null, "focal_y": null,
            "type": "image", "original_name": "Моё фото.png", "alt": null,
            "folder": null, "size": 24576
        }
    ],
    "current_page": 1,
    "last_page": 3
}
```

### GET `/admin/media/{media}` — file details

`AdminMediaController@show`. Returns `id`, `original_name`, `alt`, `folder`,
`filename` (path on the media disk), `mime_type`, `type`, `size`, `url`,
`thumb_url`, `srcset`, `focal_x`, `focal_y`, `dimensions` (`{ width, height }` or
`null`), `uploaded_by`, `updated_by`, `created_at`, `updated_at` (ISO UTC),
`created_local`, `usages` (labels) and `places` (every referencing record with
an edit link, see `MediaUsage::place()`).

### POST `/admin/media/{media}/replace` — replace the file

`ReplaceMediaRequest`, field `file`: the same formats and limits as an upload,
and the same type as the current file (an image is replaced only by an image,
and so on). Response `{ "queued": 1, "batch": "<uuid>" }`; the drawer then polls
`media/poll` with that batch.

The queue stores the new file and points the record at it (id, display name, alt
and folder stay; the focal point is reset), rewrites links to the old file in
texts and settings and removes the old files after the commit. If rewriting the
links fails, the record and the old file stay as they were. The file URL changes.
Replacements skip the duplicate check.

### POST `/admin/media/{media}/crop` — crop an image

`CropMediaRequest`: `x`, `y`, `width`, `height` as fractions of the image
(0..1, `width` and `height` above 0, the area must fit inside the image). Runs
synchronously and returns the same payload as `GET /admin/media/{media}`. A new
file is written and links are rewritten as on a replacement.

### Metadata, folders and deletion

These are form actions with redirects, not JSON: `PATCH /admin/media/{media}`
(display name, alt, folder, focal point), folder create/rename/delete under
`/admin/media/folders`, `PATCH /admin/media/bulk-folder` and the deletes. See
[response-conventions.md](response-conventions.md#media-metadata) and the bulk
contract below.

---

## Bulk action payloads

Bulk actions are form submissions (Inertia visits) that redirect back with a
summary flash such as `"Удалено: 5. Пропущено: 2 (причина)"`. Records the actor
may not touch are skipped and counted; they never fail the whole request.

The selection is sent **flat** (`composables/useBulkSelection.ts`):

- picked rows: `{ "ids": [1, 2, 3] }`;
- every row matching the list: `{ "all": true, ...filters }`, where the filter
  keys are the list's own query parameters. "All matching" covers every page.

| List | Routes | Filters sent with `all` | Notes |
| ---- | ------ | ----------------------- | ----- |
| Users | `DELETE users/bulk`, `PATCH users/bulk-status` | `search`, `role`, `status`, `must_change_password` | bulk-status also takes `active` (bool) and `reason` |
| User trash | `POST users/trashed/bulk-restore`, `DELETE users/trashed/bulk-force` | `search` | |
| Media | `DELETE media/bulk`, `PATCH media/bulk-folder` | `search`, `type`, `folder`, `usage` | see below |

**Media `bulk-folder`:** `folder` is the list filter (the open folder), so the
destination travels as `target`: a path such as `"Баннеры/2026"`, where missing
folders are created and `null` or an empty string takes the files out of any
folder. A request without `target` and without `all` is the older form, where
`folder` named the destination.

**Per-record rules** (the record is skipped and counted in the flash):

- media deletion: files still referenced anywhere;
- users: accounts the actor may not manage (`RbacGuard::canManageUser`) and the
  actor's own account.
