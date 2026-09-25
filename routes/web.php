<?php

use App\Http\Controllers\Admin\AdminActivityLogController;
use App\Http\Controllers\Admin\AdminBackupController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminMediaController;
use App\Http\Controllers\Admin\AdminPermissionController;
use App\Http\Controllers\Admin\AdminQueueController;
use App\Http\Controllers\Admin\AdminRoleController;
use App\Http\Controllers\Admin\AdminSearchController;
use App\Http\Controllers\Admin\AdminSettingsController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Admin\SessionController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\SeoController;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\RequirePasswordChange;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

// Crawler files built from the SEO settings (seo.indexable, seo.sitemap).
Route::get('/robots.txt', [SeoController::class, 'robots'])->name('robots');
Route::get('/sitemap.xml', [SeoController::class, 'sitemap'])->name('sitemap');

Route::prefix('/admin')->group(function () {

    Route::middleware('guest')->group(function () {
        Route::get('/login', [LoginController::class, 'show'])
            ->name('login');

        Route::post('/login', [LoginController::class, 'login'])
            ->middleware('throttle:login') // limit from the security.login_throttle setting
            ->name('admin.login');
    });

    // AuthenticateSession ends other sessions after a password change;
    // EnsureAccountIsActive signs out blocked users; RequirePasswordChange keeps
    // users with a pending forced change on the profile page.
    Route::middleware(['auth', AuthenticateSession::class, EnsureAccountIsActive::class, RequirePasswordChange::class])->group(function () {

        Route::get('/', [AdminDashboardController::class, 'index'])
            ->name('admin.dashboard');

        Route::post('/logout', [LoginController::class, 'logout'])
            ->name('logout');

        // Keep-alive for the "session expires soon" dialog.
        Route::post('session/ping', [SessionController::class, 'ping'])
            ->name('admin.session.ping');

        // Own account: available to every signed-in user.
        Route::get('profile', [ProfileController::class, 'show'])
            ->name('admin.profile.show');
        Route::put('profile', [ProfileController::class, 'update'])
            ->name('admin.profile.update');
        Route::put('profile/password', [ProfileController::class, 'password'])
            ->name('admin.profile.password');
        Route::post('profile/sessions/logout-others', [ProfileController::class, 'logoutOthers'])
            ->name('admin.profile.sessions.logout-others');
        Route::delete('profile/sessions/{key}', [ProfileController::class, 'destroySession'])
            ->name('admin.profile.sessions.destroy');

        // Leaving the "sign in as" mode: available to whoever is being impersonated;
        // the controller checks the impersonator stored in the session.
        Route::post('impersonation/stop', [ImpersonationController::class, 'stop'])
            ->name('admin.impersonation.stop');

        // Starting it: users.impersonate; the target rules live in App\Support\Impersonation.
        Route::post('users/{user}/impersonate', [ImpersonationController::class, 'start'])
            ->whereNumber('user')
            ->middleware('permission:users.impersonate')
            ->name('admin.users.impersonate');

        // Global search (Cmd+K)
        Route::get('search', [AdminSearchController::class, 'index'])
            ->name('admin.search');

        // Notifications — the latest actions of other users (activity log feed for the bell).
        Route::middleware('permission:activity-log.view')->group(function () {
            Route::get('notifications/recent', [AdminActivityLogController::class, 'recent'])
                ->name('admin.notifications.recent');
            Route::get('notifications/count', [AdminActivityLogController::class, 'count'])
                ->name('admin.notifications.count');
            Route::post('notifications/seen', [AdminActivityLogController::class, 'markSeen'])
                ->name('admin.notifications.seen');
            Route::put('notifications/preferences', [AdminActivityLogController::class, 'preferences'])
                ->name('admin.notifications.preferences');
        });

        // User deletion
        Route::middleware('permission:users.delete')->group(function () {
            Route::get('users/trashed', [AdminUserController::class, 'trashed'])
                ->name('admin.users.trashed');

            Route::post('users/trashed/bulk-restore', [AdminUserController::class, 'bulkRestore'])
                ->name('admin.users.bulk-restore');

            Route::delete('users/trashed/bulk-force', [AdminUserController::class, 'bulkForceDelete'])
                ->name('admin.users.bulk-force-delete');

            Route::patch('users/restore/{id}', [AdminUserController::class, 'restore'])
                ->whereNumber('id')
                ->name('admin.users.restore');

            Route::delete('users/force/{id}', [AdminUserController::class, 'forceDelete'])
                ->whereNumber('id')
                ->name('admin.users.force-delete');

            Route::delete('users/bulk', [AdminUserController::class, 'bulkDestroy'])
                ->name('admin.users.bulk-destroy');

            Route::delete('users/{user}', [AdminUserController::class, 'destroy'])
                ->whereNumber('user')
                ->name('admin.users.destroy');
        });

        // Viewing/creating/editing users.
        Route::middleware('permission:users.view')->group(function () {
            // CSV of the list with the current filters.
            Route::get('users/export', [AdminUserController::class, 'export'])
                ->middleware('permission:users.export')
                ->name('admin.users.export');

            // Bulk block/unblock; gated by users.edit in BulkUserStatusRequest.
            Route::patch('users/bulk-status', [AdminUserController::class, 'bulkStatus'])
                ->name('admin.users.bulk-status');

            Route::name('admin')->resource('users', AdminUserController::class)->except('destroy');
        });

        // Roles
        Route::middleware('permission:roles.view')->group(function () {
            Route::name('admin')->resource('roles', AdminRoleController::class)->except('destroy');
        });

        Route::middleware('permission:roles.delete')->group(function () {
            Route::name('admin')->resource('roles', AdminRoleController::class)->only('destroy');
        });

        // Permissions. Access matrix (toggling permissions for roles)
        Route::middleware('permission:permissions.edit')->group(function () {
            Route::patch('permissions/matrix', [AdminPermissionController::class, 'sync'])
                ->name('admin.permissions.sync');

            Route::patch('permissions/matrix/bulk', [AdminPermissionController::class, 'syncMany'])
                ->name('admin.permissions.sync-many');
        });

        // Permissions
        Route::middleware('permission:permissions.view')->group(function () {
            Route::name('admin')->resource('permissions', AdminPermissionController::class)->except('destroy');
        });
        Route::middleware('permission:permissions.delete')->group(function () {
            Route::name('admin')->resource('permissions', AdminPermissionController::class)->only('destroy');
        });

        // Media library: viewing under media.view, uploading under media.upload,
        // renaming under media.edit, deletion (single and bulk) under media.delete.
        Route::middleware('permission:media.view')->group(function () {
            Route::get('media/poll', [AdminMediaController::class, 'poll'])
                ->name('admin.media.poll');

            // JSON browse for reusable media picker interfaces.
            Route::get('media/browse', [AdminMediaController::class, 'browse'])
                ->name('admin.media.browse');

            Route::get('media', [AdminMediaController::class, 'index'])
                ->name('admin.media.index');

            // File details (dimensions, uploader) for the media library drawer.
            Route::get('media/{media}', [AdminMediaController::class, 'show'])
                ->whereNumber('media')
                ->name('admin.media.show');
        });

        Route::middleware('permission:media.upload')->group(function () {
            Route::post('media', [AdminMediaController::class, 'store'])
                ->name('admin.media.store');
        });
        Route::middleware('permission:media.edit')->group(function () {
            // Move selected files into a folder (or out of any folder).
            Route::patch('media/bulk-folder', [AdminMediaController::class, 'bulkFolder'])
                ->name('admin.media.bulk-folder');

            // Rename a folder, or dissolve it (its files move out of any folder).
            Route::patch('media/folders', [AdminMediaController::class, 'renameFolder'])
                ->name('admin.media.folders.rename');
            Route::delete('media/folders', [AdminMediaController::class, 'clearFolder'])
                ->name('admin.media.folders.clear');

            Route::patch('media/{media}', [AdminMediaController::class, 'update'])
                ->whereNumber('media')
                ->name('admin.media.update');

            // Replace the file behind an existing record (processed in the queue).
            Route::post('media/{media}/replace', [AdminMediaController::class, 'replace'])
                ->whereNumber('media')
                ->name('admin.media.replace');

            // Crop an image in place (a new file under the same record).
            Route::post('media/{media}/crop', [AdminMediaController::class, 'crop'])
                ->whereNumber('media')
                ->name('admin.media.crop');
        });
        Route::middleware('permission:media.delete')->group(function () {
            Route::delete('media/bulk', [AdminMediaController::class, 'bulkDestroy'])
                ->name('admin.media.bulk-destroy');

            Route::delete('media/{media}', [AdminMediaController::class, 'destroy'])
                ->whereNumber('media')
                ->name('admin.media.destroy');
        });

        // Activity log
        Route::middleware('permission:activity-log.view')->group(function () {
            Route::get('activity-log', [AdminActivityLogController::class, 'index'])
                ->name('admin.activity-log.index');

            Route::get('activity-log/export', [AdminActivityLogController::class, 'export'])
                ->name('admin.activity-log.export');
        });

        // Clearing the log up to a chosen date — under a separate permission.
        Route::middleware('permission:activity-log.delete')->group(function () {
            Route::delete('activity-log', [AdminActivityLogController::class, 'clear'])
                ->name('admin.activity-log.clear');
        });

        // Settings
        Route::middleware('permission:settings.view')->group(function () {
            Route::get('settings', [AdminSettingsController::class, 'index'])
                ->name('admin.settings.index');
        });

        Route::middleware('permission:settings.edit')->group(function () {
            Route::put('settings', [AdminSettingsController::class, 'update'])
                ->name('admin.settings.update');
        });

        // Database backups (dumps of app:db-backup in storage/app/backups). A dump holds
        // every account's password hash, so downloading has its own permission.
        Route::middleware('permission:backups.view')->group(function () {
            Route::get('backups', [AdminBackupController::class, 'index'])
                ->name('admin.backups.index');
        });

        Route::middleware('permission:backups.download')->group(function () {
            Route::get('backups/{file}', [AdminBackupController::class, 'download'])
                ->where('file', 'db-[A-Za-z0-9_.-]+')
                ->name('admin.backups.download');
        });

        Route::middleware('permission:backups.create')->group(function () {
            Route::post('backups', [AdminBackupController::class, 'store'])
                ->name('admin.backups.store');
        });

        Route::middleware('permission:backups.delete')->group(function () {
            Route::delete('backups/{file}', [AdminBackupController::class, 'destroy'])
                ->where('file', 'db-[A-Za-z0-9_.-]+')
                ->name('admin.backups.destroy');
        });

        // Queue: pending jobs overview and failed jobs (retry/delete).
        Route::middleware('permission:queue.view')->group(function () {
            Route::get('queue', [AdminQueueController::class, 'index'])
                ->name('admin.queue.index');
        });

        Route::middleware('permission:queue.manage')->group(function () {
            Route::post('queue/failed/retry-all', [AdminQueueController::class, 'retryAll'])
                ->name('admin.queue.retry-all');
            Route::post('queue/failed/{uuid}/retry', [AdminQueueController::class, 'retry'])
                ->whereUuid('uuid')
                ->name('admin.queue.retry');
            Route::delete('queue/failed/{uuid}', [AdminQueueController::class, 'destroy'])
                ->whereUuid('uuid')
                ->name('admin.queue.destroy');
            Route::delete('queue/failed', [AdminQueueController::class, 'flush'])
                ->name('admin.queue.flush');
        });

    });
});
