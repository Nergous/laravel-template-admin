# Changelog

All notable changes to the nergous-cit design system are documented here.
The project loosely follows [Semantic Versioning](https://semver.org/).

## Unreleased

### Fixed

- Select option clicks no longer re-activate a wrapping label and reopen the popup.
- `NSelectWithSearch` uses English search/empty labels by default; consumers can
  supply localized text through its existing props.
- The standalone library and the Laravel template snapshot share the modal/select
  fixes, Vue 3.5 requirements, examples and documentation.

## v1.0.1 — 2026-07-18

### Added

- `NSelectWithSearch`, a searchable single-select listbox with client-side filtering.
- `rowClass` support in `NDataTable` for per-row classes.

### Fixed

- `NSelect` wraps long option labels and keeps its dropdown within the control width.
- `NToaster` vertically centers title-only and message-only single-line toasts.
- `NSelect` listboxes remain visible inside `NModal`, and Escape closes the listbox before the dialog.

## v1.0.0 — 2026-06-30

Initial release as a standalone repository (snapshot-distribution model).

First public cut of the nergous-cit Vue 3 design system, extracted from the
`laravel-template-admin` project:

- Themeable & density-aware design tokens (`styles/tokens.css`).
- 40 components across primitives, forms, data-display, feedback, overlays and
  navigation (including the admin-shell set: `NSidebar`, `NTopbar`, `NDataTable`,
  `NCommandPalette`, `NWizard`, `NAnchoredForm`).
- Composables: `useTheme`, `useToast`, `useScrollSpy`.
- Locale-agnostic formatting helpers (`createFormat`, `toDate`).
- Bundled Manrope + JetBrains Mono subsets.
