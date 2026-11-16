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
- the route group also runs `AuthenticateSession` and `EnsureAccountIsActive`
  (blocked accounts are signed out);
- permissions are checked by route middleware, see
  [permissions-matrix.md](permissions-matrix.md).

All calls go through `apiFetch()` (`resources/js/lib/api.ts`), except the
upload in `useMediaUpload.ts`, which uses `XMLHttpRequest` for the progress
bar. Error handling of both is described in
[response-conventions.md](response-conventions.md#csrf-and-419).

These endpoints are an internal contract between controllers and Vue, not a
public API. Payloads are built by API Resources (`app/Http/Resources`) and use
**snake_case** keys; the matching TypeScript types are in
`resources/js/admin/types.ts`.

## Summary

| Method | URI | Permission | Response |
| ------ | --- | ---------- | -------- |
| GET | `/admin/search` | signed in; result types filtered by `*.view` | `{ results: [...] }` |
| POST | `/admin/session/ping` | signed in | `204 No Content` |
| POST | `/admin/media` | `media.upload` | `{ queued, batch }` |
| GET | `/admin/media/poll` | `media.view` | `{ items, failed, duplicates }` |
| GET | `/admin/media/browse` | `media.view` | `{ data, current_page, last_page, upload }` |
| GET | `/admin/media/{media}` | `media.view` | file details |
| POST | `/admin/media/{media}/replace` | `media.edit` | `{ queued: 1, batch }` |
| POST | `/admin/media/{media}/crop` | `media.edit` | file details |

---

## GET `/admin/search` — global search (Cmd+K)

`SearchController` (invokable) on top of `App\Services\System\GlobalSearch`.
Consumer: `layouts/partials/AdminCommandPalette.vue`. `resources/js/lib/csrf.ts`
also calls it with an empty query to get a fresh `XSRF-TOKEN` cookie after a 419.

**Request:** `q` (`GlobalSearchRequest`, at most 100 characters; a longer query
gets 422). Queries shorter than 2 characters return `{ "results": [] }`;
otherwise up to **5** matches per entity type.

**Entity types and permissions** (a type without its view permission is left
out; no permission at all gives an empty list, not a 403):

| `type` | Permission | `meta` | `url` |
| ------ | ---------- | ------ | ----- |
| `user` | `users.view` | email | user page |
| `role` | `roles.view` | description or `Роль` | role page |
| `media` | `media.view` | `Фото`/`Видео`/`Аудио`/`Документ`/`Файл` | media library filtered by name |

Matching uses the model's `HasSearch` scope (the `$searchable` columns).

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

Each item has `type`, `label`, `meta`, `url` and `icon`. To search your own
entity, add a private method to `GlobalSearch` and one line to `find()`.

---

## POST `/admin/session/ping` — keep the session alive

`SessionPingController`. Returns `204 No Content`; any authenticated request
extends the session. `useSessionTimeout.ts` calls it while the user works on one
page and when they choose to stay signed in from the expiry warning.

---

## Media library

Consumers: `composables/useMediaUpload.ts`, `pages/Media/Partials/*`,
`components/MediaPicker.vue`.

### POST `/admin/media` — queue an upload

`MediaUploadController@store`, validation `UploadMediaRequest`.
`multipart/form-data`:

| Field | Rules |
| ----- | ----- |
| `media[]` | required, 1–10 files |
| `media.*` | `mimes:` one of 18 extensions, `max:51200` (50 MB), images at most 40 megapixels |
| `folder` | optional, an existing folder path (`media_folders.name`); empty means the library root |

The limits live in `App\Support\Media\UploadRules` (`MAX_FILES`, `MAX_SIZE_KB`);
the extensions come from `App\Enums\MediaType::extensions()`: `jpg jpeg png webp
avif gif mp4 webm mov mp3 wav ogg pdf doc docx xls xlsx txt`.

**Response** `200`: `{ "queued": 3, "batch": "<uuid>" }`. Each file becomes an
`UploadMedia` job; every row and log entry of the request carries the batch
id. With the default `QUEUE_CONNECTION=sync` the jobs run before the response
is sent; with the `database` queue a worker processes them. Images are converted
to WebP (or AVIF, see `MEDIA_IMAGE_FORMAT`; the stored `mime_type` follows the
converted file) and get a 600 px thumbnail `<name>.thumb.webp`
(`<name>.thumb.avif` for AVIF output) and responsive copies of 960 and 1440 px
(`<name>.w960.webp`, `config('media.variant_widths')`) when the original is
wider; other files are stored as they are.

A file whose SHA-256 already exists in the library is not stored again: the job
logs `upload_duplicate` pointing at the existing record and leaves that record
where it is (it is not moved into the chosen folder).

**Errors:** 422 (format, size, count, resolution, unknown folder), 403 without
`media.upload`. The XHR upload does not retry a 419 or 401: it sends the user to
the login page.

### GET `/admin/media/poll?batch=<uuid>` — upload progress

`MediaUploadController@poll` (`PollMediaUploadRequest`,
`MediaUploads::progress()`, `MediaUploadProgressResource`). `batch` is required
(`uuid`).

```json
{
    "items": [
        {
            "id": 105,
            "filename": "media/abc.webp",
            "original_name": "Моё фото.png",
            "mime_type": "image/webp",
            "type": "image",
            "size": 24576,
            "url": "/storage/media/abc.webp",
            "thumb_url": "/storage/media/abc.thumb.webp",
            "srcset": "/storage/media/abc.thumb.webp 600w, /storage/media/abc.webp 1200w",
            "width": 1200,
            "height": 800,
            "focal_x": null,
            "focal_y": null,
            "alt": null,
            "folder": "Баннеры/2026",
            "created_at": "2026-10-05T10:05:00+00:00",
            "usages": []
        }
    ],
    "failed": [{ "id": 901, "name": "broken.jpg", "error": "Не удалось обработать файл" }],
    "duplicates": [{ "id": 902, "name": "copy.jpg", "existing_id": 77 }]
}
```

- `items` — records whose current file came from this batch (new uploads and
  replacements), newest first, at most 50. Each item is a `MediaResource`.
- `failed` — `upload_failed` entries of the batch (the job gave up after 3 tries).
- `duplicates` — files skipped as duplicates; `existing_id` is the record that
  already holds the content.
- `filename` is the path on the media disk. `created_at` is ISO-8601 UTC; the
  client formats it in the display time zone.

The client polls for up to 3 minutes, with a delay growing from 1 to 10 seconds.

### GET `/admin/media/browse` — media picker

`MediaPickerController` (invokable, `MediaPickerRequest`). Query: `search`
(≤255), `page` (≥1), `type` (`image|video|audio|document|other`). 24 items per
page (`MediaLibrary::PICKER_PAGE_SIZE`), newest first, across the whole library
regardless of folders.

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
    "last_page": 3,
    "upload": { "extensions": ["jpg", "jpeg", "png", "webp", "avif", "gif"], "max_size_kb": 51200, "max_files": 10 }
}
```

`data` items are `MediaResource` payloads (the same keys as in the poll, without
`usages`). `upload` lets the picker upload files itself (`POST /admin/media`
into the library root, then `media/poll`): the upload limits
(`UploadRules::toArray()`) with the extensions of the requested `type` (all of
them without a type, none for `other`), or `null` without `media.upload`. Files
uploaded in the picker, and the existing records of skipped duplicates, appear
first in its grid and are selected.

### GET `/admin/media/{media}` — file details

`MediaController@show` (`MediaDetailsResource`). Returns every `MediaResource`
key (`id`, `filename`, `original_name`, `mime_type`, `type`, `size`, `url`,
`thumb_url`, `srcset`, `width`, `height`, `focal_x`, `focal_y`, `alt`,
`folder`, `created_at`, `usages`) plus `dimensions` (`{ width, height }` or
`null`), `creator_name`, `editor_name`, `updated_at` (ISO UTC) and `places`
(every referencing record as `{ label, title, url }`, see `MediaPlaces`).

### POST `/admin/media/{media}/replace` — replace the file

`MediaUploadController@replace`, `ReplaceMediaRequest`, field `file`: the same
formats and limits as an upload, and the same type as the current file (an image
is replaced only by an image, and so on). Response
`{ "queued": 1, "batch": "<uuid>" }`; the drawer then polls `media/poll` with
that batch.

The job stores the new file and points the record at it (id, display name, alt
and folder stay; the focal point is reset), rewrites links to the old file in
texts and settings (`MediaReferenceUpdater`) and removes the old files after the
commit. If rewriting the links fails, the record and the old file stay as they
were. The file URL changes. Replacements skip the duplicate check.

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

The selection is sent **flat** (`composables/useBulkSelection.ts`) and
validated by a `App\Http\Requests\SelectionRequest` subclass:

- picked rows: `{ "ids": [1, 2, 3] }`, at most **500** ids
  (`SelectionRequest::MAX_IDS`);
- every row matching the list: `{ "all": true, ...filters }`, where the filter
  keys are the list's own query parameters. "All matching" covers every page.
  The filters are validated strictly (an unknown role name or type is a 422), so
  a typo cannot widen the selection.

The services resolve the selection with `App\Support\Selection`, which walks the
records in chunks of 200 by id inside one transaction, one model at a time (so
model events and the activity log fire per record).

| List | Routes | Filters sent with `all` | Notes |
| ---- | ------ | ----------------------- | ----- |
| Users | `DELETE users/bulk`, `PATCH users/bulk-status` | `search`, `role`, `status` | bulk delete takes `force` (bool, delete permanently instead of the trash); bulk-status also takes `active` (bool) and `reason` |
| User trash | `POST users/trashed/bulk-restore`, `DELETE users/trashed/bulk-force` | `search` | |
| Media | `DELETE media/bulk`, `PATCH media/bulk-folder` | `search`, `type`, `folder`, `usage` | see below |

**Media `bulk-folder`:** `folder` is the list filter (the open folder), so the
destination travels as `target`: a path such as `"Баннеры/2026"`, where missing
folders are created and `null` or an empty string takes the files out of any
folder. A request without `target` and without `all` is the older form, where
`folder` named the destination.

**Per-record rules** (the record is skipped and counted in the flash):

- media deletion: files still referenced anywhere;
- users: accounts the actor may not manage (`UserPolicy`) and the actor's own
  account.
