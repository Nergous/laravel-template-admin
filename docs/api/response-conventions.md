# Response conventions

Shapes and behaviour shared by the whole application, so they do not have to be
re-read from each controller.

## Three response types

| Type | What it is | When |
| ---- | ---------- | ---- |
| **Inertia** | `Inertia::render('Xxx/Index', [...])`: HTML on the first hit, a JSON page object on navigation | admin GET pages |
| **Redirect** | `redirect()->route(...)` or `back()` with flash messages and/or validation errors | admin mutations (`store`, `update`, `destroy`, bulk actions) |
| **JSON** | `response()->json(...)` or `204` | the XHR endpoints in [json-endpoints.md](json-endpoints.md) |

Mutations redirect; the result is shown as a toast from `flash` on the next
page. After a list action the controller returns to the list the user started
from, with its filters, sorting and page (`Controller::redirectToList()`). A
page number past the end (after deleting the last rows) redirects to the last
page (`Controller::redirectPastLastPage()`).

## Shared props

Source: `App\Http\Middleware\HandleInertiaRequests::share()`. The middleware is
attached to the `/admin` route group only (`routes/web.php`), so the props are
built for admin pages and never for a public site added later.

| Prop | Contents |
| ---- | -------- |
| `appName` | application name (`config('app.name')`, set from the settings) |
| `timezone` | display time zone (settings → general); dates are stored in UTC |
| `sessionLifetime` | idle session lifetime in minutes (settings → security); `null` for guests and for a "remember me" session |
| `auth.user` | `{ id, name, email, roles, must_change_password }` or `null` |
| `auth.impersonator` | `{ id, name }` of the administrator working "as" this user, else `null` |
| `auth.can` | flat list of the user's permission names; the superadmin gets every permission. Read by `can()` in `resources/js/lib/can.ts` for conditional rendering only |
| `counts` | lazy sidebar badges: `users`, `roles`, `permissions`, `media` (cached for 30 s, `null` without the section's `*.view`) and `recentActivity` (personal unread bell count, `null` without `activity-log.view`) |
| `flash` | `success`, `error`, `warning`, `info` messages |

## Errors

**Admin** (`/admin/*`): with `APP_DEBUG=false`, statuses 403, 404, 419, 429, 500
and 503 render the Inertia `Error` page (also in maintenance mode). With
`APP_DEBUG=true` the framework's own error screen is shown. Plain JSON requests
(`Accept: application/json` without `X-Inertia`) always get the raw status.

**Outside `/admin`:** a non-JSON 404 redirects to `/admin` (`bootstrap/app.php`).

## CSRF and 419

A 419 means the CSRF token is stale (the page stayed open past the session, or
the user signed in again in another tab).

- **Inertia visits and plain forms**: the server redirects back with the flash
  warning "Страница устарела, повторите действие"; the reload brings a fresh token.
- **`apiFetch()`** (`resources/js/lib/api.ts`): on a 419 it requests
  `GET /admin/search` to get a new `XSRF-TOKEN` cookie and retries once. If the
  refresh answers 401 the user is sent to the login page; if the retry fails
  again an error toast asks to reload the page. A 401 on any call also leads to
  the login page (`SessionExpiredError` is thrown to the caller).
- **Media upload** (`XMLHttpRequest`): 401 and 419 send the user to the login
  page without a retry.

## Pagination

List pages return a standard Laravel paginator inside an Inertia prop (`data`,
`current_page`, `last_page`, `per_page`, `total`, `links`, `from`, `to`). Every
list uses `->withQueryString()`, so filters and sorting survive paging.

Page sizes (`app/Http/Pagination`):

| List | Page size |
| ---- | --------- |
| users, user trash, roles | `?per_page` from 10, 25, 50, 100; default 10 (`PerPage`) |
| media library | the same options, default 25 (`MediaPerPage`) |
| activity log | 30, fixed |
| queue | 20, fixed |
| media picker (`/admin/media/browse`) | 24, fixed |

Values outside the whitelist fall back to the default. Pages that take
`per_page` also receive `perPage` and `perPageOptions` props for
`AdminPagination.vue`.

Sorting uses `App\Http\Sorts\*` strategies with a whitelist of fields;
`currentSort` and `currentDirection` come with the paginator.

## Infrastructure routes

| Method | URI | What |
| ------ | --- | ---- |
| GET | `/up` | liveness: the database answers `select 1` and `storage/app` is writable; 200 or 500. Used by the Docker health check; queue and scheduler containers wait for it. A queue backlog does not fail it |
| GET | `/up?full=1` | the same plus the queue: 500 when, with the `database` queue driver, a runnable job has waited more than 15 minutes (`QueueStats::STALLED_AFTER_MINUTES`). For external monitoring |
| GET | `/` | redirect to `/admin` |
| GET | `/robots.txt` | generated from the SEO settings; `Disallow: /` when indexing is off |
| GET | `/sitemap.xml` | URLs from the sources registered in `App\Support\Sitemap` (none by default); 404 when indexing or the sitemap is off |

## Media metadata

`PATCH /admin/media/{media}` (`RenameMediaRequest`, `media.edit`) updates any of:

- `original_name` — display name, required when sent, ≤255, no `/`, `\` or
  control characters;
- `alt` — ≤255, empty clears it;
- `folder` — folder path (≤255, no `\`, control characters, `.` or `..`
  segments), empty takes the file out of any folder;
- `focal_x`, `focal_y` — focal point as fractions 0..1, always sent as a pair;
  `null` resets it to the centre.

The stored file name does not change, so links and thumbnails keep working.
Success redirects back with a flash message; changes go to the activity log.

## Versioning

The `/admin/*` routes are versionless and change together with the Vue code in
the same commit. If an external API is ever needed, it goes into its own prefix
(for example `/api/v1`) with token authentication, outside the admin session and
CSRF, and with its own documentation.

