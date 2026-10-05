# Admin frontend

The admin panel is an Inertia 3 application on Vue 3.5 and TypeScript, built by
Vite 8. Interface components come from `nergous-ui-vue` (1.1.0). The entry point
is `resources/js/admin/app.ts`, the root Blade template is
`resources/views/admin.blade.php`.

The HTTP contracts the frontend relies on are described in
[api/json-endpoints.md](api/json-endpoints.md) and
[api/response-conventions.md](api/response-conventions.md).

## `resources/js` layout

| Directory | What's inside |
| --------- | ------------- |
| `lib/` | page-independent helpers: `api.ts` (`apiFetch`, session tracking, redirect to sign-in), `csrf.ts` (CSRF headers and token refresh), `can.ts` (permission checks for display), `format.ts` (dates in the site timezone, numbers, sizes; built on the library's `createFormat`), `filterList.ts` (multi-value list filters), `listUrl.ts` (list addresses), `media.ts` (file labels and icons), `swatch.ts` (badge colors) |
| `admin/app.ts` | Inertia bootstrap, persistent layout, shared props, the library locale and Enter handling |
| `admin/types.ts` | types of page props and server responses |
| `admin/layouts/` | `AdminLayout.vue` (sidebar, topbar, breadcrumbs, notifications, session timer), `AuthLayout.vue` (sign-in screen), `partials/` (`AdminCommandPalette.vue` for Cmd+K search, `NotificationBell.vue`, `nav.ts` for sidebar items) |
| `admin/components/` | project components on top of the library: `AdminPagination`, `DeleteButton`, `ExportMenu`, `MediaPicker`, `MediaField`, `DrawerFooter` |
| `admin/composables/` | logic shared by pages: lists, row selection, forms, session, media library, notifications |
| `admin/pages/<Section>/` | Inertia pages: `Index.vue`, `FormPage.vue`, `Show.vue`, `Trashed.vue`; large page parts live next to them in `Partials/` |

Frontend tests live in `tests/js/` (logic without a browser) and `tests/e2e/`
(Playwright and axe in a browser).

## What comes from nergous-ui-vue

Generic pieces are taken from the library instead of being kept in the project:

| Need | Library API |
| ---- | ----------- |
| Russian labels of every component | `createLocale(ruMessages)` in `app.ts`; pages pass no "Назад"/"Закрыть" labels |
| Enter submits the form | `installEnterSubmit({ boundary: "#admin-main" })` in `app.ts` |
| Confirmation before a dangerous action | `NConfirmDialog` + `useConfirm()` |
| Keyboard shortcuts | `useHotkeys` |
| List toolbar, filters, chips | `NToolbar`, `NSelect`, `NSelectWithSearch`, `NMultiSelect`, `NFilterChips` |
| Visible table columns | `NColumnPicker` + `useColumnVisibility` |
| Tables, including the phone layout | `NDataTable` (`stacked`, `:total`, `v-model:all-matching`) |
| Sticky save bar | `NActionBar` |
| Breadcrumbs, popovers, icon tooltips | `NBreadcrumbs`, `NPopover`, `NIconTooltip` |
| Pagination | `NPagination`, wrapped by `AdminPagination` |

The library ships no Inertia code. The project connects it to Inertia where
needed, for example `NBreadcrumbs` gets `link-as="Link"`.

## Persistent layout

`app.ts` assigns `AdminLayout` to every page except `Auth/*` and `Error`:

```ts
layout: (name) => (usesAdminLayout(name) ? AdminLayout : null),
```

The layout mounts once and survives page changes: the sidebar, topbar, toasts,
session timer and notification polling are not recreated. A page therefore
does not wrap itself in `AdminLayout` and passes it no slots. The page sets the
topbar title, subtitle and tab title through `usePageHeader()`:

```ts
usePageHeader(() => ({ title: "Пользователи", subtitle: `Всего: ${props.users.total}` }));
```

The values travel as Inertia layout props and are watched, so the subtitle
follows a partial reload. Inertia clears these props on every page change.

## Enter to submit

`installEnterSubmit` from the library runs once in `app.ts`. The main action
button is marked with `data-enter-submit`:

- Enter in a text field clicks the nearest marked button: inside an open dialog
  or drawer, the button of that dialog; on the page, the page's save button;
- in a `textarea` Enter inserts a line break, and Ctrl/Cmd+Enter submits;
- a form with its own `type="submit"` button works as usual;
- a field that handles Enter itself is wrapped in `data-enter-ignore`
  (dropdowns and `role="combobox"` are skipped automatically);
- `data-enter-scope` limits the search to part of the page, like a dialog.

## Back to the list: `listUrl` and `LIST_PATHS`

`lib/listUrl.ts` remembers in the tab's `sessionStorage` the last address of
each list, with its filters, sort and page. "Back to list" links on record pages
are built with `listUrl("/admin/users")`. Only paths in `LIST_PATHS` (users,
roles) are tracked; add a new list with such links there. `cameFrom(path)`
tells whether the user came from the given page.

On the server, `Controller::redirectToList()` does the same: after an action in
a list, the user returns to the same list page.

## Unsaved changes: `useUnsavedGuard`

`useUnsavedGuard(() => form.isDirty)` asks for confirmation when the form has
unsaved changes and the user:

- follows an Inertia link (a GET visit);
- presses Back or Forward in the browser: the `popstate` handler runs in the
  capture phase, before Inertia's, and puts the page address back on refusal;
- closes or reloads the tab (`beforeunload`).

Submitting the form itself (not a GET) and the forced redirect to sign-in
(`isRedirectingToLogin()`) pass without a question.

## Session and background requests

`useSessionTimeout` runs in `AdminLayout` and reads the shared
`sessionLifetime` prop (minutes; `null` after "remember me", which turns the
timer off).

- The time of the last server response is kept in `localStorage`
  (`admin-last-request`) and shared by all tabs, since every request extends the
  same session. `apiFetch` and Inertia visits update it.
- While the user works (clicks, keys, scrolling in the last 5 minutes) and the
  last response is older than 5 minutes (or half the session for sessions under
  10 minutes), a quiet `POST /admin/session/ping` goes out.
- A minute before the end a warning with an "extend" button appears; when the
  session expires, the user is sent to sign-in.
- Background requests extend the session, so they run only in a visible tab of
  an active user (`isUserActive()`), for example the notification counter.

## Row selection and bulk actions

`useBulkSelection(pageIds, total, filters)` keeps the selection of a
server-paginated list. It is bound to `NDataTable` through `:selected`,
`@update:selected="updateSelected"`, `:total` and
`v-model:all-matching="allMatchingSelected"`; the table itself offers "select
all N" once the page is selected.

- Rows picked on the page are sent as `{ ids: [...] }` (`selectionPayload`);
- "all matching" sends the flat `{ all: true, ...filters }`, where the filter keys
  are the list's query parameters;
- a filter change drops the selection; a page, sort or page size change drops
  the rows that are no longer visible. "All matching" survives paging because it
  is defined by the filters alone.

The media library uses `useMediaSelection`: `bulkFolderBody()` puts the
destination folder in `target`, because `folder` is the filter of the open
folder. Deletion in lists is done by `useListDelete` with `NConfirmDialog`. The
server skips records that may not be touched and reports their number in the
flash message. The full contract is in
[api/json-endpoints.md](api/json-endpoints.md#bulk-action-payloads).

## JSON requests: `apiFetch` and 419

All requests to JSON routes go through `apiFetch()` from `lib/api.ts`:

- it adds `Accept: application/json`, `X-Requested-With` and, for mutating
  requests, the CSRF header from the `XSRF-TOKEN` cookie (read on every attempt);
- on 419 it fetches a fresh token and retries once. If the refresh returns 401,
  the user goes to sign-in; if the retry gives 419 again, a message asks to
  reload the page;
- 401 means the session is gone: `redirectToLogin()` shows a message and opens
  the sign-in page, and the caller gets `SessionExpiredError`.

Inertia visits are handled by the server: on 419 it returns to the same page
with a "page expired, repeat the action" warning. Media uploads use
`XMLHttpRequest` for the progress bar and go to sign-in on 401 and 419.

Errors a form cannot show next to a field (service rules, missing permissions,
network) are shown as toasts by `useServerErrors`.

## Lists and filters

`useIndexFilters(url, params)` reloads a list with the current filters, search
(debounced by 300 ms) and sort, preserving state and scroll.

Filters accept several values. Values in one filter combine with OR, filters
combine with AND. In the address a multi-value filter is a comma list
(`?status=active,blocked`); `lib/filterList.ts` reads and writes it
(`parseList`, `joinList`), builds the button summary (`listSummary`) and one
chip per value (`listChips`, `chipTarget`, `without`). The page binds the
filter to `NMultiSelect` and the applied values to `NFilterChips`, which emits
removal of one chip or a reset of all.

A dropdown filter without a visible label gets `aria-label`. If a list has only
search, chips are not needed: the value is visible in the field.

`AdminPagination.vue` maps the Laravel paginator onto `NPagination`:

- it takes `paginator` (`current_page`, `last_page`, `total`), `perPage` and
  `perPageOptions` (the page gets them from the server, `App\Http\Pagination\PerPage`);
- it hides while the list fits one page of the smallest size;
- it supports jumping to a page and choosing the page size, and emits
  `update:page` and `update:pageSize`.

## Common page elements

**Breadcrumbs.** The page passes the trail to `usePageHeader`, and the layout
renders it above the content with `NBreadcrumbs`. The last item is the current
page and is added automatically:

```ts
usePageHeader(() => ({
    title: "Новый пользователь",
    crumbs: [{ label: "Пользователи", href: listUrl("/admin/users") }],
}));
```

**Save bar.** Form buttons sit in `NActionBar` with `:dirty`: the bar sticks to
the bottom of the screen and says whether there are unsaved changes.

**Tooltips.** `NIconTooltip` in the layout shows the `aria-label` of a button or
link without text on hover and on keyboard focus. Elements with `title` and
inside `data-no-tip` are skipped.

**Tables on a phone.** Lists use `NDataTable stacked`: below 640 px rows turn
into cards labelled with the column headers.

**Loading.** `useVisitState` tells a visit to another page from a reload of the
same list when the request takes longer than 250 ms: on a page change the layout
shows a skeleton, on a reload it dims tables and cards.

**Tab in the address.** Settings keep the open tab in `?tab=`: a link or a reload
opens the same tab. The address changes through `router.replace` without a
request, and unsaved form values stay.

## Checks and tests

```bash
npm run typecheck      # vue-tsc
npm run format:check   # Prettier
npm run test:js        # node --test for tests/js/*.test.ts
npm run build          # Vite build
npm run test:e2e       # Playwright and axe, after npm run build
```

Tests in `tests/js/` run on the built-in `node --test`: Node 23.6 and newer
strip TypeScript types itself, no build is needed. They cover media selection,
the move payload, keyboard crop and focal point, and the multi-value filter
helpers. Logic without DOM that can be checked on plain objects and Vue `ref`s
goes there.

Browser tests (`tests/e2e/`) cover sign-in, breadcrumbs, filter chips, the
settings tab in the address, the save bar, tooltips, the dashboard actions,
tables and the topbar at 390 px, and run axe (WCAG 2.1 A and AA) over the main
pages in both themes. Playwright needs Chromium
(`npx playwright install chromium`, once).

The working database is never used. `tests/e2e/server.ts` creates an empty
SQLite file in `storage/framework/testing/e2e`, writes `.env.e2e` and starts the
application with `APP_ENV=e2e`, so Laravel does not read `.env`. Before
migrating, the script asks the loaded application which database it uses and
stops unless it is that file. It then runs migrations and seeders
(`admin@example.com` / `password123`) and starts PHP's built-in server on port
8765 (`E2E_PORT`; the PHP binary is `E2E_PHP`). `php artisan serve` is not
suitable: it restarts PHP without the environment variables, and the new
process would read `.env`.
