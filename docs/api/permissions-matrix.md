# Permissions matrix and error catalog

Who may call which admin route, how the checks are layered and which errors come
back. Source of truth: `routes/web.php`, the FormRequests in `app/Http/Requests`,
`App\Support\RbacGuard` and the services in `app/Services`. The full route list
(`php artisan route:list`) is in [routes.snapshot.txt](routes.snapshot.txt).

## Authorization model

One mechanism (`spatie/laravel-permission`, permission names `module.action`),
with one job per layer (docblock of `App\Http\Controllers\Controller`):

1. **Route middleware** (`permission:x` in `routes/web.php`) decides whether the
   user may perform the action at all. Create/edit routes carry both the
   section's `*.view` and the action permission (`*.create`, `*.edit`), resource
   routes attach the action permission with `middlewareFor()`. A user with
   `users.view` but without `users.create` gets **403** on `POST /admin/users`
   from the middleware.
2. **FormRequest `authorize()`** re-checks the same concrete permission (defence
   in depth, never just "signed in") and adds record-level rules: may the actor
   touch this user or role (`RbacGuard`).
3. **Controllers** do not repeat permission checks. They only apply
   record-level rules where no FormRequest runs (GET edit pages of users and
   roles answer 403) and the per-record rules of bulk actions, which skip and
   report records.
4. **Services** keep the domain invariants (anti-escalation, protected roles)
   whoever calls them, and report them as 422.

`can()` in Vue (`resources/js/lib/can.ts`, fed by `auth.can`) only hides
buttons. The server stays the source of truth.

Laravel policies are not used.

### Superadmin and roles

- **Superadmin** is the role named in `config('rbac.superadmin_role')`
  (`admin`). `Gate::before` (`RbacGuard::registerSuperadminGate()`, registered
  in `bootstrap/app.php`) lets it pass every ability check, including route
  `permission:` middleware, so a missing permission row can never lock it out.
  `auth.can` lists every permission for it.
- The superadmin role's permission set is **immutable**: the matrix locks its
  column, and the role form and the matrix reject any change with 422.
- **System roles** (`is_system`: `admin`, `operator`) cannot be deleted or
  renamed; only a superadmin may change their permissions or assign them.
- **Role management rule:** a non-superadmin may edit a role, toggle its matrix
  cells or assign it to a user only when they hold **every** permission the role
  has (`RbacGuard::canManageRole()`), and may grant only permissions they hold
  themselves (`canGrantPermission()`). This stops a `roles.edit` or
  `permissions.edit` holder from stripping the role of someone above them.
- **User management rule:** a non-superadmin may edit, block, delete, restore or
  impersonate a user only when the target has no system role and no permission
  the actor lacks (`canManageUser()`). Everyone may manage their own account,
  but cannot remove the superadmin role from themselves or block themselves.
  Keeping a role the user already has is not a new grant.
- **Permissions** are defined in code (`RolePermissionSeeder::PERMISSIONS`,
  migrations through `App\Support\Migrations\PermissionMigration`), all
  `is_system`. The panel only toggles the role × permission matrix: there are no
  routes to create, rename or delete a permission (`permissions.create` and
  `permissions.delete` no longer exist).

Permissions: `users.{view,create,edit,delete,export,impersonate}`,
`roles.{view,create,edit,delete}`, `permissions.{view,edit}`,
`media.{view,upload,edit,delete}`, `activity-log.{view,delete}`,
`settings.{view,edit}`, `backups.{view,download,create,delete}`,
`queue.{view,manage}`. The `operator` role gets the media permissions by default.

## Notation

- All URIs are relative to `/admin`.
- **Route** — the `permission:` middleware (or `guest`/`throttle`). Every route
  except the login form also runs `auth`, `AuthenticateSession`,
  `EnsureAccountIsActive` and `RequirePasswordChange`.
- **Request** — what the FormRequest checks in addition, when it differs.

---

## Route → permission map

### Auth, profile, search, notifications

| Method | URI | Route | Notes |
| ------ | --- | ----- | ----- |
| GET | `/login` | `guest` | login form |
| POST | `/login` | `guest`, `throttle:login` | `LoginRequest`; wrong credentials or a blocked account → 422 on `email` |
| POST | `/logout` | — | ends the session |
| GET | `/` | — | dashboard |
| POST | `/session/ping` | — | 204, keeps the session alive |
| GET | `/search` | — | results filtered by each type's `*.view` |
| GET | `/notifications/recent`, `/notifications/count` | `activity-log.view` | JSON |
| POST | `/notifications/seen` | `activity-log.view` | JSON |
| PUT | `/notifications/preferences` | `activity-log.view` | JSON |
| GET | `/profile` | — | own profile and sessions |
| PUT | `/profile`, `/profile/password` | `throttle:6,1,profile-password` | email change and password change need the current password |
| POST | `/profile/sessions/logout-others` | `throttle:6,1,profile-password` | needs the current password; ends "remember me" on other devices |
| DELETE | `/profile/sessions/{key}` | — | only with `SESSION_DRIVER=database`, otherwise 404 |
| POST | `/impersonation/stop` | — | 404 when not impersonating |
| POST | `/users/{user}/impersonate` | `users.impersonate` | 403 unless the target is another active, non-superadmin user the actor may manage |

### Users

| Method | URI | Route | Request / notes |
| ------ | --- | ----- | --------------- |
| GET | `/users` | `users.view` | list |
| GET | `/users/export` | `users.view` + `users.export` | CSV/XLSX with the current filters |
| GET | `/users/create` | `users.view` + `users.create` | |
| POST | `/users` | `users.view` + `users.create` | `UserRequest`; assigning roles follows the role management rule |
| GET | `/users/{user}` | `users.view` | |
| GET | `/users/{user}/edit` | `users.view` + `users.edit` | 403 unless `canManageUser` |
| PUT/PATCH | `/users/{user}` | `users.view` + `users.edit` | `UserRequest` + `canManageUser` |
| PATCH | `/users/bulk-status` | `users.view` + `users.edit` | `BulkUserStatusRequest`; skips users the actor may not manage |
| DELETE | `/users/{user}` | `users.delete` | yourself → 422 |
| DELETE | `/users/bulk` | `users.delete` | `BulkUserActionRequest` |
| GET | `/users/trashed` | `users.delete` | trash |
| PATCH | `/users/restore/{id}` | `users.delete` | 404 if not in the trash; 422 if the email is taken |
| DELETE | `/users/force/{id}` | `users.delete` | 404 if not in the trash |
| POST | `/users/trashed/bulk-restore` | `users.delete` | `BulkUserActionRequest` |
| DELETE | `/users/trashed/bulk-force` | `users.delete` | `BulkUserActionRequest` |

### Roles and permissions

| Method | URI | Route | Request / notes |
| ------ | --- | ----- | --------------- |
| GET | `/roles`, `/roles/{role}` | `roles.view` | |
| GET | `/roles/create` | `roles.view` + `roles.create` | |
| POST | `/roles` | `roles.view` + `roles.create` | `RoleRequest`; only permissions the actor holds |
| GET | `/roles/{role}/edit` | `roles.view` + `roles.edit` | 403 unless `canManageRole` |
| PUT/PATCH | `/roles/{role}` | `roles.view` + `roles.edit` | `RoleRequest` + `canManageRole`; a request without `permissions` keeps the set |
| DELETE | `/roles/{role}` | `roles.delete` | system role or a role assigned to users → 422 |
| GET | `/permissions` | `permissions.view` | role × permission matrix |
| PATCH | `/permissions/matrix` | `permissions.edit` | one cell; superadmin role and roles above the actor → 422 |
| PATCH | `/permissions/matrix/bulk` | `permissions.edit` | a row or a group; skips roles and permissions the actor may not change, 422 when nothing is left |

### Media library

| Method | URI | Route | Request / notes |
| ------ | --- | ----- | --------------- |
| GET | `/media` | `media.view` | library page |
| GET | `/media/poll`, `/media/browse`, `/media/{media}` | `media.view` | JSON |
| POST | `/media` | `media.upload` | `MediaRequest` (also `media.upload`), JSON |
| PATCH | `/media/{media}` | `media.edit` | `RenameMediaRequest`: name, alt, folder, focal point |
| POST | `/media/{media}/replace` | `media.edit` | `ReplaceMediaRequest`, JSON, queued |
| POST | `/media/{media}/crop` | `media.edit` | `CropMediaRequest`, JSON |
| PATCH | `/media/bulk-folder` | `media.edit` | `BulkMediaFolderRequest`, destination in `target` |
| POST/PATCH/DELETE | `/media/folders` | `media.edit` | `MediaFolderRequest`; deleting a folder moves its files one level up |
| DELETE | `/media/{media}` | `media.delete` | a file in use → redirect back with `error` |
| DELETE | `/media/bulk` | `media.delete` | `BulkDestroyMediaRequest`; files in use are skipped and listed in `warning` |

A file counts as used when a place registered in `App\Support\MediaReferenceRegistry`
refers to it: the favicon and OG image settings by default, plus the foreign keys and
text fields a project registers.

### Activity log, settings, backups, queue

| Method | URI | Route | Notes |
| ------ | --- | ----- | ----- |
| GET | `/activity-log`, `/activity-log/export` | `activity-log.view` | export: CSV/XLSX, chosen columns, list filters |
| DELETE | `/activity-log` | `activity-log.delete` | clears entries before `before` (not in the future); the clearing is logged |
| GET | `/settings` | `settings.view` | |
| PUT | `/settings` | `settings.edit` | `UpdateSettingsRequest` |
| GET | `/backups` | `backups.view` | dump list, pending/failed state, nightly backup warning |
| GET | `/backups/{file}` | `backups.download` | download; logged. A dump holds every password hash, hence its own permission |
| POST | `/backups` | `backups.create` | queues a `CreateBackup` job (one at a time); logged |
| DELETE | `/backups/{file}` | `backups.delete` | logged |
| GET | `/queue` | `queue.view` | jobs, failed jobs, stalled-queue warning |
| POST | `/queue/failed/retry-all`, `/queue/failed/{uuid}/retry` | `queue.manage` | |
| DELETE | `/queue/failed/{uuid}`, `/queue/failed` | `queue.manage` | |

---

## Error catalog

### HTTP codes

| Code | When |
| ---- | ---- |
| **302 → `/admin/login`** | unauthenticated request; JSON requests get **401** (`apiFetch` then opens the login page) |
| **403** | missing permission (route middleware or FormRequest), a record above the actor (`canManageUser`/`canManageRole`), or a JSON request during a pending forced password change |
| **404** | model not found, record not in the trash, a backup file that does not exist, single-session sign-out without database sessions |
| **419** | stale CSRF token. Inertia/forms: redirect back with the warning "Страница устарела, повторите действие". `apiFetch`: refreshes the token and retries once |
| **422** | FormRequest validation or a domain rule (below) |
| **429** | rate limit exceeded |

**Rate limits:**

- `POST /login`: `security.login_throttle` attempts per minute (default 5) for
  the email + IP pair, and four times that per IP across all emails
  (`AppServiceProvider::LOGIN_IP_MULTIPLIER`). The client IP is trusted only from
  `TRUSTED_PROXIES`, so a forged `X-Forwarded-For` does not reset the limit;
- profile actions that check the current password (`PUT /profile`,
  `PUT /profile/password`, `POST /profile/sessions/logout-others`) share 6
  attempts per minute per user.

### Domain errors (422)

Raised as `ValidationException::withMessages([...])`: Inertia shows them as
field errors after a redirect back.

| Route | Key | Message |
| ----- | --- | ------- |
| `POST /login` | `email` | "Неверный email или пароль, либо учётная запись заблокирована" |
| `PUT /users/{user}` | `roles` | "Вы не можете убрать у себя роль admin" |
| `PUT /users/{user}` | `is_active` | "Вы не можете заблокировать самого себя" |
| `POST`/`PUT /users` | `roles.*` | "Системную роль может назначать только администратор." / "Нельзя назначить роль с правами, которых у вас нет: …" |
| `DELETE /users/{user}` | `user` | "Вы не можете удалить самого себя" |
| user actions | `user` | "Недостаточно прав для действий с этим пользователем" |
| `PATCH /users/restore/{id}` | `user` | "Email … уже занят активным пользователем" |
| `PUT /roles/{role}` | `name` | "Имя системной роли нельзя менять" |
| `PUT /roles/{role}` | `role` | "Системную роль может менять только администратор" / "У роли есть права, которых нет у вас: …" |
| `POST`/`PUT /roles` | `permissions` | "Права роли «admin» нельзя изменять" / "Нельзя назначить роли право, которого у вас нет: …" |
| `DELETE /roles/{role}` | `role` | "Системную роль нельзя удалить" / "Нельзя удалить роль, назначенную пользователям" |
| `PATCH /permissions/matrix` | `matrix` | "Права роли «admin» нельзя изменять" / "Права системной роли может менять только администратор" / "У роли «…» есть права, которых нет у вас: …" / "Нельзя выдать право, которого у вас нет" |
| `PATCH /permissions/matrix/bulk` | `matrix` | "Нет ролей или прав, которые вы можете изменить" |
