# Design-system synchronization — 2026-09-14

## Scope

Synchronized the standalone `C:/Files/01_work/active/nergous-cit` repository
(HEAD `c4d7c96`, package 1.0.1) and the vendored snapshot at
`resources/js/lib/nergous-cit`. The user explicitly excluded `jur-bot-max`
from changes. Its snapshot was read only for comparison.

All **69 distributable/snapshot files are byte-identical** between the standalone
library and the Laravel template after this sync. Repository-only `AGENTS.md`,
`ROADMAP.md`, `docs/`, `.agents/`, `.superpowers/` and Git metadata are not
copied into the snapshot. Existing untracked repository notes and roadmap are retained.

## Merged differences

- Kept the standalone library's modal overflow fix and bubbling Escape handling:
  an open select closes before its containing modal, and nested overlays close
  one at a time.
- Carried the snapshot's select option click prevention back to the standalone
  library, avoiding re-activation by a wrapping label.
- Carried English search/empty defaults for `NSelectWithSearch` back to the library;
  existing props still allow consumer localization.
- Updated the template snapshot to package metadata 1.0.1, Vue peer requirement
  `^3.5.0`, 41 components, current examples and release history.
- Matched formatting and consolidated the public documentation. No public exports
  were removed and no runtime dependency was added.
- Kept the package version unchanged; new fixes are documented under Unreleased.
  No tag, commit, package publication or remote push was performed.

## Changed standalone files (6)

- `CHANGELOG.md`
- `components/feedback/NToaster.vue`
- `components/forms/NSelect.vue`
- `components/forms/NSelectWithSearch.vue`
- `examples/App.vue`
- `README.md`

## Changed snapshot files (7)

- `resources/js/lib/nergous-cit/CHANGELOG.md`
- `resources/js/lib/nergous-cit/components/overlays/NModal.vue`
- `resources/js/lib/nergous-cit/composables/useDismiss.js`
- `resources/js/lib/nergous-cit/examples/AdminApp.vue`
- `resources/js/lib/nergous-cit/examples/App.vue`
- `resources/js/lib/nergous-cit/package.json`
- `resources/js/lib/nergous-cit/README.md`

## Verification

- Full snapshot comparison: 69/69 files byte-identical, including fonts and package metadata.
- Vue compiler: 43 SFCs (41 components and 2 examples) parsed and compiled successfully.
- Public barrel import targets and JavaScript syntax checked.
- `npm pack --dry-run --ignore-scripts`: package 1.0.1, 65 files; no archive or publication.
- `npm run build` and `npm run format:check` in the template: passed.
- Browser fixture: 12 combinations (2 selects × 2 themes × 3 densities), label-contained
  option clicks, filtering, English empty state, keyboard selection, disabled-option skipping,
  Escape ordering, modal focus restoration, nested drawer/modal, popup visibility beyond
  the modal boundary, 390px layout overflow, rowClass and single-line toast alignment.
- No browser page errors. Screenshots reviewed from the disposable host.
- `git diff --check`: passed for the standalone repository.

The browser host uses synthetic local state, blocks requests outside its local Vite
server and never loads the Laravel application. Verification scripts and screenshots
are under ignored `.tmp/` in the template. No database or real API was accessed.
The prior Laravel/Python changes and tests are outside this follow-up's edit scope.
Future roadmap work was not implemented.

The standalone changes are local only. Before `ds:pull`, publish the intended
standalone Git changes through the normal workflow; pulling the remote now could
replace local fixes with its previous snapshot.
