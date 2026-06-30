# Changelog

All notable changes to the project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project adheres to [semantic versioning](https://semver.org/).

## [Unreleased]

### Added

- **Media attachments for bot messages.** A bot message can now carry files from the
  media library (photos, PDFs, …) — several at once, capped by `config('bot.max_attachments')`.
  They are picked in a new reusable `MediaPicker` modal (backed by `GET /admin/media/browse`,
  gated by `media.view`) inside the bot-message drawer, stored in the `bot_message_media`
  pivot keyed by the message `code` (independent of the text override — resetting the text
  keeps the attachments), and synced from `media_ids[]`. The Python bot joins them to `media`,
  reads the file from the Laravel public disk (`MEDIA_ROOT`; in Docker the storage volume is
  mounted read-only into the bot container) and uploads it to MAX via the `maxapi` library
  (`utils/messaging.py`). A file missing on disk is skipped, never fatal.

### Changed

- **Codebase internationalized to English.** All code comments (PHPDoc, inline,
  template, and CSS comments) across the PHP backend and the JS/Vue frontend were
  translated from Russian to English, along with the project documentation
  (`README.md`, `CLAUDE.md`, `docs/`, `modules/max-bot/README.md`, and this
  changelog). The application's user-facing UI and the `lang/ru` locale stay in
  Russian — only developer-facing comments and docs changed; no runtime behavior
  was affected.

## [1.1.0] — 2026-06-30

### Added

- **Roles in a drawer.** Creating and editing roles open in a right-hand drawer
  directly on the list page (`/admin/roles`) — just like users — rather than as separate
  pages. The permissions matrix collapses into a single column in the drawer. The
  controller's resource methods `create()`/`edit()` now redirect to `index`.
- **Activity log cleanup.** The "Activity log" page gained a "Clear log" button:
  a modal with a date picker deletes all events earlier than the selected day in a single
  bulk `DELETE`. The action is gated by a dedicated `activity-log.delete` permission and is
  accompanied by a flash message with the number of deleted records
  (`DELETE /admin/activity-log`, `admin.activity-log.clear`).
- **`npm run ds:pull`** — updating the vendored snapshot of the nergous-cit design system
  from the canonical repository [`Nergous/nergous-cit`](https://github.com/Nergous/nergous-cit).

### Changed

- **⚠️ PHP 8.4 required.** The minimum PHP version was raised from `^8.2` to `^8.4`
  (`composer.json`, `composer.lock`, `README.md`); the CI matrix was reduced to a single
  version — 8.4. Installations on PHP 8.2/8.3 are no longer supported.
- The API documentation (`docs/api/permissions-matrix.md`, `docs/api/routes.snapshot.txt`)
  reflects the `activity-log.delete` permission and the cleanup route; the resource routes
  `roles.create`/`roles.edit`/`roles.show` are marked as stub redirects to
  `index` (the forms live in the drawer).

### Fixed

- **max-bot module**: the MAX API base URL was switched to
  `https://platform-api2.max.ru`; `API_BASE_URL` was added to `.env.example`.

### Removed

- The separate pages `resources/js/admin/pages/Roles/Create.vue` and `Edit.vue`
  (replaced by the drawer).
- Runtime session files are no longer tracked by git — a
  `storage/framework/sessions/.gitignore` was added (mistakenly committed in 1.0.0).

## [1.0.0] — 2026-06-30

The first release of the Laravel admin panel template: an Inertia + Vue 3 SPA, the
nergous-cit design system, RBAC (spatie/laravel-permission), a media library with
asynchronous processing, an activity log, settings, and an optional bot module.

[1.1.0]: https://github.com/Nergous/laravel-template-admin/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/Nergous/laravel-template-admin/releases/tag/v1.0.0
