# Response conventions

Response shapes shared across the whole application. Documents what would otherwise
have to be inferred from the code for each page.

## Three response types

| Type         | What it is                                                                                         | When                                                |
| ------------ | -------------------------------------------------------------------------------------------------- | --------------------------------------------------- |
| **Inertia**  | a page object `Inertia::render('Xxx/Index', [...])` (HTML on first hit, JSON on navigation)        | section GET pages                                   |
| **Redirect** | a `RedirectResponse` (`redirect()->route(...)` or `back()`) with flash and/or validation errors    | mutations (`store`/`update`/`destroy`/…)            |
| **JSON**     | `response()->json(...)` — a real XHR response                                                       | 4 endpoints, see [json-endpoints.md](json-endpoints.md) |

Mutations do **not** return JSON — they redirect, and the result is shown via a
flash toast on the next page.

## Shared props (on every Inertia response)

Source — `App\Http\Middleware\HandleInertiaRequests::share()`. Available in all Vue
pages via `usePage().props`:

| Prop          | Contents                                                                                                                   |
| ------------- | -------------------------------------------------------------------------------------------------------------------------- |
| `auth.user`   | the current user (or `null`)                                                                                              |
| `auth.can`    | a flat list of the user's **permission names**; read by the `can(perm)` helper (`resources/js/lib/can.js`) for conditional rendering |
| `counts`      | lazy aggregates for the sidebar/bell badges                                                                                |
| `flash`       | flash messages (`success`/`error`/`warning`/`info`) → toasts                                                               |
| `bot.enabled` | whether the bot module is enabled (`config('bot.enabled')`) — gates the sidebar item                                       |
| `appName`     | the application name (from settings)                                                                                       |

Any information needed globally in Vue is added here rather than passed into every
controller.

## Pagination envelope

List pages (`/users`, `/roles`, `/media`, `/activity-log`, …) return a **standard
Laravel paginator** inside an Inertia prop. The shape (the fields the frontend uses):

```json
{
  "data": [ /* rows of the current page */ ],
  "current_page": 1,
  "last_page": 5,
  "per_page": 10,
  "total": 47,
  "links": [ { "url": "...", "label": "1", "active": true }, ... ],
  "from": 1,
  "to": 10
}
```

Page sizes: users — 10, roles — 15, media — 15, log — 30.
All lists use `->withQueryString()`, so filters/sorting survive paging.

Sorting is driven by `App\Http\Sorts\*` strategies with a field whitelist (protection
against injection via `?sort=`); `currentSort` and `currentDirection` come alongside
the paginator.

## Infrastructure routes

| Method | URI   | What                                                                                                                                                                                |
| ----- | ----- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| GET   | `/up` | Laravel health check (`bootstrap/app.php`, `health: '/up'`). Returns 200 if the app booted. Use it for liveness/readiness probes (Docker healthcheck, load balancer)                |
| GET   | `/`   | redirect to `/admin`                                                                                                                                                                |
| —     | 404   | a non-admin, non-JSON 404 redirects to `/admin` (`bootstrap/app.php`)                                                                                                               |

## Media display-name changes

`PATCH /admin/media/{media}` accepts `original_name` (required string, at most 255
characters, no path separators or control characters). Both middleware and
`RenameMediaRequest` require `media.edit`. Success redirects back with a flash
message; validation uses the standard Inertia error bag. Only `original_name`
changes; stored filenames, attachment links and thumbnails remain valid. The
activity log records the old and new display names.
