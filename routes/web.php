<?php

use App\Http\Controllers\Admin\AdminActivityLogController;
use App\Http\Controllers\Admin\AdminBackupController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminMediaController;
use App\Http\Controllers\Admin\AdminPermissionController;
use App\Http\Controllers\Admin\AdminRoleController;
use App\Http\Controllers\Admin\AdminSearchController;
use App\Http\Controllers\Admin\AdminSettingsController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\RequirePasswordChange;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

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

        // Global search (Cmd+K)
        Route::get('search', [AdminSearchController::class, 'index'])
            ->name('admin.search');

        // Notifications — the latest actions of other users (activity log feed for the bell).
        Route::middleware('permission:activity-log.view')->group(function () {
            Route::get('notifications/recent', [AdminActivityLogController::class, 'recent'])
                ->name('admin.notifications.recent');
            Route::post('notifications/seen', [AdminActivityLogController::class, 'markSeen'])
                ->name('admin.notifications.seen');
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
                ->name('admin.users.restore');

            Route::delete('users/force/{id}', [AdminUserController::class, 'forceDelete'])
                ->name('admin.users.force-delete');

            Route::delete('users/bulk', [AdminUserController::class, 'bulkDestroy'])
                ->name('admin.users.bulk-destroy');

            Route::delete('users/{user}', [AdminUserController::class, 'destroy'])
                ->name('admin.users.destroy');
        });

        // Viewing/creating/editing users.
        Route::middleware('permission:users.view')->group(function () {
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
            Route::patch('media/{media}', [AdminMediaController::class, 'update'])
                ->name('admin.media.update');

            // Replace the file behind an existing record (processed in the queue).
            Route::post('media/{media}/replace', [AdminMediaController::class, 'replace'])
                ->name('admin.media.replace');
        });
        Route::middleware('permission:media.delete')->group(function () {
            Route::delete('media/bulk', [AdminMediaController::class, 'bulkDestroy'])
                ->name('admin.media.bulk-destroy');

            Route::delete('media/{media}', [AdminMediaController::class, 'destroy'])
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

        // Database backups (dumps of app:db-backup in storage/app/backups).
        Route::middleware('permission:backups.view')->group(function () {
            Route::get('backups', [AdminBackupController::class, 'index'])
                ->name('admin.backups.index');
            Route::get('backups/{file}', [AdminBackupController::class, 'download'])
                ->where('file', 'db-[A-Za-z0-9_.-]+')
                ->name('admin.backups.download');
        });

        Route::middleware('permission:backups.create')->group(function () {
            Route::post('backups', [AdminBackupController::class, 'store'])
                ->name('admin.backups.store');
        });

    });
});
