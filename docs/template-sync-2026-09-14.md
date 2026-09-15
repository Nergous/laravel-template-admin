# Template synchronization — 2026-09-14

Follow-up: the standalone design system and the template snapshot were subsequently
synchronized; see [design-system-sync-2026-09-14.md](design-system-sync-2026-09-14.md).
The report below records the initial jur-bot-max import. Its DS-specific remaining
differences were resolved in the follow-up without changing jur-bot-max.

Source: `../jur-bot-max`, HEAD `dcb11b8`, including the existing uncommitted production migration flag change.
Destination: `laravel-template-admin`, HEAD `614a72c`.

## Scope and result

Compared the union of tracked files and the existing working-tree changes, normalizing
CRLF/LF differences. Ported reusable admin, design-system, Docker and bot improvements.
The destination remains an admin template with its optional generic bot module.
No legal-aid schema, citizen records, deployment secrets or application database contents
were copied. Dependency manifests and npm/Composer locks already matched.

Updated or added **65 files** in this sync, including this report. Four pre-existing
destination changes were preserved: `AGENTS.md`, the sanitizer, the bot-message editor
and `tests/Unit/BotMessageSanitizerTest.php`. No commit was created. The source
working tree was not changed.

## Imported behavior

- Media display-name editing: permission `media.edit`, validated PATCH route, modal,
  and old/new audit values. Physical filenames and attachment URLs stay intact.
- One password policy for admin creation and user edits: 15+ characters with mixed
  case, numbers and symbols. The UI generates/copies 20-character passwords.
- Creating a user after editing one clears the prior form defaults.
- Bot-message edits log creation/update with field differences and translated subjects.
  Audit records survive deletion of their acting user.
- Cmd+K searches bot-message labels, codes and text under the enabled-module and view-permission checks.
- Dashboard hides the activity panel without permission; its KPI layout adapts to the card count.
- Select long-label/click fixes, searchable select export, table row-class callback,
  single-line toast alignment and neutral settings placeholders.
- Russian validation messages are available at Laravel's active `lang/ru` path.
- Caddy uses the built-in PHP fallback and the FrankenPHP image is constrained to major 1.
  Production migrations are opt-in; development retains explicit automatic initialization.
- The bot's Docker context includes its logging package. Configuration handles its shorter
  Docker path and an empty DB password. Optional webhook delivery, keyboard attachments,
  personalized text and edit-in-place messaging are available without the legal intake flow.

## Adaptation and verification

The old bot fixtures read live .env files and truncated MySQL tables. They now use a
fresh SQLite `:memory:` database per test through a test-only DB-API adapter, mocked
MAX methods and a socket guard. Windows asyncio's internal socket pair is supported.
No production Python database adapter was changed. SQLite tests do not prove
MySQL-specific isolation or server behavior.

PHPUnit forces the test environment and SQLite memory connection, clears DB_URL,
bypasses the production config cache, and rejects an unsafe database before
RefreshDatabase runs. Unfaked Laravel HTTP requests are blocked.

Completed verification:

- PHPUnit: **181 tests, 663 assertions passed**, PHP 8.4.25; SQLite `:memory:`.
- Python: **34 tests passed**, Python 3.12.14, including repository attachments,
  mocked handlers, webhook configuration/listener wiring, media-path and messaging regressions.
- `npm run build`: passed.
- `npm run format:check`: passed; changed PHP files passed Pint.
- Browser checks with synthetic Inertia props and mocked writes: password generation,
  edit-to-create form reset, 390px layout overflow, media rename, permission visibility
  and hidden dashboard activity. No browser page errors. Shared DS imports were retained.
- `git diff --check` and shell syntax check of `docker/entrypoint.sh`: passed.
- Route snapshot regenerated with an isolated testing environment and bot enabled.

PHP and Python runtimes, npm cache and browser verification fixtures/screenshots live
under ignored `.tmp/`. The production build is under ignored `public/build/`.
These verification files are excluded from the Docker build context.
Docker images/services were not started; actual deployment and MAX webhook delivery
were not exercised. No application migrations or seeders were run against existing data.

## Deployment notes

For first production boot or an intentional release migration, set
`RUN_MIGRATIONS=true`; return it to false for ordinary rebuilds. This also seeds base
RBAC and resets the built-in admin/operator grants, so review existing custom grants
before doing so. Existing installations need the new `media.edit` permission assigned
before rename actions appear; this sync deliberately made no data changes.

Webhook mode requires an explicit HTTPS proxy/port mapping; the default Compose
configuration remains polling. DS changes are local snapshot changes: verify their
presence upstream before running the overwrite-style `ds:pull` command.

## Files changed or added by this sync

- `.dockerignore`
- `.env.example`
- `.github/workflows/ci.yml`
- `.gitignore`
- `Dockerfile`
- `README.md`
- `app/Console/Commands/CreateAdmin.php`
- `app/Http/Controllers/Admin/AdminBotMessageController.php`
- `app/Http/Controllers/Admin/AdminMediaController.php`
- `app/Http/Controllers/Admin/AdminSearchController.php`
- `app/Http/Requests/RenameMediaRequest.php`
- `app/Http/Requests/UserRequest.php`
- `app/Models/ActivityLog.php`
- `app/Providers/AppServiceProvider.php`
- `compose.dev.yaml`
- `config/audit.php`
- `database/seeders/RolePermissionSeeder.php`
- `docker-compose.yml`
- `docker/Caddyfile`
- `docker/entrypoint.sh`
- `docs/README.md`
- `docs/api/json-endpoints.md`
- `docs/api/permissions-matrix.md`
- `docs/api/response-conventions.md`
- `docs/api/routes.snapshot.txt`
- `lang/ru/activity.php`
- `lang/ru/validation.php`
- `modules/max-bot/.dockerignore`
- `modules/max-bot/.env.example`
- `modules/max-bot/Dockerfile`
- `modules/max-bot/README.md`
- `modules/max-bot/config/config.py`
- `modules/max-bot/conftest.py`
- `modules/max-bot/main.py`
- `modules/max-bot/pytest.ini`
- `modules/max-bot/tests/test_config.py`
- `modules/max-bot/tests/test_listener.py`
- `modules/max-bot/utils/messaging.py`
- `modules/max-bot/utils/test_messaging.py`
- `phpunit.xml`
- `resources/js/admin/pages/Dashboard.vue`
- `resources/js/admin/pages/Media/Index.vue`
- `resources/js/admin/pages/Settings/Index.vue`
- `resources/js/admin/pages/Users/Index.vue`
- `resources/js/admin/pages/Users/Partials/Form.vue`
- `resources/js/lib/nergous-cit/.gitignore`
- `resources/js/lib/nergous-cit/CHANGELOG.md`
- `resources/js/lib/nergous-cit/LICENSE`
- `resources/js/lib/nergous-cit/README.md`
- `resources/js/lib/nergous-cit/components/data-display/NDataTable.vue`
- `resources/js/lib/nergous-cit/components/feedback/NToaster.vue`
- `resources/js/lib/nergous-cit/components/forms/NSelect.vue`
- `resources/js/lib/nergous-cit/components/forms/NSelectWithSearch.vue`
- `resources/js/lib/nergous-cit/index.js`
- `routes/web.php`
- `tests/Feature/AdminSearchTest.php`
- `tests/Feature/BotMessageTest.php`
- `tests/Feature/CreateAdminCommandTest.php`
- `tests/Feature/MediaTest.php`
- `tests/Feature/PasswordPolicyTest.php`
- `tests/Feature/RbacTest.php`
- `tests/Feature/SoftDeleteAndAuditTest.php`
- `tests/Feature/UserManagementTest.php`
- `tests/TestCase.php`
- `docs/template-sync-2026-09-14.md`

## Source-only files intentionally not imported

These implement the legal intake, appeal processing, reference data and their tests.
The source's message renderer is only used by its appeal/outbox flow; its status
multi-filter is an appeal-page component. `bun.lock` is omitted because the template
uses the already matching `package-lock.json`.

- `app/Enums/AppealStatus.php`
- `app/Http/Controllers/Admin/AdminAppealController.php`
- `app/Http/Controllers/Admin/AdminCategoryController.php`
- `app/Http/Controllers/Admin/AdminQuestionCategoryController.php`
- `app/Http/Controllers/Admin/AdminUserCategoryController.php`
- `app/Http/Requests/AppealUpdateRequest.php`
- `app/Http/Requests/CategoryRequest.php`
- `app/Http/Requests/QuestionCategoryRequest.php`
- `app/Http/Requests/UserCategoryRequest.php`
- `app/Http/Sorts/AppealSort.php`
- `app/Http/Sorts/CategorySort.php`
- `app/Models/Appeal.php`
- `app/Models/BotOutbox.php`
- `app/Models/QuestionCategory.php`
- `app/Models/UserCategory.php`
- `app/Services/AppealExporter.php`
- `app/Services/AppealService.php`
- `app/Support/BotMessageText.php`
- `bun.lock`
- `database/migrations/bot/2026_07_01_000001_create_user_categories_table.php`
- `database/migrations/bot/2026_07_01_000002_create_question_categories_table.php`
- `database/migrations/bot/2026_07_01_000003_create_appeals_table.php`
- `database/migrations/bot/2026_07_01_000004_create_bot_outbox_table.php`
- `database/migrations/bot/2026_07_09_000001_remove_category_name_length_limits.php`
- `database/seeders/BotMessagesSeeder.php`
- `database/seeders/CategorySeeder.php`
- `database/seeders/QuestionSeeder.php`
- `modules/max-bot/dialog/__init__.py`
- `modules/max-bot/dialog/callbacks.py`
- `modules/max-bot/dialog/contact.py`
- `modules/max-bot/dialog/keyboards.py`
- `modules/max-bot/dialog/states.py`
- `modules/max-bot/handlers/dialog.py`
- `modules/max-bot/handlers/feedback.py`
- `modules/max-bot/repositories/appeals.py`
- `modules/max-bot/repositories/categories.py`
- `modules/max-bot/repositories/outbox.py`
- `modules/max-bot/tests/test_dialog_contact.py`
- `modules/max-bot/tests/test_keyboards.py`
- `modules/max-bot/workers/__init__.py`
- `modules/max-bot/workers/outbox.py`
- `resources/js/admin/components/StatusMultiFilter.vue`
- `resources/js/admin/pages/Appeals/Index.vue`
- `resources/js/admin/pages/Appeals/Partials/Form.vue`
- `resources/js/admin/pages/Categories/Index.vue`
- `tests/Feature/AppealAutoReplyTest.php`
- `tests/Feature/AppealListTest.php`
- `tests/Feature/CategoryLengthTest.php`
- `tests/Unit/BotMessageTextTest.php`

## Shared files with intentional remaining differences

| File | Decision |
| --- | --- |
| `.env.example` | Document opt-in production migrations; retain self-contained template defaults. |
| `.gitignore` | Ignore runtime output and local verification files, retaining directory placeholders. |
| `app/Http/Controllers/Admin/AdminDashboardController.php` | Retain generic template behavior; source changes require legal appeal/category flows. |
| `app/Http/Controllers/Admin/AdminSearchController.php` | Add message search; omit appeal/category search. |
| `app/Http/Middleware/HandleInertiaRequests.php` | Retain generic template behavior; source changes require legal appeal/category flows. |
| `compose.dev.yaml` | Explicitly retain automatic initialization for the separate development stack. |
| `config/app.php` | Retain UTC; Europe/Moscow is the source deployment's choice. |
| `config/audit.php` | Add BotMessage label only; omit legal models. |
| `database/seeders/DatabaseSeeder.php` | Retain generic template behavior; source changes require legal appeal/category flows. |
| `database/seeders/RolePermissionSeeder.php` | Add media.edit; omit appeal/category permissions. |
| `docker-compose.yml` | Keep the template DB/Redis, network and healthchecks; port the opt-in migration flag. |
| `docker/entrypoint.sh` | Port opt-in migration default; omit legal category/question/message seeders. |
| `docs/api/json-endpoints.md` | Document template behavior and imported features, not the legal-aid product. |
| `docs/api/permissions-matrix.md` | Document template behavior and imported features, not the legal-aid product. |
| `docs/api/response-conventions.md` | Document template behavior and imported features, not the legal-aid product. |
| `docs/api/routes.snapshot.txt` | Document template behavior and imported features, not the legal-aid product. |
| `docs/README.md` | Document template behavior and imported features, not the legal-aid product. |
| `lang/ru/activity.php` | Add BotMessage translation only. |
| `modules/max-bot/config/config.py` | Port media-path safety, optional password and webhook parsing; defer annotations for Python 3.12 verification. |
| `modules/max-bot/conftest.py` | Use fresh SQLite memory DB and mocked API, not either project's MySQL fixtures. |
| `modules/max-bot/handlers/__init__.py` | Retain generic template behavior; source changes require legal appeal/category flows. |
| `modules/max-bot/handlers/bot_started.py` | Retain generic template behavior; source changes require legal appeal/category flows. |
| `modules/max-bot/handlers/test_handlers.py` | Retain generic template behavior; source changes require legal appeal/category flows. |
| `modules/max-bot/main.py` | Port webhook listener/lifecycle and correct local env fallback; omit outbox worker. |
| `modules/max-bot/messages.json` | Retain generic template behavior; source changes require legal appeal/category flows. |
| `modules/max-bot/pytest.ini` | Collect config/handler/helper tests too; importlib mode supports repeated test filenames. |
| `modules/max-bot/README.md` | Document template behavior and imported features, not the legal-aid product. |
| `modules/max-bot/repositories/messages.py` | Retain generic template behavior; source changes require legal appeal/category flows. |
| `modules/max-bot/tests/test_config.py` | Use isolated fixture variables and add Docker path regression coverage. |
| `modules/max-bot/utils/messaging.py` | Keep generic behavior; remove references to the legal workflow from comments. |
| `modules/max-bot/utils/test_messaging.py` | Use neutral message codes and verify override/keyboard behavior. |
| `phpunit.xml` | Force test values, clear DB_URL and avoid the application's config cache. |
| `README.md` | Document template behavior and imported features, not the legal-aid product. |
| `resources/js/admin/layouts/AdminLayout.vue` | Retain generic template behavior; source changes require legal appeal/category flows. |
| `resources/js/admin/pages/Dashboard.vue` | Port activity permission visibility and responsive KPI grid; omit appeal metrics. |
| `resources/js/admin/pages/Settings/Index.vue` | Port neutral placeholders and correct the source's awkward OG hint. |
| `resources/js/admin/pages/Users/Partials/Form.vue` | Port generator/policy hints; keep code comments in English. |
| `resources/js/lib/nergous-cit/components/feedback/NToaster.vue` | Same behavior as source, formatted with template Prettier. |
| `resources/js/lib/nergous-cit/components/forms/NSelectWithSearch.vue` | Port searchable select with English default labels and template formatting. |
| `resources/js/lib/nergous-cit/README.md` | Document template behavior and imported features, not the legal-aid product. |
| `routes/web.php` | Add media rename route only; omit legal entities. |
| `tests/Feature/AdminSearchTest.php` | Cover template users and messages only, including permission and disabled-module cases. |
| `tests/Feature/BotMessageTest.php` | Port audit regression test and apply repository Pint rules. |
| `tests/Feature/DashboardTest.php` | Retain generic template behavior; source changes require legal appeal/category flows. |
| `tests/TestCase.php` | Reject non-memory/non-SQLite databases before RefreshDatabase; block unfaked HTTP. |

## Destination-only files retained

- `.github/dependabot.yml`
- `.github/workflows/ci.yml`
- `public/favicon.ico`
- `resources/lang/ru/validation.php`

The CI workflow was retained and adapted to run offline SQLite bot tests without a
MariaDB service. Existing tracked compiled views and other runtime storage were
left untouched; they are not reusable source changes. Local .env files, vendor
directories, virtual environments, build output and agent metadata were excluded
from source synchronization.
