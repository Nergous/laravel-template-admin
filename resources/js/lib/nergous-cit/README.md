# nergous-cit UI — Vue 3

Token-driven component library for the nergous-cit design system. Light/dark
themes, three density levels, accessibility out of the box. Zero dependencies
beyond Vue 3.

This folder is self-contained: components, composables, formatting helpers,
design tokens and the bundled fonts all live here, and the entry barrel
(`index.js`) auto-imports the token stylesheet. Drop the folder into any Vue 3
project and import from the barrel.

## Requirements

- **Vue 3** (`^3.3`) — the only peer dependency.
- A bundler that understands `.vue` single-file components and `.css` imports
  (Vite, Rollup, webpack, …). Vite is the reference setup.

## Install

The library ships as plain source and is consumed as a **vendored snapshot** —
you copy the source into your project and import it directly, you don't
`npm install` it. The folder stays editable in place.

The convenient way to pull (or later update) a snapshot is [`tiged`](https://github.com/tiged/tiged)
(a maintained `degit` fork): it shallow-clones this repo, strips the `.git`, and
drops the contents into a target folder.

```bash
# download / refresh the snapshot into your project
npx tiged --mode=git --force Nergous/nergous-cit src/lib/nergous-cit
```

`--mode=git` clones through your local git, so it works for both public and
private repos without registry tokens. `--force` overwrites the target — that is
the snapshot model: re-pulling replaces local edits, so contribute changes back
here rather than diverging downstream.

Prefer a one-liner in your project's `package.json`:

```json
"scripts": {
  "ds:pull": "tiged --mode=git --force Nergous/nergous-cit src/lib/nergous-cit"
}
```

> No network / quick manual route: just copy this repo's contents into
> `src/lib/nergous-cit` by hand. There is no build step.

To make imports short, add an alias to your bundler. With Vite, mapping `@` to
your source root lets you write `@/lib/nergous-cit`:

```js
// vite.config.js
import { fileURLToPath, URL } from 'node:url'

export default {
  resolve: {
    alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
  },
}
```

All examples below use `@/lib/nergous-cit`. Adjust the path to wherever you
vendored the folder (e.g. `./lib/nergous-cit`) if you skip the alias.

## Usage

Use named imports from the barrel only — global registration via `app.use()` is
intentionally not supported (named imports give editor autocomplete and make a
page's real dependencies visible).

```vue
<script setup>
import { NButton, NBadge, useToast } from '@/lib/nergous-cit'
const toast = useToast()
</script>
```

> The barrel (`index.js`) imports `styles/tokens.css` for you. If you import a
> component while bypassing the barrel, pull in the tokens manually:
> `import '@/lib/nergous-cit/styles/tokens.css'`.

## Themes and density

```vue
<script setup>
import { useTheme } from '@/lib/nergous-cit'
const { theme, density, toggle, setTheme, setDensity } = useTheme()
// theme   → ref('light' | 'dark')
// density → ref('compact' | 'comfortable' | 'spacious')
</script>

<template>
  <button @click="toggle">Toggle theme</button>
  <button @click="setDensity('compact')">Compact</button>
</template>
```

`useTheme` sets `data-theme` and `data-density` on `<html>` and persists both to
`localStorage`. Every color and spacing value comes from CSS variables, so
theming and density apply automatically to your own components when you use the
tokens. State is a module-level singleton, so every `useTheme()` caller shares
the same reactive `theme`/`density`.

Density has three levels — `compact`, `comfortable` (default) and `spacious` —
selected via `[data-density="…"]` rules in `tokens.css`.

### Anti-flash on first paint (optional)

To avoid a flash of the wrong theme before your JS runs, set the
`data-theme`/`data-density` attributes on `<html>` from an inline script in your
HTML head, reading the same `localStorage` keys `useTheme` uses. Those keys are
exported so you don't hardcode them:

```js
import { THEME_STORAGE_KEY, DENSITY_STORAGE_KEY } from '@/lib/nergous-cit'
// THEME_STORAGE_KEY   === 'nergouscit-theme'
// DENSITY_STORAGE_KEY === 'nergouscit-density'
```

```html
<script>
  var d = document.documentElement;
  d.dataset.theme = localStorage.getItem('nergouscit-theme') || 'light';
  d.dataset.density = localStorage.getItem('nergouscit-density') || 'comfortable';
</script>
```

## Toasts

Render `<NToaster />` once near the app root, then push messages from anywhere:

```vue
<script setup>
import { useToast } from '@/lib/nergous-cit'
const toast = useToast()
</script>

<template>
  <NToaster />
  <button @click="toast.success('Saved', 'Profile updated')">OK</button>
  <button @click="toast.error('Error', 'Could not save')">Fail</button>
</template>
```

`useToast()` returns the reactive `toasts` list plus:

- `success(title, msg)` / `error(title, msg)` / `warning(title, msg)` / `info(title, msg)`
  — tone shortcuts.
- `push(opts)` — `opts` is a string (title only) or `{ tone, title, msg, duration }`;
  returns the new toast's id.
- `dismiss(id)` — remove a toast early.

Auto-dismiss timing depends on tone: errors linger longest (7 s), warnings 5 s,
ok/info 3.6 s, unless you pass an explicit `duration` (`0` disables auto-dismiss).
Timers pause while the pointer or focus is inside the toaster, so messages don't
vanish mid-read (WCAG 2.2.1).

## Formatting

`createFormat(locale)` builds locale-bound formatters on top of plain `Intl` —
no hardcoded language; the host app passes the active BCP-47 locale:

```vue
<script setup>
import { createFormat } from '@/lib/nergous-cit'
const { formatDateTime, formatDateShort, formatRelative, formatNumber } = createFormat('en-US')
// formatDateTime('2025-03-12T10:00:00Z') → "03/12/2025, 10:00 AM"
// formatDateShort(...) → "Mar 12, 2025" · formatRelative(...) → "2 minutes ago"
// formatNumber(1284) → "1,284"
</script>
```

Invalid/empty values render as an em dash (`—`). `toDate(value)` is the single
parsing entry point (expects ISO 8601) and returns a `Date` or `null`; it is
also exported standalone from the barrel.

## Components

| Component | Purpose | Key props / v-model |
|---|---|---|
| `NButton` | Button | `variant` (primary/secondary/ghost/danger), `size` (sm/md/lg), `tone` (''/accent/danger), `icon`, `loading`, `block`, `as` (polymorphic root — e.g. a router-link component or `"a"`) |
| `NFormField` | Field wrapper: label + control + error/hint | `label`, `error`, `hint`, `required`, `tag` (`label`/`div`), `labelId`; passes an a11y contract to the nested control via `useFormField` (provide/inject — `aria-describedby`/`aria-invalid`/`aria-required`) |
| `NInput` | Text input | `v-model`, `type`, `icon`, `error`, `placeholder` |
| `NSelect` | Themed listbox (replaces native `<select>`) | `v-model` (value), `options: [{value,label,disabled?}]`, `placeholder`, `error` |
| `NTextarea` | Multi-line input | `v-model`, `rows` |
| `NRichText` | Mini WYSIWYG on `contenteditable` | `v-model` (HTML string), `placeholder`, `error`, `disabled`, `labels` (toolbar captions), `tools` (limit the button set). With `NFormField` use `tag="div"` |
| `NSwitch` | Toggle | `v-model` (Boolean) |
| `NCheckbox` | Checkbox | `v-model` (Boolean) + label slot |
| `NRadioGroup` | Radio group | `v-model`, `options: [{value,label}]` |
| `NSegmented` | Segmented control | `v-model`, `options: [{value,label}]` |
| `NBadge` | Badge / status | `tone`, `dot`, `pill` |
| `NAvatar` | Avatar | `name`, `src`, `size`, `status` (online/busy/away) |
| `NAvatarGroup` | Avatar stack | `items: string[]`, `max` |
| `NCard` | Card container | `hover`, `padding` |
| `NAlert` | Inline notice | `tone`, `title` + slot |
| `NTabs` | Tabs (WAI-ARIA tablist) | `v-model`, `tabs: [{value,label}]`, `id-base` (stable id prefix that ties tabs to panels via `aria-controls`/`aria-labelledby`) |
| `NModal` | Modal dialog | `v-model` (open), `title`, `#footer` slot |
| `NDrawer` | Side panel | `v-model` (open), `title`, `#footer` slot |
| `NLightbox` | Fullscreen image viewer | `items: [{url,caption}]`, `v-model:index` (`-1` = closed), `dialogLabel`/`closeLabel`/`prevLabel`/`nextLabel`; focus trap + Escape + arrow keys |
| `NToaster` | Toast container | — (driven by `useToast`) |
| `NIcon` | Icon | `name`, `size` |

### Layout and data (admin shell)

| Component | Purpose | Key props / v-model |
|---|---|---|
| `NSidebar` | Collapsible sidebar | `v-model` (active id), `groups: [{label,items:[{id,label,icon,badge}]}]`, `collapsed`, `brand`, `#footer` slot |
| `NTopbar` | Top bar | `title`, `subtitle`, `@toggle` event; slots `#left`, default, `#right` |
| `NBrand` | Logo lockup: glyph + name | `glyph`, `name`, `sub`, `size` (sm/md/lg), `showSub` (hide text for an icon-only rail) |
| `NStepper` | Vertical step rail | `v-model` (active step), `steps: [{value,label,sub}]`, `completed` (explicit done-set; default = linear by index), `navLabel` |
| `NWizard` | Stepped-form shell (create) | `v-model`, `steps`, `completed`, `title` (rail eyebrow), `progress`; scoped default slot `{step,index,count}` for the active panel, `#footer` slot `{index,count,isFirst,isLast,prev,next,goTo}` |
| `NAnchorNav` | Section table-of-contents | `v-model` (active section), `sections: [{value,label,count}]`, `navLabel` — presentational; pair with `useScrollSpy` or use `NAnchoredForm` |
| `NAnchoredForm` | Anchored single-page editor shell (edit) | `v-model`, `sections: [{value,label,count}]`, `sectionsLabel`, `height`, `offset`; slots `#section-<value>` (fields, scoped `{section,index}`), `#header`, `#status`, `#savebar`. Owns the scroll container + scroll-spy |
| `NDataTable` | Data table | `columns`, `rows`, `selectable`, `v-model:selected`, `page-size`; controlled sorting via `sort-key`/`sort-dir` + `manual-sort` (server-side; the header syncs the arrow and `aria-sort`); slots `#cell-<key>`, `#bulk`, `#empty`; events `@row-click`, `@sort-change` |
| `NPagination` | Pagination | `v-model:page`, `pages` |
| `NStatCard` | KPI card | `label`, `value`, `delta`, `trend` (up/down), `spark: number[]`, `icon` |
| `NActivityRow` | Activity-feed row | `tone` (ok/info/danger/warn/accent), `icon`, `actor`, `verb`, `object`, `tag`, `time`, `meta`. Presentational — the localized verb/tag is passed in by the host |
| `NDropzone` | Drag-and-drop upload | `accept`, `multiple`, `hint`; `@files` event |
| `NEmptyState` | Empty state | `icon`, `title`, `description` + action slot |
| `NProgress` | Progress bar | `value` (0–100), `label`, `showValue`, `tone` |
| `NSkeleton` | Skeleton placeholder | `width`, `height`, `circle` |
| `NSpinner` | Spinner | `size`, `width` |
| `NTooltip` | Tooltip | `content`, `placement` + trigger slot |
| `NDropdown` | Dropdown menu | `items: [{label,icon,danger,divider,action}]`, `align`; trigger slot; `@select` event |
| `NCommandPalette` | Command palette (⌘K) | `v-model` (open), `commands: [{label,icon,hint,action}]`, `shortcut`; `@run` event |

A complete admin shell is assembled in `examples/AdminApp.vue` (sidebar + topbar
+ KPIs + table with bulk actions + drawer + modal + command palette).

**Large forms.** When a record outgrows a drawer or modal, reach for the two
big-form shells: `NWizard` (a stepped create flow) and `NAnchoredForm` (a single
scrollable edit page with a sticky table of contents). `examples/App.vue` shows
both behind an `NSegmented` switch. The `useScrollSpy(containerRef, { offset })`
composable — which powers `NAnchoredForm` — is also exported for anchored pages
you wire yourself; it returns `{ active, scrollTo }` and keys off `data-spy`
attributes on the scroll container's children.

## Example

```vue
<script setup>
import { ref } from 'vue'
import { NCard, NBadge, NInput, NSegmented, NButton, NModal } from '@/lib/nergous-cit'

const open = ref(false)
const q = ref('')
const role = ref('editor')
</script>

<template>
  <NCard>
    <NBadge tone="ok" dot>Active</NBadge>
    <NInput v-model="q" icon="search" placeholder="Search" />
    <NSegmented v-model="role" :options="[
      { value: 'admin', label: 'Admin' },
      { value: 'editor', label: 'Editor' },
    ]" />
    <NButton variant="primary" icon="plus" @click="open = true">Create</NButton>

    <NModal v-model="open" title="New member">
      Form content…
      <template #footer="{ close }">
        <NButton variant="secondary" block @click="close">Cancel</NButton>
        <NButton variant="primary" block @click="close">Save</NButton>
      </template>
    </NModal>
  </NCard>
</template>
```

## Tokens

Every value lives in `styles/tokens.css` as a CSS variable (`--accent`,
`--surface`, `--radius-md`, `--sp-4`, …). Use them in your own components to stay
inside the system; they react to the active theme and density for free:

```css
.my-block {
  background: var(--surface);
  border-radius: var(--radius-lg);
  padding: var(--sp-4);
}
```

## Demos

- `examples/App.vue` — a showcase of the base components.
- `examples/AdminApp.vue` — a **full admin app** built on the shell components
  (sidebar, topbar, table, KPIs, dropzone, command palette, modal, drawer, toasts).

Copy either one into a fresh Vite project as the root `App.vue` to see the
library in action.

— MIT
