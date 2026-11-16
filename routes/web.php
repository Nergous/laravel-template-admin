<?php

use App\Http\Controllers\Admin\Access\PermissionController;
use App\Http\Controllers\Admin\Access\RoleController;
use App\Http\Controllers\Admin\Access\UserController;
use App\Http\Controllers\Admin\Access\UserTrashController;
use App\Http\Controllers\Admin\Account\ProfileController;
use App\Http\Controllers\Admin\Account\SessionPingController;
use App\Http\Controllers\Admin\Content\Media\MediaController;
use App\Http\Controllers\Admin\Content\Media\MediaFolderController;
use App\Http\Controllers\Admin\Content\Media\MediaPickerController;
use App\Http\Controllers\Admin\Content\Media\MediaUploadController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\SearchController;
use App\Http\Controllers\Admin\System\ActivityLogController;
use App\Http\Controllers\Admin\System\BackupController;
use App\Http\Controllers\Admin\System\QueueController;
use App\Http\Controllers\Admin\System\SettingsController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Site\RobotsController;
use App\Http\Controllers\Site\SitemapController;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

// Crawler files built from the SEO settings (seo.indexable, seo.sitemap).
Route::get('/robots.txt', RobotsController::class)->name('robots');
Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');

// The admin panel is the only Inertia application: its shared props (user,
// permissions, sidebar counts) are built for these routes only. A public site
// added later (Blade or a separate Inertia bundle) stays outside this group.
Route::prefix('/admin')->middleware(HandleInertiaRequests::class)->group(function () {

    Route::middleware('guest')->group(function () {
        Route::get('/login', [AuthenticatedSessionController::class, 'create'])
            ->name('login');

        Route::post('/login', [AuthenticatedSessionController::class, 'store'])
            ->middleware('throttle:login') // limit from the security.login_throttle setting
            ->name('admin.login');
    });

    // AuthenticateSession ends other sessions after a password change;
    // EnsureAccountIsActive signs out blocked users.
    Route::middleware(['auth', AuthenticateSession::class, EnsureAccountIsActive::class])->group(function () {

        Route::get('/', DashboardController::class)
            ->name('admin.dashboard');

        Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
            ->name('logout');

        // Keep-alive for the "session expires soon" dialog.
        Route::post('session/ping', SessionPingController::class)
            ->name('admin.session.ping');

        // Own account: available to every signed-in user. Actions that check the
        // current password share a small attempt budget against password guessing
        // from a hijacked session.
        Route::get('profile', [ProfileController::class, 'show'])
            ->name('admin.profile.show');
        Route::middleware('throttle:6,1,profile-password')->group(function () {
            Route::put('profile', [ProfileController::class, 'update'])
                ->name('admin.profile.update');
            Route::put('profile/password', [ProfileController::class, 'password'])
                ->name('admin.profile.password');
            Route::post('profile/sessions/logout-others', [ProfileController::class, 'logoutOthers'])
                ->name('admin.profile.sessions.logout-others');
        });
        Route::delete('profile/sessions/{key}', [ProfileController::class, 'destroySession'])
            ->name('admin.profile.sessions.destroy');

        // Global search (Cmd+K)
        Route::get('search', SearchController::class)
            ->name('admin.search');

        // User deletion
        Route::middleware('permission:users.delete')->group(function () {
            Route::get('users/trashed', [UserTrashController::class, 'index'])
                ->name('admin.users.trashed');

            Route::post('users/trashed/bulk-restore', [UserTrashController::class, 'bulkRestore'])
                ->name('admin.users.bulk-restore');

            Route::delete('users/trashed/bulk-force', [UserTrashController::class, 'bulkForceDelete'])
                ->name('admin.users.bulk-force-delete');

            // Trash actions bind the user with withTrashed(); the record-level rule is UserPolicy.
            Route::patch('users/restore/{user}', [UserTrashController::class, 'restore'])
                ->whereNumber('user')
                ->withTrashed()
                ->middleware('can:restore,user')
                ->name('admin.users.restore');

            Route::delete('users/force/{user}', [UserTrashController::class, 'forceDelete'])
                ->whereNumber('user')
                ->withTrashed()
                ->middleware('can:forceDelete,user')
                ->name('admin.users.force-delete');

            Route::delete('users/bulk', [UserController::class, 'bulkDestroy'])
                ->name('admin.users.bulk-destroy');

            // DestroyUserRequest checks UserPolicy::delete or forceDelete (force=1).
            Route::delete('users/{user}', [UserController::class, 'destroy'])
                ->whereNumber('user')
                ->name('admin.users.destroy');
        });

        // Viewing/creating/editing users; creating and editing need their own permission.
        Route::middleware('permission:users.view')->group(function () {
            // CSV or XLSX of the list with the current filters.
            Route::get('users/export', [UserController::class, 'export'])
                ->middleware('permission:users.export')
                ->name('admin.users.export');

            // Bulk block/unblock.
            Route::patch('users/bulk-status', [UserController::class, 'bulkStatus'])
                ->middleware('permission:users.edit')
                ->name('admin.users.bulk-status');

            Route::name('admin')->resource('users', UserController::class)->except('destroy')
                ->middlewareFor(['create', 'store'], 'permission:users.create')
                ->middlewareFor(['edit', 'update'], ['permission:users.edit', 'can:update,user']);
        });

        // Roles
        Route::middleware('permission:roles.view')->group(function () {
            Route::name('admin')->resource('roles', RoleController::class)->except('destroy')
                ->middlewareFor(['create', 'store'], 'permission:roles.create')
                ->middlewareFor(['edit', 'update'], ['permission:roles.edit', 'can:update,role']);
        });

        Route::middleware('permission:roles.delete')->group(function () {
            Route::name('admin')->resource('roles', RoleController::class)->only('destroy')
                ->middlewareFor('destroy', 'can:delete,role');
        });

        // Permissions. Access matrix (toggling permissions for roles)
        Route::middleware('permission:permissions.edit')->group(function () {
            Route::patch('permissions/matrix', [PermissionController::class, 'toggle'])
                ->name('admin.permissions.toggle');

            Route::patch('permissions/matrix/bulk', [PermissionController::class, 'toggleMany'])
                ->name('admin.permissions.toggle-many');
        });

        // Permissions are defined in code (seeders/migrations); the panel only shows the matrix.
        Route::middleware('permission:permissions.view')->group(function () {
            Route::get('permissions', [PermissionController::class, 'index'])
                ->name('admin.permissions.index');
        });

        // Media library: viewing under media.view, uploading under media.upload,
        // renaming under media.edit, deletion (single and bulk) under media.delete.
        Route::middleware('permission:media.view')->group(function () {
            Route::get('media/poll', [MediaUploadController::class, 'poll'])
                ->name('admin.media.poll');

            // JSON browse for reusable media picker interfaces.
            Route::get('media/browse', MediaPickerController::class)
                ->name('admin.media.browse');

            Route::get('media', [MediaController::class, 'index'])
                ->name('admin.media.index');

            // File details (dimensions, uploader) for the media library drawer.
            Route::get('media/{media}', [MediaController::class, 'show'])
                ->whereNumber('media')
                ->name('admin.media.show');
        });

        Route::middleware('permission:media.upload')->group(function () {
            Route::post('media', [MediaUploadController::class, 'store'])
                ->name('admin.media.store');
        });
        Route::middleware('permission:media.edit')->group(function () {
            // Move selected files into a folder (or out of any folder).
            Route::patch('media/bulk-folder', [MediaController::class, 'bulkFolder'])
                ->name('admin.media.bulk-folder');

            // Create, rename or delete a folder (deleting moves its files one level up).
            Route::post('media/folders', [MediaFolderController::class, 'store'])
                ->name('admin.media.folders.store');
            Route::patch('media/folders', [MediaFolderController::class, 'rename'])
                ->name('admin.media.folders.rename');
            Route::delete('media/folders', [MediaFolderController::class, 'clear'])
                ->name('admin.media.folders.clear');

            Route::patch('media/{media}', [MediaController::class, 'update'])
                ->whereNumber('media')
                ->name('admin.media.update');

            // Replace the file behind an existing record (processed in the queue).
            Route::post('media/{media}/replace', [MediaUploadController::class, 'replace'])
                ->whereNumber('media')
                ->name('admin.media.replace');

            // Crop an image in place (a new file under the same record).
            Route::post('media/{media}/crop', [MediaController::class, 'crop'])
                ->whereNumber('media')
                ->name('admin.media.crop');
        });
        Route::middleware('permission:media.delete')->group(function () {
            Route::delete('media/bulk', [MediaController::class, 'bulkDestroy'])
                ->name('admin.media.bulk-destroy');

            Route::delete('media/{media}', [MediaController::class, 'destroy'])
                ->whereNumber('media')
                ->name('admin.media.destroy');
        });

        // Activity log
        Route::middleware('permission:activity-log.view')->group(function () {
            Route::get('activity-log', [ActivityLogController::class, 'index'])
                ->name('admin.activity-log.index');

            Route::get('activity-log/export', [ActivityLogController::class, 'export'])
                ->name('admin.activity-log.export');
        });

        // Clearing the log up to a chosen date — under a separate permission.
        Route::middleware('permission:activity-log.delete')->group(function () {
            Route::delete('activity-log', [ActivityLogController::class, 'clear'])
                ->name('admin.activity-log.clear');
        });

        // Settings
        Route::middleware('permission:settings.view')->group(function () {
            Route::get('settings', [SettingsController::class, 'index'])
                ->name('admin.settings.index');
        });

        Route::middleware('permission:settings.edit')->group(function () {
            Route::put('settings', [SettingsController::class, 'update'])
                ->name('admin.settings.update');
        });

        // Database backups (dumps of app:db-backup in storage/app/backups). A dump holds
        // every account's password hash, so downloading has its own permission.
        Route::middleware('permission:backups.view')->group(function () {
            Route::get('backups', [BackupController::class, 'index'])
                ->name('admin.backups.index');
        });

        Route::middleware('permission:backups.download')->group(function () {
            Route::get('backups/{file}', [BackupController::class, 'download'])
                ->where('file', 'db-[A-Za-z0-9_.-]+')
                ->name('admin.backups.download');
        });

        Route::middleware('permission:backups.create')->group(function () {
            Route::post('backups', [BackupController::class, 'store'])
                ->name('admin.backups.store');
        });

        Route::middleware('permission:backups.delete')->group(function () {
            Route::delete('backups/{file}', [BackupController::class, 'destroy'])
                ->where('file', 'db-[A-Za-z0-9_.-]+')
                ->name('admin.backups.destroy');
        });

        // Queue: pending jobs overview and failed jobs (retry/delete).
        Route::middleware('permission:queue.view')->group(function () {
            Route::get('queue', [QueueController::class, 'index'])
                ->name('admin.queue.index');
        });

        Route::middleware('permission:queue.manage')->group(function () {
            Route::post('queue/failed/retry-all', [QueueController::class, 'retryAll'])
                ->name('admin.queue.retry-all');
            Route::post('queue/failed/{uuid}/retry', [QueueController::class, 'retry'])
                ->whereUuid('uuid')
                ->name('admin.queue.retry');
            Route::delete('queue/failed/{uuid}', [QueueController::class, 'destroy'])
                ->whereUuid('uuid')
                ->name('admin.queue.destroy');
            Route::delete('queue/failed', [QueueController::class, 'flush'])
                ->name('admin.queue.flush');
        });

    });
});
