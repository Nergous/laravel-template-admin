# Permissions matrix and error catalog

A per-route authorization map and the possible error responses.

## Two-level authorization model

The mechanism is single (`spatie/laravel-permission`, permission names `module.action`),
but **the check point depends on the type of action**:

1. **Viewing a section** — middleware `permission:*.view` on the route group.
2. **Deletion** — middleware `permission:*.delete` (for media — `media.delete`),
   often in a separate group.
3. **Create / edit** — a thin gate in `FormRequest::authorize()` **by HTTP method**:
   `POST → *.create`, `PUT/PATCH → *.edit`. That's why the resource methods
   `store`/`update` physically sit in the `*.view` group but are actually gated by
   the `*.create` / `*.edit` permission.
4. **Cross-entity JSON** (`/admin/search`) — inline `can()` per entity inside the
   controller (one endpoint serves several types).

**An easily missed consequence:** a role with `users.view` but without `users.create`
**will pass** the route middleware for `POST /admin/users` (it's under `users.view`)
and will be rejected only in `UserRequest::authorize()` → **403**. This is by design.

The duplication in Vue (`can(perm)`, `resources/js/lib/can.js`) is **only for
conditional rendering** (hiding a button). The server remains the source of truth: if
a permission is hidden in the UI but the request is sent anyway, a 403 is returned.

> Laravel policies (`app/Policies`, `Gate::define`) are deliberately not used — for a
> template this size the combination of middleware + `FormRequest::authorize()` is enough.

## Notation

- **Route permission** — middleware `permission:*` (or `auth` / `guest` / `bot.enabled`).
- **Action permission** — what `FormRequest::authorize()` actually checks (when it
  differs from the route permission).
- All routes are under the `/admin` prefix.

---

## Route → permission map

### Auth

| Method | URI       | Route permission          | Action permission | Notes                                  |
| ----- | --------- | ------------------------- | -------------- | -------------------------------------- |
| GET   | `/login`  | `guest`                   | —              | login form                             |
| POST  | `/login`  | `guest`, `throttle:login` | —              | `LoginRequest`; invalid credentials → 422 |
| POST  | `/logout` | `auth`                    | —              | invalidates the session                |

### Dashboard / Search / Notifications

| Method | URI                     | Route permission    | Action permission | Notes                                            |
| ----- | ----------------------- | ------------------- | -------------- | ------------------------------------------------ |
| GET   | `/` (`/admin`)          | `auth`              | —              | dashboard                                        |
| GET   | `/search`               | `auth`              | inline `can()` | results filtered by `users.view`/`media.view`    |
| GET   | `/notifications/recent` | `activity-log.view` | —              | JSON "bell" feed                                 |

### Users

| Method    | URI                                                  | Route permission | Action permission  | Notes                                              |
| --------- | ---------------------------------------------------- | -------------- | ------------------ | -------------------------------------------------- |
| GET       | `/users`                                             | `users.view`   | —                  | list                                               |
| POST      | `/users`                                             | `users.view`   | **`users.create`** | `UserRequest`                                      |
| PUT/PATCH | `/users/{user}`                                      | `users.view`   | **`users.edit`**   | `UserRequest`                                      |
| GET       | `/users/create`,`/users/{user}`,`/users/{user}/edit` | `users.view`   | —                  | stub redirects to index/edit (forms are drawers)   |
| DELETE    | `/users/{user}`                                      | `users.delete` | —                  | you can't delete yourself → 422                    |
| GET       | `/users/trashed`                                     | `users.delete` | —                  | trash                                              |
| PATCH     | `/users/restore/{id}`                                | `users.delete` | —                  | 404 if not in trash                                |
| DELETE    | `/users/force/{id}`                                  | `users.delete` | —                  | 404 if not in trash                                |
| POST      | `/users/trashed/bulk-restore`                        | `users.delete` | —                  | `BulkUserActionRequest`                            |
| DELETE    | `/users/trashed/bulk-force`                          | `users.delete` | —                  | `BulkUserActionRequest`                            |

### Roles

| Method    | URI                                             | Route permission | Action permission                                    | Notes                                     |
| --------- | ----------------------------------------------- | -------------- | ---------------------------------------------------- | ----------------------------------------- |
| GET       | `/roles`                                        | `roles.view`   | —                                                    | list                                      |
| GET       | `/roles/create`, `/roles/{role}`, `/roles/{role}/edit` | `roles.view`   | —                                             | stub redirects to index (forms are a drawer) |
| POST      | `/roles`                                        | `roles.view`   | **`roles.create`**                                   | `RoleRequest`                             |
| PUT/PATCH | `/roles/{role}`                                 | `roles.view`   | **`roles.edit`** (+ admin-only for a system role)    | `RoleRequest`                             |
| DELETE    | `/roles/{role}`                                 | `roles.delete` | —                                                    | can't delete a system / assigned role → 422 |

### Permissions

| Method    | URI                                                                     | Route permission     | Action permission        | Notes                                            |
| --------- | ----------------------------------------------------------------------- | -------------------- | ------------------------ | ------------------------------------------------ |
| GET       | `/permissions`, `/permissions/create`, `/permissions/{permission}/edit` | `permissions.view`   | —                        | matrix / stubs                                   |
| PATCH     | `/permissions/matrix`                                                   | `permissions.edit`   | — (inline validation)    | cell toggle; the `admin` role is locked → 422    |
| POST      | `/permissions`                                                          | `permissions.view`   | **`permissions.create`** | `PermissionRequest`; auto-grant to the `admin` role |
| PUT/PATCH | `/permissions/{permission}`                                             | `permissions.view`   | **`permissions.edit`**   | `PermissionRequest`                              |
| DELETE    | `/permissions/{permission}`                                             | `permissions.delete` | —                        | —                                                |

### Media

| Method | URI              | Route permission | Action permission     | Notes                            |
| ------ | ---------------- | -------------- | --------------------- | -------------------------------- |
| GET    | `/media`         | `media.view`   | —                     | list                             |
| GET    | `/media/poll`    | `media.view`   | — (inline validation) | JSON polling                     |
| POST   | `/media`         | `media.upload` | (also `media.upload`) | `MediaRequest`, JSON, needs CSRF |
| DELETE | `/media/{media}` | `media.delete` | —                     | —                                |
| DELETE | `/media/bulk`    | `media.delete` | —                     | `BulkDestroyMediaRequest`        |

### Activity Log / Settings

| Method | URI             | Route permission      | Action permission | Notes                                                  |
| ------ | --------------- | --------------------- | -------------- | ------------------------------------------------------ |
| GET    | `/activity-log` | `activity-log.view`   | —              | the log                                                |
| DELETE | `/activity-log` | `activity-log.delete` | —              | clears the log up to the `before` date (earlier events are deleted) |
| GET    | `/settings`     | `settings.view`       | —              | —                                                      |
| PUT    | `/settings`     | `settings.edit`       | —              | `UpdateSettingsRequest` (rules from `Setting::SCHEMA`) |

### Bot Messages (module under `bot.enabled`)

| Method | URI                    | Route permission                    | Action permission | Notes                                              |
| ------ | ---------------------- | ----------------------------------- | -------------- | -------------------------------------------------- |
| GET    | `/bot-messages`        | `bot.enabled` + `bot-messages.view` | —              | —                                                  |
| PUT    | `/bot-messages/{code}` | `bot.enabled` + `bot-messages.edit` | —              | `BotMessageRequest`; `{code}` ∈ registry, else 404 |
| DELETE | `/bot-messages/{code}` | `bot.enabled` + `bot-messages.edit` | —              | reset to default (route name `reset`)              |

> When `config('bot.enabled') === false`, the `EnsureBotEnabled` middleware returns
> **404** on all three (the module is indistinguishable from a non-existent one).

---

## Error catalog

### Framework codes

| Code                           | When                                                                                                                                  |
| ------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------- |
| **401 / redirect to `/login`** | unauthenticated request to a route under `auth`                                                                                       |
| **403**                        | missing the required permission (route middleware **or** `FormRequest::authorize()`)                                                 |
| **404**                        | model not found (`{user}`/`{role}`/…), user/media not in trash, disabled bot module, unknown bot message `{code}`                     |
| **419**                        | mutation without a valid CSRF token (relevant for `POST /media`)                                                                      |
| **422**                        | Form Request validation error **or** a domain check (see below)                                                                      |
| **429**                        | the `throttle:login` limit on `POST /login` exceeded (by default **5/min per IP**, configurable via `security.login_throttle`)       |

### Domain errors (422, `ValidationException` → redirect back with an error)

Domain invariants live in `App\Services\*` and are signaled via
`ValidationException::withMessages([...])` — Laravel turns them into a redirect back
with an error under the given key (the same UX as `back()->withErrors(...)`).

| Route                       | Key             | Message                                                                                                                                              |
| --------------------------- | --------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------- |
| `POST /login`               | `email`         | "Invalid email or password"                                                                                                                          |
| `DELETE /users/{user}`      | `user`          | "You cannot delete yourself"                                                                                                                          |
| `PUT /users/{user}`         | `roles`         | "You cannot remove the admin role from yourself"                                                                                                     |
| `POST`/`PUT /users`         | `roles.*`       | "Only an administrator can assign a system role" / "You can't assign a role with permissions you don't have: …"                                      |
| `PUT /roles/{role}`         | `name`          | "A system role's name cannot be changed"                                                                                                             |
| `POST`/`PUT /roles`         | `permissions.*` | "You can't assign a role a permission you don't have: …" (anti-escalation, S3)                                                                       |
| `DELETE /roles/{role}`      | `role`          | "A system role cannot be deleted" / "A role assigned to users cannot be deleted"                                                                     |
| `PATCH /permissions/matrix` | `matrix`        | "Permissions of the system role \"admin\" cannot be changed" / "Only an administrator can change a system role's permissions" / "You can't grant a permission you don't have" |
